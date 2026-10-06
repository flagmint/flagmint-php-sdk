<?php

declare(strict_types=1);

namespace Flagmint\ConfigSync;

/**
 * Sign and verify config-sync payloads (lease, fullConfig, delta, deltas).
 *
 * Canonicalization sorts object keys recursively so PHP, JS, and Go produce the
 * same HMAC input. Matches FF-EU `canonicalizeForSigning` / JS `signPayload.ts`.
 *
 * Prefer the ECDH session MAC key from {@see AslEcdh::deriveMacKey()} as `$secret`.
 */
final class SignPayload
{
    /**
     * Produce stable JSON for HMAC (sorted keys, UTF-8, unescaped slashes).
     *
     * @param mixed $value Arbitrary JSON-compatible structure
     * @return string Canonical JSON string
     */
    public static function canonicalize(mixed $value): string
    {
        return json_encode(self::sortValue($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * HMAC-SHA256 hex digest of `$body` with any existing `signature` field omitted.
     *
     * @param array<string, mixed> $body Payload including optional stale `signature`
     * @param string $secret Raw 32-byte MAC key (or UTF-8 string for tests)
     * @return string Lowercase hex digest
     */
    public static function sign(array $body, string $secret): string
    {
        $unsigned = $body;
        unset($unsigned['signature']);
        $canonical = self::canonicalize($unsigned);

        return hash_hmac('sha256', $canonical, $secret);
    }

    /**
     * @param array<string, mixed> $body
     * @param string $secret
     */
    public static function verify(array $body, string $secret): bool
    {
        $signature = $body['signature'] ?? null;
        if (!is_string($signature) || $signature === '') {
            return false;
        }

        try {
            $expected = self::sign($body, $secret);
        } catch (\Throwable) {
            return false;
        }

        return hash_equals($expected, $signature);
    }

    /**
     * @param int $expiresAt Epoch milliseconds
     * @param int|null $nowMs
     */
    public static function isExpired(int $expiresAt, ?int $nowMs = null): bool
    {
        $now = $nowMs ?? (int) (microtime(true) * 1000);

        return $expiresAt <= 0 || $now >= $expiresAt;
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private static function sortValue(mixed $value): mixed
    {
        // Empty JSON objects must stay `{}` (see {@see \Flagmint\Support\Json}).
        if ($value instanceof \stdClass) {
            return new \stdClass();
        }

        if (!is_array($value)) {
            return $value;
        }

        $isList = array_is_list($value);
        if ($isList) {
            return array_map([self::class, 'sortValue'], $value);
        }

        ksort($value);
        $sorted = [];
        foreach ($value as $key => $item) {
            $sorted[$key] = self::sortValue($item);
        }

        return $sorted;
    }
}
