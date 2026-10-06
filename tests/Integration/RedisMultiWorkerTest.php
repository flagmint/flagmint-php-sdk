<?php

declare(strict_types=1);

namespace Flagmint\Tests\Integration;

use Flagmint\Cache\PredisRedisClient;
use Flagmint\Cache\RedisAdapter;
use Flagmint\Cache\RulesSnapshot;
use PHPUnit\Framework\TestCase;
use Predis\Client as PredisClient;

/**
 * Multi-worker share test against Docker Redis when FLAGMINT_TEST_REDIS_URL is set.
 */
final class RedisMultiWorkerTest extends TestCase
{
    public function testSharedSnapshotAcrossAdapterInstances(): void
    {
        $url = getenv('FLAGMINT_TEST_REDIS_URL') ?: '';
        if ($url === '' || !class_exists(PredisClient::class)) {
            $this->markTestSkipped('Set FLAGMINT_TEST_REDIS_URL (e.g. redis://127.0.0.1:6379) to run');
        }

        $adapterA = new RedisAdapter(new PredisRedisClient(new PredisClient($url)), 'flagmint:test:rules:');
        $adapterB = new RedisAdapter(new PredisRedisClient(new PredisClient($url)), 'flagmint:test:rules:');

        $snapshot = new RulesSnapshot(
            version: 42,
            expiresAt: (int) (microtime(true) * 1000) + 3_600_000,
            flags: [['key' => 'shared', 'type' => 'boolean', 'is_active' => true, 'default_value' => true, 'targeting_rules' => [], 'variations' => [['id' => 'on', 'value' => true]], 'rollouts' => []]],
            segments: [],
        );
        $adapterA->saveRulesSnapshot('fm_shared', $snapshot);
        $loaded = $adapterB->loadRulesSnapshot('fm_shared');
        $this->assertNotNull($loaded);
        $this->assertSame(42, $loaded->version);
        $this->assertSame('shared', $loaded->flags[0]['key'] ?? null);
    }
}
