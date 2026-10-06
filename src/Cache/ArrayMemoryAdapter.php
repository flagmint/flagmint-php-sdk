<?php

declare(strict_types=1);

namespace Flagmint\Cache;

/**
 * Process-local {@see CacheAdapter} (default for plain PHP and unit tests).
 *
 * Snapshots live only in this PHP process — fine for CLI scripts and tests,
 * but each FPM worker keeps its own copy. For multi-worker Laravel apps prefer
 * {@see \Flagmint\Laravel\Cache\LaravelCacheAdapter} or {@see RedisAdapter}.
 */
final class ArrayMemoryAdapter implements CacheAdapter
{
    /** @var array<string, RulesSnapshot> */
    private array $store = [];

    /**
     * {@inheritdoc}
     */
    public function loadRulesSnapshot(string $apiKey): ?RulesSnapshot
    {
        return $this->store[$apiKey] ?? null;
    }

    /**
     * {@inheritdoc}
     */
    public function saveRulesSnapshot(string $apiKey, RulesSnapshot $snapshot): void
    {
        $this->store[$apiKey] = $snapshot;
    }

    /**
     * Drop all snapshots (useful between tests).
     */
    public function clear(): void
    {
        $this->store = [];
    }
}
