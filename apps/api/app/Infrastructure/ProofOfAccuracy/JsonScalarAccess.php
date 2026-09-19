<?php

declare(strict_types=1);

namespace App\Infrastructure\ProofOfAccuracy;

/**
 * Narrows a decoded JSON `array<string, mixed>`'s values to the
 * concrete scalar/array type this Golden Dataset fixture schema
 * declares for a given key — PHPStan (level 9) cannot infer a
 * `json_decode(..., true)` result's shape any other way, and this
 * codebase's own convention is to fail loudly on an unexpected shape
 * rather than let a `mixed` silently flow into a value object's typed
 * constructor.
 */
trait JsonScalarAccess
{
    /**
     * @param  array<string, mixed>  $data
     */
    private static function jsonStr(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        if (! is_string($value)) {
            throw new \RuntimeException("Expected a string value for \"{$key}\", got: ".get_debug_type($value));
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function jsonStrOrNull(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw new \RuntimeException("Expected a string or null value for \"{$key}\", got: ".get_debug_type($value));
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function jsonInt(array $data, string $key): int
    {
        $value = $data[$key] ?? null;

        if (! is_int($value)) {
            throw new \RuntimeException("Expected an int value for \"{$key}\", got: ".get_debug_type($value));
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function jsonBool(array $data, string $key): bool
    {
        $value = $data[$key] ?? null;

        if (! is_bool($value)) {
            throw new \RuntimeException("Expected a bool value for \"{$key}\", got: ".get_debug_type($value));
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function jsonArr(array $data, string $key): array
    {
        $value = $data[$key] ?? null;

        if (! is_array($value)) {
            throw new \RuntimeException("Expected an array value for \"{$key}\", got: ".get_debug_type($value));
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private static function jsonStrList(array $data, string $key): array
    {
        $value = $data[$key] ?? null;

        if (! is_array($value)) {
            throw new \RuntimeException("Expected a list of strings for \"{$key}\", got: ".get_debug_type($value));
        }

        $list = [];
        foreach (array_values($value) as $item) {
            if (! is_string($item)) {
                throw new \RuntimeException("Expected every element of \"{$key}\" to be a string, got: ".get_debug_type($item));
            }

            $list[] = $item;
        }

        return $list;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<array<string, mixed>>
     */
    private static function jsonArrList(array $data, string $key, mixed $default = []): array
    {
        $value = $data[$key] ?? $default;

        if (! is_array($value)) {
            throw new \RuntimeException("Expected a list value for \"{$key}\", got: ".get_debug_type($value));
        }

        $list = [];
        foreach (array_values($value) as $item) {
            if (! is_array($item)) {
                throw new \RuntimeException("Expected every element of \"{$key}\" to be an array, got: ".get_debug_type($item));
            }

            /** @var array<string, mixed> $item */
            $list[] = $item;
        }

        return $list;
    }
}
