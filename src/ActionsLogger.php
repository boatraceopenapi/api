<?php

declare(strict_types=1);

namespace BOA\API;

/**
 * @author shimomo
 */
final class ActionsLogger
{
    /**
     * @param non-empty-string $title
     * @return void
     */
    public static function startGroup(string $title): void
    {
        echo "::group::{$title}" . PHP_EOL;
    }

    /**
     * @return void
     */
    public static function endGroup(): void
    {
        echo '::endgroup::' . PHP_EOL;
    }

    /**
     * @param non-empty-string $message
     * @return void
     */
    public static function info(string $message): void
    {
        echo $message . PHP_EOL;
    }

    /**
     * @param non-empty-string $message
     * @return void
     */
    public static function notice(string $message): void
    {
        echo "::notice::{$message}" . PHP_EOL;
    }

    /**
     * @param non-empty-string $message
     * @return void
     */
    public static function warning(string $message): void
    {
        echo "::warning::{$message}" . PHP_EOL;
    }

    /**
     * @param non-empty-string $message
     * @return void
     */
    public static function error(string $message): void
    {
        echo "::error::{$message}" . PHP_EOL;
    }
}
