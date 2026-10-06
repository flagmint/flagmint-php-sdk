<?php

declare(strict_types=1);

namespace Flagmint\Tests\Integration;

use Flagmint\Cache\ArrayMemoryAdapter;
use Flagmint\FlagmintClient;
use GuzzleHttp\Psr7\HttpFactory;
use PHPUnit\Framework\TestCase;

final class FlagmintClientConfigSyncTest extends TestCase
{
    public function testHandshakeConfigLocalEvalAndCacheHydrate(): void
    {
        $full = $this->fixture('config/full_config.json');
        $http = new MockFlagmintHttp([$full]);
        $adapter = new ArrayMemoryAdapter();
        $factory = new HttpFactory();

        $client = new FlagmintClient([
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
        $client2 = new FlagmintClient([
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

        $client = new FlagmintClient([
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
        $client = new FlagmintClient([
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
        $client = new FlagmintClient([
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
        $client = new FlagmintClient([
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

    public function testRefreshDoesNotThrowOnHandshakeFailure(): void
    {
        $errors = [];
        $http = new class implements \Psr\Http\Client\ClientInterface {
            public function sendRequest(\Psr\Http\Message\RequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                throw new \RuntimeException('connection reset');
            }
        };
        $factory = new HttpFactory();
        $client = new FlagmintClient([
            'apiKey' => 'fm_test_key',
            'httpClient' => $http,
            'requestFactory' => $factory,
            'streamFactory' => $factory,
            'restEndpoint' => 'https://example.test',
            'handshakeEndpoint' => 'https://example.test/auth/asl-handshake',
            'onError' => static function (array $err) use (&$errors): void {
                $errors[] = $err;
            },
        ]);

        $client->refresh();
        $this->assertNotEmpty($errors);
        $this->assertSame('ERR_NETWORK', $errors[0]['code']);
    }

    public function testFlushEventsRebuffersOnFailure(): void
    {
        $full = $this->fixture('config/full_config.json');
        $inner = new MockFlagmintHttp([$full]);
        $http = new class ($inner) implements \Psr\Http\Client\ClientInterface {
            public function __construct(private readonly MockFlagmintHttp $inner)
            {
            }

            public function sendRequest(\Psr\Http\Message\RequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                if (str_contains($request->getUri()->getPath(), '/evaluator/events')) {
                    return new \GuzzleHttp\Psr7\Response(500, [], '{"error":"boom"}');
                }

                return $this->inner->sendRequest($request);
            }
        };
        $factory = new HttpFactory();
        $client = new FlagmintClient([
            'apiKey' => 'fm_test_key',
            'httpClient' => $http,
            'requestFactory' => $factory,
            'streamFactory' => $factory,
            'restEndpoint' => 'https://example.test',
            'handshakeEndpoint' => 'https://example.test/auth/asl-handshake',
        ]);
        $client->ready();
        $client->track('new-checkout', ['action' => 'click']);
        $this->assertFalse($client->flushEvents());
        $this->assertSame(1, $client->getEventBuffer()->count());
    }

    public function testSdkIdentityOnHandshakeConfigAndEvents(): void
    {
        $full = $this->fixture('config/full_config.json');
        $http = new MockFlagmintHttp([$full]);
        $factory = new HttpFactory();
        $client = new FlagmintClient([
            'apiKey' => 'fm_test_key',
            'httpClient' => $http,
            'requestFactory' => $factory,
            'streamFactory' => $factory,
            'restEndpoint' => 'https://example.test',
            'handshakeEndpoint' => 'https://example.test/auth/asl-handshake',
            'wrapperInfo' => ['name' => 'flagmint-laravel', 'version' => '0.1.1'],
            'sdkVersion' => '0.1.1-test',
        ]);

        $this->assertTrue($client->ready());

        $handshake = $http->getLastHandshakeBody();
        $this->assertNotNull($handshake);
        $this->assertSame('0.1.1-test', $handshake['sdkVersion'] ?? null);
        $this->assertSame('php', $handshake['platform'] ?? null);
        $this->assertSame('flagmint-laravel', $handshake['wrapperName'] ?? null);
        $this->assertSame('0.1.1', $handshake['wrapperVersion'] ?? null);
        $this->assertSame('0.1.1-test', $http->getLastHandshakeHeaders()['x-flagmint-sdk-version'] ?? null);

        $this->assertStringContainsString('sdkVersion=0.1.1-test', $http->getLastConfigUrl());
        $this->assertStringContainsString('wrapperName=flagmint-laravel', $http->getLastConfigUrl());
        $this->assertSame('php', $http->getLastConfigHeaders()['x-flagmint-platform'] ?? null);

        $client->track('new-checkout', ['eventName' => 'click']);
        $this->assertTrue($client->flushEvents());
        $this->assertSame('0.1.1-test', $http->getLastEventsHeaders()['x-flagmint-sdk-version'] ?? null);
    }

    public function testEvaluationReportsWhenAnalyticsEnabled(): void
    {
        $full = $this->fixture('config/full_config.json');
        $full['flags'][0]['analytics_enabled'] = true;
        $http = new MockFlagmintHttp([$full]);
        $factory = new HttpFactory();
        $client = new FlagmintClient([
            'apiKey' => 'fm_test_key',
            'httpClient' => $http,
            'requestFactory' => $factory,
            'streamFactory' => $factory,
            'restEndpoint' => 'https://example.test',
            'handshakeEndpoint' => 'https://example.test/auth/asl-handshake',
        ]);
        $this->assertTrue($client->ready());

        $ctx = ['kind' => 'user', 'key' => 'u_analytics'];
        $first = $client->bool('new-checkout', false, $ctx);
        $second = $client->bool('new-checkout', false, $ctx);
        $this->assertSame($first, $second);

        $this->assertTrue($client->flushEvents());
        $body = $http->getLastEventsBody();
        $this->assertNotNull($body);
        $events = $body['events'] ?? [];
        $this->assertCount(1, $events);
        $this->assertSame('evaluation', $events[0]['kind']);
        $this->assertSame('new-checkout', $events[0]['flagKey']);
        $this->assertSame(2, $events[0]['count']);
        $this->assertSame('u_analytics', $events[0]['userKey']);
        $this->assertSame($first, $events[0]['variationValue']);
    }

    public function testNoEvaluationReportWhenAnalyticsDisabled(): void
    {
        $full = $this->fixture('config/full_config.json');
        $http = new MockFlagmintHttp([$full]);
        $factory = new HttpFactory();
        $client = new FlagmintClient([
            'apiKey' => 'fm_test_key',
            'httpClient' => $http,
            'requestFactory' => $factory,
            'streamFactory' => $factory,
            'restEndpoint' => 'https://example.test',
            'handshakeEndpoint' => 'https://example.test/auth/asl-handshake',
        ]);
        $this->assertTrue($client->ready());
        $this->assertTrue($client->bool('new-checkout', false, ['key' => 'u1']));
        $events = $client->drainPendingEvents();
        $this->assertSame([], $events);
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
