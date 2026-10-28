import {
	env,
	createExecutionContext,
	waitOnExecutionContext,
	SELF,
	runInDurableObject,
} from "cloudflare:test";
import { describe, it, expect, vi } from "vitest";
import worker, { INTERVAL_MS, hasRaceNear, nextSlot, workflowsForSlot } from "../src/index";

// For now, you'll need to do something like this to get a correctly-typed
// `Request` to pass to `worker.fetch()`.
const IncomingRequest = Request<unknown, IncomingRequestCfProperties>;

// 実際に dispatch が走るケースは GitHub Actions を起動してしまうため、
// ここでは「dispatch に到達しない」経路だけを検証する。
const testEnv = { ...env, BOA_TOKEN: "test-token" };

describe("fetch handler", () => {
	it("returns 401 without X-Trigger-Secret", async () => {
		const request = new IncomingRequest("http://example.com");
		const ctx = createExecutionContext();
		const response = await worker.fetch(request, testEnv, ctx);
		await waitOnExecutionContext(ctx);

		expect(response.status).toBe(401);
		expect(await response.text()).toBe("Unauthorized");
	});

	it("returns 401 when X-Trigger-Secret does not match", async () => {
		const request = new IncomingRequest("http://example.com", {
			headers: { "X-Trigger-Secret": "wrong-token" },
		});
		const ctx = createExecutionContext();
		const response = await worker.fetch(request, testEnv, ctx);
		await waitOnExecutionContext(ctx);

		expect(response.status).toBe(401);
	});

	it("returns 401 for unauthenticated integration-style request", async () => {
		const response = await SELF.fetch("https://example.com");

		expect(response.status).toBe(401);
	});
});

describe("scheduled handler", () => {
	it("logs and skips dispatch for an unknown cron", async () => {
		const consoleError = vi.spyOn(console, "error").mockImplementation(() => {});
		const waitUntil = vi.fn();
		const ctx = createExecutionContext();

		await worker.scheduled(
			{ cron: "0 0 1 1 *", scheduledTime: Date.now(), noRetry: () => {} },
			testEnv,
			{ ...ctx, waitUntil } as unknown as ExecutionContext,
		);
		await waitOnExecutionContext(ctx);

		expect(waitUntil).not.toHaveBeenCalled();
		expect(consoleError).toHaveBeenCalledWith("Unknown cron: 0 0 1 1 *");

		consoleError.mockRestore();
	});

	it("ensures the scheduler alarm for the watchdog cron", async () => {
		const ctx = createExecutionContext();

		await worker.scheduled(
			{ cron: "*/10 * * * *", scheduledTime: Date.now(), noRetry: () => {} },
			testEnv,
			ctx,
		);
		await waitOnExecutionContext(ctx);

		const stub = env.SCHEDULER.get(env.SCHEDULER.idFromName("default"));
		const alarm = await runInDurableObject(stub, (_, state) => state.storage.getAlarm());
		expect(alarm).not.toBeNull();
	});
});

describe("scheduler", () => {
	it("returns 401 from /scheduler without X-Trigger-Secret", async () => {
		const response = await SELF.fetch("https://example.com/scheduler");

		expect(response.status).toBe(401);
	});

	it("returns the next 10-minute slot from /scheduler with X-Trigger-Secret", async () => {
		const before = Date.now();
		const request = new IncomingRequest("https://example.com/scheduler", {
			headers: { "X-Trigger-Secret": "test-token" },
		});
		const ctx = createExecutionContext();
		const response = await worker.fetch(request, testEnv, ctx);
		await waitOnExecutionContext(ctx);
		const { next } = await response.json<{ next: string }>();

		expect(response.status).toBe(200);
		expect(Date.parse(next)).toBe(nextSlot(before));
	});

	it("keeps an existing alarm instead of overriding it", async () => {
		const stub = env.SCHEDULER.get(env.SCHEDULER.idFromName("default"));
		const existing = nextSlot(Date.now()) + INTERVAL_MS * 10;
		await runInDurableObject(stub, (_, state) => state.storage.setAlarm(existing));

		expect(await stub.ensure()).toBe(existing);
	});
});

describe("slot helpers", () => {
	it("rounds up to the next 10-minute boundary", () => {
		expect(nextSlot(Date.parse("2026-10-28T08:48:00Z"))).toBe(Date.parse("2026-10-28T08:50:00Z"));
		expect(nextSlot(Date.parse("2026-10-28T08:49:59Z"))).toBe(Date.parse("2026-10-28T08:50:00Z"));
		expect(nextSlot(Date.parse("2026-10-28T08:50:00Z"))).toBe(Date.parse("2026-10-28T09:00:00Z"));
	});

	it("dispatches only sync.yml on the sync hours", () => {
		expect(workflowsForSlot(Date.parse("2026-10-28T15:00:00Z"))).toEqual(["sync.yml"]);
		expect(workflowsForSlot(Date.parse("2026-10-28T00:00:00Z"))).toEqual(["sync-upcoming.yml"]);
		expect(workflowsForSlot(Date.parse("2026-10-28T15:10:00Z"))).toEqual(["sync-upcoming.yml"]);
		expect(workflowsForSlot(Date.parse("2026-10-28T12:00:00Z"))).toEqual(["sync-upcoming.yml"]);
	});
});

describe("hasRaceNear", () => {
	const todayJson = JSON.stringify({
		programs: {
			stadiums: {
				"1": { races: { "1": { closed_at: "2026-10-28 08:35:00" }, "12": { closed_at: "2026-10-28 22:54:00" } } },
				"2": { races: { "1": { closed_at: null } } },
			},
		},
	});
	const jst = (hhmm: string, date = "2026-10-28") => Date.parse(`${date}T${hhmm}:00+09:00`);

	it("is true from 35 minutes before to 125 minutes after a closing time", () => {
		expect(hasRaceNear(todayJson, jst("08:00"))).toBe(true);
		expect(hasRaceNear(todayJson, jst("10:40"))).toBe(true);
		expect(hasRaceNear(todayJson, jst("00:59", "2026-10-29"))).toBe(true);
	});

	it("is false outside that range", () => {
		expect(hasRaceNear(todayJson, jst("10:41"))).toBe(false);
	});

	it("is false in the night gap", () => {
		expect(hasRaceNear(todayJson, jst("07:59"))).toBe(false);
		expect(hasRaceNear(todayJson, jst("01:00", "2026-10-29"))).toBe(false);
		expect(hasRaceNear(todayJson, jst("03:00", "2026-10-29"))).toBe(false);
	});

	it("is false when today.json still holds the previous day", () => {
		expect(hasRaceNear(todayJson, jst("08:35", "2026-10-29"))).toBe(false);
	});

	it("returns null when no closed_at is found", () => {
		expect(hasRaceNear("{}", jst("12:00"))).toBeNull();
	});
});
