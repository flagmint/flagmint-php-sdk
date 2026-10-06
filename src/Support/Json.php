<?php

declare(strict_types=1);

namespace Flagmint\Support;

/**
 * JSON helpers that preserve empty objects (`{}`) — PHP's `json_decode(..., true)`
 * otherwise turns them into `[]`, which breaks config-sync HMAC verification.
 */
final class Json
{
    /**
     * Decode JSON for config-sync: empty objects stay as empty {@see \stdClass}.
     *
     * @return array<string, mixed>|list<mixed>|\stdClass|null
     */
    public static function decodePreservingEmptyObjects(string $raw): mixed
    {
        $decoded = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);

        return self::toSignable($decoded);
    }

    /**
     * Convert decoded JSON into arrays while keeping empty objects as {@see \stdClass}.
     *
     * @param mixed $value
     * @return mixed
     */
    public static function toSignable(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $vars = get_object_vars($value);
            if ($vars === []) {
                return new \stdClass();
            }
            $out = [];
            foreach ($vars as $key => $item) {
                $out[$key] = self::toSignable($item);
            }

            return $out;
        }

        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $item) {
                $out[$key] = self::toSignable($item);
            }

            return $out;
        }

        return $value;
    }

    /**
     * Convert empty {@see \stdClass} markers to empty arrays for in-memory rules state.
     *
     * @param mixed $value
     * @return mixed
     */
    public static function toArrays(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $vars = get_object_vars($value);
            if ($vars === []) {
                return [];
            }
            $out = [];
            foreach ($vars as $key => $item) {
                $out[$key] = self::toArrays($item);
            }

            return $out;
        }

        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $item) {
                $out[$key] = self::toArrays($item);
            }

            return $out;
        }

        return $value;
    }
}
