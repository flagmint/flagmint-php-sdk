<?php

declare(strict_types=1);

namespace Flagmint\Cache;

/**
 * Redis-backed {@see CacheAdapter} for sharing rules across FPM workers.
 *
 * ```php
 * use Flagmint\Cache\PredisRedisClient;
 * use Flagmint\Cache\RedisAdapter;
 * use Predis\Client as Predis;
 *
 * $adapter = new RedisAdapter(
 *     new PredisRedisClient(new Predis('tcp://127.0.0.1:6379')),
 *     prefix: 'flagmint:rules:',
 * );
 *
 * $client = new \Flagmint\Client([
 *     'apiKey' => $key,
 *     'cacheAdapter' => $adapter,
 * ]);
 * ```
 *
 * Keys are `prefix + sha256(apiKey)`. A safety TTL of lease expiry + 5 minutes
 * is applied for Redis eviction; the Client still fail-closes on lease expiry.
 */
final class RedisAdapter implements CacheAdapter
{
    private const DEFAULT_PREFIX = 'flagmint:rules:';

    private const SAFETY_BUFFER_SECONDS = 300;

    /**
     * @param RedisClient $redis Minimal get/setex/set client ({@see PredisRedisClient})
     * @param string $prefix Key prefix (include trailing separator if you want one)
     */
    public function __construct(
        private readonly RedisClient $redis,
        private readonly string $prefix = self::DEFAULT_PREFIX,
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function loadRulesSnapshot(string $apiKey): ?RulesSnapshot
    {
        $raw = $this->redis->get($this->key($apiKey));
        if ($raw === null || $raw === '') {
            return null;
        }

        try {
            return RulesSnapshot::fromJson($raw);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function saveRulesSnapshot(string $apiKey, RulesSnapshot $snapshot): void
    {
        $key = $this->key($apiKey);
        $ttl = $this->ttlSeconds($snapshot->expiresAt);
        if ($ttl > 0) {
            $this->redis->setex($key, $ttl, $snapshot->toJson());
        } else {
            $this->redis->set($key, $snapshot->toJson());
        }
    }

    /**
     * Build a stable Redis key for the SDK environment key.
     *
     * @param string $apiKey
     */
    private function key(string $apiKey): string
    {
        return $this->prefix . hash('sha256', $apiKey);
    }

    /**
     * Seconds until Redis should drop the key (lease end + safety buffer).
     *
     * @param int $expiresAtMs Lease expiry in epoch milliseconds
     */
    private function ttlSeconds(int $expiresAtMs): int
    {
        $seconds = (int) ceil(($expiresAtMs / 1000) - time()) + self::SAFETY_BUFFER_SECONDS;

        return max(1, $seconds);
    }
}
