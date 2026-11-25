<?php

declare(strict_types=1);

namespace BOA\API\Tests;

use BOA\API\Synchronizer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\BrowserKit\Response;
use ValueError;

/**
 * @author shimomo
 */
final class SynchronizerTest extends TestCase
{
    /**
     * 取得を始める前に弾くので、通信もファイルの書き込みも起きない
     *
     * @return void
     */
    #[Test]
    public function syncRejectsIntervalShorterThanOneSecond(): void
    {
        $this->expectException(ValueError::class);
        $this->expectExceptionMessage('$minCallIntervalSeconds must be 1 or greater, 0.5 given.');

        Synchronizer::sync('2026-11-25', 'v1', 0.5);
    }

    /**
     * @return void
     */
    #[Test]
    public function raceWithClosedAtIsUsable(): void
    {
        $this->assertTrue(Synchronizer::isUsableRace([
            'date' => '2026-11-21',
            'stadium_number' => 2,
            'race_number' => 1,
            'closed_at' => '2026-11-21 10:45:00',
        ]));
    }

    /**
     * @return void
     */
    #[Test]
    public function raceWithNullClosedAtIsNotUsable(): void
    {
        $this->assertFalse(Synchronizer::isUsableRace([
            'date' => '2026-11-21',
            'stadium_number' => 2,
            'race_number' => 1,
            'closed_at' => null,
        ]));
    }

    /**
     * @return void
     */
    #[Test]
    public function raceMissingClosedAtIsNotUsable(): void
    {
        $this->assertFalse(Synchronizer::isUsableRace([
            'date' => '2026-11-21',
            'stadium_number' => 2,
            'race_number' => 1,
        ]));
    }

    /**
     * @return void
     */
    #[Test]
    public function countUsableRacesCountsRacesWithClosedAt(): void
    {
        $payload = self::payload([
            2 => [1 => self::race(2, 1, '10:45:00'), 2 => self::race(2, 2, null)],
            4 => [1 => self::race(4, 1, null)],
        ]);

        $this->assertSame(1, Synchronizer::countUsableRaces($payload));
    }

    /**
     * @return void
     */
    #[Test]
    public function countUsableRacesReturnsZeroForEmptyPayload(): void
    {
        $this->assertSame(0, Synchronizer::countUsableRaces(['programs' => []]));
    }

    /**
     * @return void
     */
    #[Test]
    public function keepExistingRacesReplacesUnusableRaceWithUsableExistingOne(): void
    {
        $payload = self::payload([2 => [1 => self::race(2, 1, null), 2 => self::race(2, 2, '11:12:00', 'new')]]);
        $existing = self::payload([2 => [1 => self::race(2, 1, '10:45:00', 'old'), 2 => self::race(2, 2, '11:12:00', 'old')]]);

        $merged = Synchronizer::keepExistingRaces($payload, $existing);

        $this->assertSame(self::race(2, 1, '10:45:00', 'old'), $merged['programs']['stadiums'][2]['races'][1]);
        $this->assertSame(self::race(2, 2, '11:12:00', 'new'), $merged['programs']['stadiums'][2]['races'][2]);
    }

    /**
     * @return void
     */
    #[Test]
    public function keepExistingRacesLeavesUnusableRaceWhenExistingIsMissingOrUnusable(): void
    {
        $payload = self::payload([2 => [1 => self::race(2, 1, null), 2 => self::race(2, 2, null)]]);
        $existing = self::payload([2 => [1 => self::race(2, 1, null, 'old')]]);

        $merged = Synchronizer::keepExistingRaces($payload, $existing);

        $this->assertSame($payload, $merged);
    }

    /**
     * @return void
     */
    #[Test]
    public function keepExistingRacesWorksWithoutExistingFile(): void
    {
        $payload = self::payload([2 => [1 => self::race(2, 1, null)]]);

        $this->assertSame($payload, Synchronizer::keepExistingRaces($payload, []));
    }

    /**
     * @return void
     */
    #[Test]
    public function describeResponseShowsStatusUrlTitleAndHeaders(): void
    {
        $response = new Response(
            '<html><head><title> アクセスが集中しています </title></head><body>x</body></html>',
            503,
            ['Content-Type' => 'text/html; charset=UTF-8', 'Server' => 'nginx', 'Retry-After' => '60']
        );

        $this->assertSame(
            'status=503 url=https://www.boatrace.jp/owpc/pc/race/racelist?hd=20261121&jcd=02&rno=1'
            . ' title="アクセスが集中しています" length=93 content-type="text/html; charset=UTF-8"'
            . ' server="nginx" retry-after="60"',
            Synchronizer::describeResponse(
                $response,
                'https://www.boatrace.jp/owpc/pc/race/racelist?hd=20261121&jcd=02&rno=1'
            )
        );
    }

    /**
     * @return void
     */
    #[Test]
    public function describeResponseShowsBodySnippetWhenTitleIsMissing(): void
    {
        $response = new Response("<html><body>\n  Just a   moment...\n</body></html>", 200, []);

        $this->assertSame(
            'status=200 url=https://example.test/ title=(none) length=48 body="Just a moment..."',
            Synchronizer::describeResponse($response, 'https://example.test/')
        );
    }

    /**
     * @return void
     */
    #[Test]
    public function describeResponseTruncatesLongBodySnippet(): void
    {
        $response = new Response(str_repeat('あ', 300), 200, []);

        $description = Synchronizer::describeResponse($response, 'https://example.test/');

        $this->assertStringContainsString('body="' . str_repeat('あ', 200) . '…"', $description);
    }

    /**
     * @param array<int, array<int, array<string, mixed>>> $stadiums
     * @return array{programs: array{stadiums: array<int, array{races: array<int, array<string, mixed>>}>}}
     */
    private static function payload(array $stadiums): array
    {
        $payload = ['programs' => ['stadiums' => []]];

        foreach ($stadiums as $stadiumNumber => $races) {
            $payload['programs']['stadiums'][$stadiumNumber]['races'] = $races;
        }

        return $payload;
    }

    /**
     * @param int $stadiumNumber
     * @param int $raceNumber
     * @param ?string $closeTime
     * @param string $title
     * @return array<string, mixed>
     */
    private static function race(int $stadiumNumber, int $raceNumber, ?string $closeTime, string $title = 'title'): array
    {
        return [
            'date' => '2026-11-21',
            'stadium_number' => $stadiumNumber,
            'race_number' => $raceNumber,
            'closed_at' => $closeTime === null ? null : "2026-11-21 {$closeTime}",
            'title' => $closeTime === null ? null : $title,
            'preview' => [],
            'result' => [],
        ];
    }
}
