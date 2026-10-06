# Cache adapters

The SDK talks only to `Flagmint\Cache\CacheAdapter`:

```php
interface CacheAdapter {
    public function loadRulesSnapshot(string $apiKey): ?RulesSnapshot;
    public function saveRulesSnapshot(string $apiKey, RulesSnapshot $snapshot): void;
}
```

| Adapter | Package | Notes |
|---------|---------|-------|
| `ArrayMemoryAdapter` | php-sdk | Default for plain PHP |
| `RedisAdapter` | php-sdk | Requires `predis/predis` |
| `LaravelCacheAdapter` | laravel | Default for Laravel apps |
| Custom | yours | Implement the interface and pass to `FlagmintClient` |

Lease `expiresAt` is the fail-closed source of truth. Redis may set a safety TTL slightly beyond the lease for eviction only.

## Custom adapter example

See [`packages/php-sdk/examples/custom-cache`](../packages/php-sdk/examples/custom-cache).
