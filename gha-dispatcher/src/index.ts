import { DurableObject } from 'cloudflare:workers';

export interface Env {
  APP_ID: string;
  APP_PRIVATE_KEY: string;
  BOA_TOKEN: string;
  SCHEDULER: DurableObjectNamespace<Scheduler>;
}

const OWNER = 'boatraceopenapi';
const REPO = 'api';
const DEFAULT_WORKFLOW_FILE = 'sync-upcoming.yml';
const DEFAULT_REF = 'gh-pages';

// dispatch の間隔。SYNC_HOURS_UTC の正時の回は sync.yml、それ以外の回は sync-upcoming.yml を起動する
export const INTERVAL_MS = 3 * 60 * 1000;
// 15:00 UTC（JST 0:00）に前日分を確定させ、当日分のファイルを作る。
// 当日分の各レースは締切 ±30 分に sync-upcoming.yml が取り直すので、日中の全体同期は持たない
// （JST 9:00 の全体同期は 1R 締切 8:35 より後で、約 17 分間 sync-upcoming.yml を待たせていた）。
// 失敗した日の再試行は sync.yml 自身の schedule トリガーに任せる。
const SYNC_HOURS_UTC = [15];

// Cron Triggers は dispatch せず、アラームが止まっていたら掛け直す見張り役にする
const WATCHDOG_CRON = '*/10 * * * *';

// 2 本のワークフローは concurrency グループを共有しており、待機中の run は後続に置き換えられる。
// 同じ回で両方を dispatch すると、待機に回った sync.yml が 3 分後の sync-upcoming.yml に取り消される
// （2026-10-27 15:00 に実際に発生）。sync.yml は当日分の直前情報も取り直すので、正時の回はこれだけにする。
export function workflowsForSlot(slotMs: number): string[] {
  const slot = new Date(slotMs);
  if (slot.getUTCMinutes() === 0 && SYNC_HOURS_UTC.includes(slot.getUTCHours())) {
    return ['sync.yml'];
  }
  return ['sync-upcoming.yml'];
}

export function nextSlot(nowMs: number): number {
  return Math.floor(nowMs / INTERVAL_MS) * INTERVAL_MS + INTERVAL_MS;
}

// sync-upcoming.yml は締切 ±30 分のレースしか更新しないので、近くにレースがない回（主に夜間）は dispatch しない。
// run の起動は dispatch の 1〜2 分後になり、Pages のキャッシュ（max-age=600）で締切時刻が遅れて届くこともあるため、
// PHP 側の ±30 分より少し広く取る。
const TODAY_JSON_URL = 'https://boatraceopenapi.github.io/api/v1/today.json';
const NEAR_RACE_MARGIN_MS = 35 * 60 * 1000;

/**
 * today.json の closed_at（JST）に、nowMs の前後 NEAR_RACE_MARGIN_MS 以内のものがあるか。
 * closed_at が 1 件も見つからなければ判断できないので null を返す。
 * 1.2 MB 程度あり JSON.parse では Free プランの CPU 上限（10 ms）に近いため、正規表現で拾う。
 */
export function hasRaceNear(todayJson: string, nowMs: number): boolean | null {
  const closings = [...todayJson.matchAll(/"closed_at":"(\d{4}-\d{2}-\d{2}) (\d{2}:\d{2}:\d{2})"/g)];
  if (closings.length === 0) {
    return null;
  }
  return closings.some(([, date, time]) => Math.abs(Date.parse(`${date}T${time}+09:00`) - nowMs) <= NEAR_RACE_MARGIN_MS);
}

// today.json が読めないときは従来どおり dispatch する（この判定のせいで同期を止めない）
async function shouldDispatchUpcoming(nowMs: number): Promise<boolean> {
  try {
    const res = await fetch(TODAY_JSON_URL, { headers: { 'User-Agent': 'cf-worker-gha-dispatcher' } });
    if (!res.ok) {
      console.warn(`today.json fetch failed: ${res.status}; dispatching anyway`);
      return true;
    }
    const near = hasRaceNear(await res.text(), nowMs);
    if (near === null) {
      console.warn('no closed_at found in today.json; dispatching anyway');
      return true;
    }
    return near;
  } catch (e) {
    console.warn('today.json fetch failed; dispatching anyway:', e);
    return true;
  }
}

/**
 * Cloudflare Cron Triggers が発火しなくなったため（2026-10-28）、
 * Durable Object のアラームで 3 分刻みの dispatch を自前で回す。
 */
export class Scheduler extends DurableObject<Env> {
  async ensure(): Promise<number> {
    const alarm = await this.ctx.storage.getAlarm();
    if (alarm !== null) {
      return alarm;
    }
    return this.schedule(nextSlot(Date.now()));
  }

  async alarm(): Promise<void> {
    const slot = (await this.ctx.storage.get<number>('slot')) ?? Math.floor(Date.now() / INTERVAL_MS) * INTERVAL_MS;

    // dispatch が失敗しても止まらないよう、先に次のアラームを予約しておく
    await this.schedule(nextSlot(Date.now()));

    for (const workflowFile of workflowsForSlot(slot)) {
      if (workflowFile === 'sync-upcoming.yml' && !(await shouldDispatchUpcoming(Date.now()))) {
        continue;
      }
      try {
        await dispatchWorkflow(this.env, workflowFile);
      } catch (e) {
        console.error(`Alarm dispatch failed (${workflowFile} @ ${new Date(slot).toISOString()}):`, e);
      }
    }
  }

  private async schedule(slotMs: number): Promise<number> {
    await this.ctx.storage.put('slot', slotMs);
    await this.ctx.storage.setAlarm(slotMs);
    return slotMs;
  }
}

