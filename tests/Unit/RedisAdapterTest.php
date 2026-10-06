<?php

declare(strict_types=1);

namespace Flagmint\Tests\Unit;

use Flagmint\Cache\RedisAdapter;
use Flagmint\Cache\RedisClient;
use Flagmint\Cache\RulesSnapshot;
use PHPUnit\Framework\TestCase;

final class RedisAdapterTest extends TestCase
{
    public function testRoundTripWithFakeRedisClient(): void
    {
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

        $adapter = new RedisAdapter($redis);
        $snapshot = new RulesSnapshot(5, (int) (microtime(true) * 1000) + 60_000, [['key' => 'f']], []);
        $adapter->saveRulesSnapshot('fm_key', $snapshot);
        $loaded = $adapter->loadRulesSnapshot('fm_key');
        $this->assertNotNull($loaded);
        $this->assertSame(5, $loaded->version);
    }
}
