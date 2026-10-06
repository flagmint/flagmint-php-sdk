<?php

declare(strict_types=1);

namespace Flagmint\Cache;

/**
 * Persistence hook for compiled rules + lease metadata.
 *
 * Implement this to share one rules snapshot across PHP-FPM workers (Redis,
 * Memcached, files, etc.). Pass your instance into {@see \Flagmint\FlagmintClient}:
 *
 * ```php
 * $client = new FlagmintClient([
 *     'apiKey' => $key,
 *     'cacheAdapter' => new MyRedisAdapter($redis),
 * ]);
 * ```
 *
 * **Lease vs TTL:** {@see RulesSnapshot::$expiresAt} is the fail-closed source
 * of truth. Adapters may set a storage TTL slightly past the lease for eviction,
 * but {@see \Flagmint\FlagmintClient} must still refuse local eval after expiry.
 *
 * Built-ins: {@see ArrayMemoryAdapter} (default), {@see RedisAdapter}.
 * Laravel apps use {@see \Flagmint\Laravel\Cache\LaravelCacheAdapter} by default.
 */
interface CacheAdapter
{
    /**
     * Load the last saved rules snapshot for this SDK key, or null on miss.
     *
     * Called during FlagmintClient construction so a new worker can hydrate before the
     * first network refresh completes.
     *
     * @param string $apiKey Environment SDK key (used as the logical cache key)
     * @return RulesSnapshot|null Null when nothing is cached or the value is corrupt
     */
    public function loadRulesSnapshot(string $apiKey): ?RulesSnapshot;

    /**
     * Persist rules after a successful config-sync refresh.
     *
     * @param string $apiKey Environment SDK key
     * @param RulesSnapshot $snapshot Flags, segments, version bookmark, and lease expiresAt
     */
    public function saveRulesSnapshot(string $apiKey, RulesSnapshot $snapshot): void;
}
