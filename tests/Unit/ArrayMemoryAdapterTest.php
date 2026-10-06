<?php

declare(strict_types=1);

namespace Flagmint\Tests\Unit;

use Flagmint\Cache\ArrayMemoryAdapter;
use Flagmint\Cache\RulesSnapshot;
use Flagmint\FlagmintClient;
use PHPUnit\Framework\TestCase;

final class ArrayMemoryAdapterTest extends TestCase
{
    public function testRoundTrip(): void
    {
        $adapter = new ArrayMemoryAdapter();
        $snapshot = new RulesSnapshot(2, 4102444800000, [['key' => 'a']], []);
        $adapter->saveRulesSnapshot('key', $snapshot);
        $loaded = $adapter->loadRulesSnapshot('key');
        $this->assertNotNull($loaded);
        $this->assertSame(2, $loaded->version);
        $this->assertNull($adapter->loadRulesSnapshot('missing'));
    }

    public function testClientRejectsNonAdapter(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new FlagmintClient([
            'apiKey' => 'fm_test',
            'cacheAdapter' => new \stdClass(),
            'enableFlagmint' => false,
            'httpClient' => $this->createStub(\Psr\Http\Client\ClientInterface::class),
            'requestFactory' => $this->createStub(\Psr\Http\Message\RequestFactoryInterface::class),
            'streamFactory' => $this->createStub(\Psr\Http\Message\StreamFactoryInterface::class),
        ]);
    }
}
