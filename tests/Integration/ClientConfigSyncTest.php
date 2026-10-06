<?php

declare(strict_types=1);

namespace Flagmint\Tests\Integration;

use Flagmint\Cache\ArrayMemoryAdapter;
use Flagmint\Client;
use GuzzleHttp\Psr7\HttpFactory;
use PHPUnit\Framework\TestCase;

final class ClientConfigSyncTest extends TestCase
{
    public function testHandshakeConfigLocalEvalAndCacheHydrate(): void
    {
        $full = $this->fixture('config/full_config.json');
        $http = new MockFlagmintHttp([$full]);
        $adapter = new ArrayMemoryAdapter();
        $factory = new HttpFactory();

        $client = new Client([
            'apiKey' => 'fm_test_key',
            'cacheAdapter' => $adapter,
            'httpClient' => $http,
            'requestFactory' => $factory,
            'streamFactory' => $factory,
            'restEndpoint' => 'https://example.test',
            'handshakeEndpoint' => 'https://example.test/auth/asl-handshake',
        ]);

        $this->assertTrue($client->ready());
        $this->assertTrue($client->isEnabled('new-checkout', ['kind' => 'user', 'key' => 'u1']));
        $this->assertSame(1, $http->getHandshakeCount());
        $this->assertNotNull($adapter->loadRulesSnapshot('fm_test_key'));

        // "Restart": new client, same adapter, no network needed for eval if lease valid —
        // still needs refresh for MAC, but hydrate makes version available.
        $http2 = new MockFlagmintHttp([$full]);
        $client2 = new Client([
            'apiKey' => 'fm_test_key',
            'cacheAdapter' => $adapter,
            'httpClient' => $http2,
            'requestFactory' => $factory,
            'streamFactory' => $factory,
            'restEndpoint' => 'https://example.test',
            'handshakeEndpoint' => 'https://example.test/auth/asl-handshake',
        ]);
        $this->assertSame(3, $client2->getRulesStore()->getState()->version);
        $this->assertTrue($client2->ready());
        $this->assertTrue($client2->bool('new-checkout', false, ['kind' => 'user', 'key' => 'u1']));
    }

    public function testCatchUpDeltaAfterFullConfig(): void
    {
        $full = $this->fixture('config/full_config.json');
        $delta = $this->fixture('config/delta_upsert.json');
        $http = new MockFlagmintHttp([$full, $delta]);
        $factory = new HttpFactory();

        $client = new Client([
            'apiKey' => 'fm_test_key',
            'httpClient' => $http,
            'requestFactory' => $factory,
            'streamFactory' => $factory,
            'restEndpoint' => 'https://example.test',
            'handshakeEndpoint' => 'https://example.test/auth/asl-handshake',
        ]);
        $this->assertTrue($client->ready());
        $this->assertFalse($client->bool('new-checkout', true, ['kind' => 'user', 'key' => 'u1']));
        $this->assertSame(4, $client->getRulesStore()->getState()->version);
    }

    public function testFlushEvents(): void
    {
        $full = $this->fixture('config/full_config.json');
        $http = new MockFlagmintHttp([$full]);
        $factory = new HttpFactory();
        $client = new Client([
            'apiKey' => 'fm_test_key',
            'httpClient' => $http,
            'requestFactory' => $factory,
            'streamFactory' => $factory,
            'restEndpoint' => 'https://example.test',
            'handshakeEndpoint' => 'https://example.test/auth/asl-handshake',
        ]);
        $client->ready();
        $client->track('new-checkout', ['action' => 'click']);
        $client->trackError('new-checkout', ['message' => 'boom']);
        $this->assertTrue($client->flushEvents());
        $this->assertSame(1, $http->getEventsFlushed());
    }

    public function testEnableFlagmintFalseUsesFallback(): void
    {
        $factory = new HttpFactory();
        $client = new Client([
            'apiKey' => 'fm_test_key',
            'enableFlagmint' => false,
            'httpClient' => new MockFlagmintHttp([]),
            'requestFactory' => $factory,
            'streamFactory' => $factory,
        ]);
        $this->assertTrue($client->ready());
        $this->assertFalse($client->isEnabled('new-checkout', ['key' => 'u1'], false));
        $this->assertSame('x', $client->string('missing', 'x'));
    }

    public function testAuthErrorSurfaced(): void
    {
        $errors = [];
        $http = new class implements \Psr\Http\Client\ClientInterface {
            public function sendRequest(\Psr\Http\Message\RequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                return new \GuzzleHttp\Psr7\Response(401, [], '{"error":"unauthorized"}');
            }
        };
        $factory = new HttpFactory();
        $client = new Client([
            'apiKey' => 'bad',
            'httpClient' => $http,
            'requestFactory' => $factory,
            'streamFactory' => $factory,
            'restEndpoint' => 'https://example.test',
            'handshakeEndpoint' => 'https://example.test/auth/asl-handshake',
            'onError' => static function (array $err) use (&$errors): void {
                $errors[] = $err;
            },
        ]);
        $client->ready();
        $this->assertNotEmpty($errors);
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
