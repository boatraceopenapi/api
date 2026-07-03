<?php

declare(strict_types=1);

namespace BOA\API\Tests;

use BOA\API\ActionsLogger;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @author shimomo
 */
final class ActionsLoggerTest extends TestCase
{
    /**
     * @return void
     */
    #[Test]
    public function testStartGroup(): void
    {
        $this->expectOutputString('::group::Build' . PHP_EOL);

        ActionsLogger::startGroup('Build');
    }

    /**
     * @return void
     */
    #[Test]
    public function testEndGroup(): void
    {
        $this->expectOutputString('::endgroup::' . PHP_EOL);

        ActionsLogger::endGroup();
    }

    /**
     * @return void
     */
    #[Test]
    public function testInfo(): void
    {
        $this->expectOutputString('Some info message' . PHP_EOL);

        ActionsLogger::info('Some info message');
    }

    /**
     * @return void
     */
    #[Test]
    public function testNotice(): void
    {
        $this->expectOutputString('::notice::Some notice message' . PHP_EOL);

        ActionsLogger::notice('Some notice message');
    }

    /**
     * @return void
     */
    #[Test]
    public function testWarning(): void
    {
        $this->expectOutputString('::warning::Some warning message' . PHP_EOL);

        ActionsLogger::warning('Some warning message');
    }

    /**
     * @return void
     */
    #[Test]
    public function testError(): void
    {
        $this->expectOutputString('::error::Some error message' . PHP_EOL);

        ActionsLogger::error('Some error message');
    }
}
