<?php

declare(strict_types=1);

namespace Flagmint\Tests\Unit;

use Flagmint\Client;
use Flagmint\ConfigSync\ConfigSyncWorker;
use Flagmint\Tests\Integration\MockFlagmintHttp;
use GuzzleHttp\Psr7\HttpFactory;
use PHPUnit\Framework\TestCase;

final class ConfigSyncWorkerTest extends TestCase
{
    public function testRunsFiniteIterations(): void
    {
        $ticks = 0;
        $path = dirname(__DIR__) . '/fixtures/config/full_config.json';
        /** @var array<string, mixed> $full */
        $full = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $factory = new HttpFactory();
        $client = new Client([
            'apiKey' => 'fm_test',
            'httpClient' => new MockFlagmintHttp([$full]),
            'requestFactory' => $factory,
            'streamFactory' => $factory,
            'restEndpoint' => 'https://example.test',
            'handshakeEndpoint' => 'https://example.test/auth/asl-handshake',
        ]);
        $client->ready();

        $worker = new ConfigSyncWorker(
            client: $client,
            intervalSeconds: 0,
            onTick: static function () use (&$ticks): void {
                $ticks++;
            },
            maxIterations: 2,
        );
        $worker->run(static function (int $_): void {
        });
        $this->assertSame(2, $ticks);
    }
}
