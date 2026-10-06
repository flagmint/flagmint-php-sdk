<?php

declare(strict_types=1);

namespace Flagmint\Tests\Unit;

use Flagmint\Cache\ArrayMemoryAdapter;
use Flagmint\Cache\CacheAdapter;
use Flagmint\Cache\RedisAdapter;
use Flagmint\Cache\RedisClient;
use Flagmint\Cache\RulesSnapshot;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Shared contract suite: every CacheAdapter must round-trip snapshots.
 */
final class CacheAdapterContractTest extends TestCase
{
    /**
     * @return \Generator<string, array{0: CacheAdapter}>
     */
    public static function adapters(): \Generator
    {
        yield 'memory' => [new ArrayMemoryAdapter()];

        $redis = new class implements RedisClient {
            /** @var array<string, string> */
            public array $store = [];

            public function get(string $key): ?string
            {
                return $this->store[$key] ?? null;
            }

            public function setex(string $key, int $ttlSeconds, string $value): void
            {
                $this->store[$key] = $value;
            }

            public function set(string $key, string $value): void
            {
                $this->store[$key] = $value;
            }
        };
        yield 'redis' => [new RedisAdapter($redis)];
    }

    #[DataProvider('adapters')]
    public function testRoundTrip(CacheAdapter $adapter): void
    {
        $snapshot = new RulesSnapshot(
            version: 11,
            expiresAt: (int) (microtime(true) * 1000) + 60_000,
            flags: [['key' => 'contract']],
            segments: ['s1' => ['id' => 's1', 'rules' => []]],
        );
        $adapter->saveRulesSnapshot('contract-key', $snapshot);
        $loaded = $adapter->loadRulesSnapshot('contract-key');
        $this->assertNotNull($loaded);
        $this->assertSame(11, $loaded->version);
        $this->assertSame('contract', $loaded->flags[0]['key'] ?? null);
        $this->assertArrayHasKey('s1', $loaded->segments);
        $this->assertNull($adapter->loadRulesSnapshot('missing-key'));
    }
}
