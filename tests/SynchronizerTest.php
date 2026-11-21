<?php

declare(strict_types=1);

namespace BOA\API\Tests;

use BOA\API\Synchronizer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @author shimomo
 */
final class SynchronizerTest extends TestCase
{
    /**
     * @return void
     */
    #[Test]
    public function scrapedProgramWithClosedAtIsUsable(): void
    {
        $this->assertTrue(Synchronizer::isUsableProgram([
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
    public function scrapedProgramWithoutClosedAtIsNotUsable(): void
    {
        $this->assertFalse(Synchronizer::isUsableProgram([
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
    public function scrapedProgramMissingClosedAtIsNotUsable(): void
    {
        $this->assertFalse(Synchronizer::isUsableProgram([
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
