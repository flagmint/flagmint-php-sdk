<?php

declare(strict_types=1);

namespace Flagmint\Tests\Unit;

use Flagmint\ConfigSync\RulesStore;
use Flagmint\ConfigSync\SignPayload;
use PHPUnit\Framework\TestCase;

final class RulesStoreTest extends TestCase
{
    public function testApplyFullConfigAndDelta(): void
    {
        $store = new RulesStore();
        $full = $this->fixture('config/full_config.json');
        $this->assertTrue($store->reduce($full)['ok']);
        $this->assertSame(3, $store->getState()->version);
        $this->assertNotNull($store->getFlag('new-checkout'));
        $this->assertTrue($store->isReady(1700000000000));

        $delta = $this->fixture('config/delta_upsert.json');
        $this->assertTrue($store->reduce($delta)['ok']);
        $this->assertSame(4, $store->getState()->version);
        $this->assertFalse($store->getFlag('new-checkout')['is_active']);
    }

    public function testVersionGapSetsNeedsFullConfig(): void
    {
        $store = new RulesStore();
        $store->reduce($this->fixture('config/full_config.json'));
        $gap = $this->fixture('config/delta_upsert.json');
        $gap['fromVersion'] = 99;
        $result = $store->reduce($gap);
        $this->assertFalse($result['ok']);
        $this->assertSame('version_gap', $result['reason']);
        $this->assertTrue($store->getState()->needsFullConfig);
    }

    public function testRejectsBadSignature(): void
    {
        $secret = random_bytes(32);
        $store = new RulesStore($secret);
        $payload = $this->fixture('config/full_config.json');
        $payload['signature'] = '00';
        $result = $store->apply($payload);
        $this->assertFalse($result['ok']);
        $this->assertSame('bad_signature', $result['reason']);
    }

    public function testAcceptsValidSignature(): void
    {
        $secret = random_bytes(32);
        $store = new RulesStore($secret);
        $payload = $this->fixture('config/full_config.json');
        $payload['signature'] = SignPayload::sign($payload, $secret);
        $this->assertTrue($store->apply($payload)['ok']);
    }

    public function testLeaseDoesNotAdvanceVersionBookmark(): void
    {
        $store = new RulesStore();
        $store->reduce($this->fixture('config/full_config.json'));
        $store->reduce([
            'type' => 'lease',
            'version' => 9,
            'serverNow' => 1,
            'expiresAt' => 4102444800000,
        ]);
        $this->assertSame(3, $store->getState()->version);
        $this->assertTrue($store->getState()->needsFullConfig);
    }

    public function testExpiredPayloadFailClosed(): void
    {
        $store = new RulesStore();
        $payload = $this->fixture('config/full_config.json');
        $payload['expiresAt'] = 1000;
        $result = $store->reduce($payload, 2000);
        $this->assertFalse($result['ok']);
        $this->assertSame('expired', $result['reason']);
        $this->assertFalse($store->getState()->ready);
    }

    public function testDeltasBatchRollsBackOnStepFailure(): void
    {
        $store = new RulesStore();
        $store->reduce($this->fixture('config/full_config.json'));
        $this->assertTrue($store->getFlag('new-checkout')['is_active']);

        $goodStep = $this->fixture('config/delta_upsert.json');
        unset($goodStep['type'], $goodStep['expiresAt']);
        $badStep = [
            'fromVersion' => 99,
            'toVersion' => 100,
            'upserts' => [],
            'deletes' => [],
            'segments' => [],
        ];

        $result = $store->reduce([
            'type' => 'deltas',
            'fromVersion' => 3,
            'toVersion' => 100,
            'expiresAt' => 4102444800000,
            'items' => [$goodStep, $badStep],
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame('version_gap', $result['reason']);
        $this->assertSame(3, $store->getState()->version);
        $this->assertTrue($store->getFlag('new-checkout')['is_active']);
        $this->assertTrue($store->getState()->needsFullConfig);
    }

    /**
     * @return array<string, mixed>
     */
    private function fixture(string $relative): array
    {
        $path = dirname(__DIR__) . '/fixtures/' . $relative;
        /** @var array<string, mixed> $data */
        $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        return $data;
    }
}