function getScheduler(env: Env): DurableObjectStub<Scheduler> {
  return env.SCHEDULER.get(env.SCHEDULER.idFromName('default'));
}

export default {
  async scheduled(event: ScheduledController, env: Env, ctx: ExecutionContext) {
    if (event.cron !== WATCHDOG_CRON) {
      console.error(`Unknown cron: ${event.cron}`);
      return;
    }
    ctx.waitUntil(getScheduler(env).ensure());
  },

  async fetch(request: Request, env: Env, ctx: ExecutionContext): Promise<Response> {
    // /scheduler も認証必須にする。公開すると叩かれるたびに Durable Object の無料枠を消費し、
    // 枠が尽きるとアラームまで失敗して同期が止まる
    const authHeader = request.headers.get('X-Trigger-Secret');
    if (authHeader !== env.BOA_TOKEN) {
      return new Response('Unauthorized', { status: 401 });
    }
    const url = new URL(request.url);

    // アラームを確保して次回の予定時刻を返す。dispatch はしない
    if (url.pathname === '/scheduler') {
      const next = await getScheduler(env).ensure();
      return new Response(JSON.stringify({ next: new Date(next).toISOString() }));
    }

    const workflowFile = url.searchParams.get('workflow') ?? DEFAULT_WORKFLOW_FILE;
    const result = await dispatchWorkflow(env, workflowFile);
    return new Response(JSON.stringify(result));
  },
} satisfies ExportedHandler<Env>;

async function dispatchWorkflow(env: Env, workflowFile: string, ref: string = DEFAULT_REF) {
  const installationToken = await getInstallationToken(env);

  const url = `https://api.github.com/repos/${OWNER}/${REPO}/actions/workflows/${workflowFile}/dispatches`;

  const res = await fetch(url, {
    method: 'POST',
    headers: {
      Authorization: `Bearer ${installationToken}`,
      Accept: 'application/vnd.github+json',
      'X-GitHub-Api-Version': '2022-11-28',
      'User-Agent': 'cf-worker-gha-dispatcher',
    },
    body: JSON.stringify({ ref }),
  });

  if (res.status !== 204) {
    const body = await res.text();
    console.error(`Dispatch failed (${workflowFile}): ${res.status} ${body}`);
    throw new Error(`GitHub dispatch failed with status ${res.status}`);
  }

  return { ok: true, workflow: workflowFile, status: res.status };
}

async function getInstallationToken(env: Env): Promise<string> {
  const jwt = await createAppJwt(env.APP_ID, env.APP_PRIVATE_KEY);

  // インストール ID を解決（リポジトリ単位）
	const installationRes = await fetch(
		`https://api.github.com/repos/${OWNER}/${REPO}/installation`,
		{
			headers: {
				Authorization: `Bearer ${jwt}`,
				Accept: 'application/vnd.github+json',
				'X-GitHub-Api-Version': '2022-11-28',
				'User-Agent': 'cf-worker-gha-dispatcher',
			},
		}
	);

	if (!installationRes.ok) {
		const body = await installationRes.text();
		console.error(`Installation resolve failed: ${installationRes.status} ${body}`);
		throw new Error(`Failed to resolve installation: ${installationRes.status} ${body}`);
	}

  const installation = await installationRes.json<{ id: number }>();

  const tokenRes = await fetch(
    `https://api.github.com/app/installations/${installation.id}/access_tokens`,
    {
      method: 'POST',
      headers: {
        Authorization: `Bearer ${jwt}`,
        Accept: 'application/vnd.github+json',
        'X-GitHub-Api-Version': '2022-11-28',
        'User-Agent': 'cf-worker-gha-dispatcher',
      },
    }
  );

  if (!tokenRes.ok) {
    throw new Error(`Failed to create installation token: ${tokenRes.status}`);
  }

  const tokenData = await tokenRes.json<{ token: string }>();
  return tokenData.token;
}

async function createAppJwt(appId: string, privateKeyPem: string): Promise<string> {
  const now = Math.floor(Date.now() / 1000);
  const header = { alg: 'RS256', typ: 'JWT' };
  const payload = {
    iat: now - 60, // 時計ずれ対策で60秒巻き戻す
    exp: now + 540, // 600 - 60 = 540 に調整
    iss: appId,
  };

  const encoder = new TextEncoder();
  const base64url = (input: ArrayBuffer | string) => {
    const bytes = typeof input === 'string' ? encoder.encode(input) : new Uint8Array(input);
    let binary = '';
    bytes.forEach((b) => (binary += String.fromCharCode(b)));
    return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
  };

  const encodedHeader = base64url(JSON.stringify(header));
  const encodedPayload = base64url(JSON.stringify(payload));
  const signingInput = `${encodedHeader}.${encodedPayload}`;

  const key = await importPrivateKey(privateKeyPem);
  const signature = await crypto.subtle.sign(
    { name: 'RSASSA-PKCS1-v1_5' },
    key,
    encoder.encode(signingInput)
  );

  return `${signingInput}.${base64url(signature)}`;
}

async function importPrivateKey(pem: string): Promise<CryptoKey> {
  const pemContents = pem
    .replace('-----BEGIN RSA PRIVATE KEY-----', '')
    .replace('-----BEGIN PRIVATE KEY-----', '')
    .replace('-----END RSA PRIVATE KEY-----', '')
    .replace('-----END PRIVATE KEY-----', '')
    .replace(/\s/g, '');

  const binaryDer = Uint8Array.from(atob(pemContents), (c) => c.charCodeAt(0));

  return crypto.subtle.importKey(
    'pkcs8',
    binaryDer,
    { name: 'RSASSA-PKCS1-v1_5', hash: 'SHA-256' },
    false,
    ['sign']
  );
}
