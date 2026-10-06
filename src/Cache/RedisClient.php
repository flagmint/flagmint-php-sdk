<?php

declare(strict_types=1);

namespace Flagmint\Cache;

/**
 * Narrow Redis surface required by {@see RedisAdapter}.
 *
 * Keeps the SDK free of a hard Predis/phpredis dependency in production code
 * paths. Wrap your client with {@see PredisRedisClient} or implement this in tests.
 */
interface RedisClient
{
    /**
     * @param string $key
     * @return string|null Raw string value, or null on miss
     */
    public function get(string $key): ?string;

    /**
     * Set a value that expires after `$ttlSeconds`.
     *
     * @param string $key
     * @param int $ttlSeconds Positive TTL in seconds
     * @param string $value
     */
    public function setex(string $key, int $ttlSeconds, string $value): void;

    /**
     * Set a value with no expiry (used only if computed TTL is non-positive).
     *
     * @param string $key
     * @param string $value
     */
    public function set(string $key, string $value): void;
}
