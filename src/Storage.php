<?php

declare(strict_types=1);

namespace BOA\API;

use RuntimeException;

/**
 * @author shimomo
 */
final class Storage
{
    /**
     * @param non-empty-string $path
     * @return array
     * @throws \RuntimeException
     */
    public static function load(string $path): array
    {
        if (!file_exists($path)) {
            throw new RuntimeException("Failed to load JSON: file not found: {$path}");
        }

        $json = file_get_contents($path);

        if ($json === false) {
            throw new RuntimeException("Failed to load JSON: could not read file: {$path}");
        }

        $payload = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException(
                "Failed to load JSON from {$path}: " . json_last_error_msg()
            );
        }

        if (!is_array($payload)) {
            throw new RuntimeException(
                "Failed to load JSON from {$path}: payload is not an array"
            );
        }

        return $payload;
    }

    /**
     * @param non-empty-string $path
     * @param array $payload
     * @return void
     * @throws \RuntimeException
     */
    public static function save(string $path, array $payload): void
    {
        $json = json_encode($payload);
        if ($json === false) {
            throw new RuntimeException(
                "Failed to encode data to JSON: " . json_last_error_msg()
            );
        }

        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException("Failed to create directory: {$directory}");
        }

        if (file_put_contents($path, $json) === false) {
            throw new RuntimeException("Failed to save JSON to {$path}");
        }
    }
}
