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
}
