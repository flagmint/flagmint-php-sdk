<?php

declare(strict_types=1);

namespace Flagmint\ConfigSync;

use Flagmint\FlagmintClient;

/**
 * Long-running loop that periodically refreshes config-sync rules.
 *
 * Use under Supervisor/systemd so web PHP-FPM workers stay request-scoped while
 * rules stay warm in a shared {@see \Flagmint\Cache\CacheAdapter} (Redis / Laravel cache).
 *
 * CLI entrypoint: `packages/php-sdk/bin/config-sync-worker.php`.
 *
 * ```php
 * $client = new FlagmintClient(['apiKey' => $key]);
 * $client->ready();
 * (new ConfigSyncWorker($client, intervalSeconds: 30))->run();
 * ```
 */
final class ConfigSyncWorker
{
    /** @var callable|null */
    private $onTick;

    /**
     * @param FlagmintClient $client Bootstrapped FlagmintClient (shared cache adapter recommended)
     * @param int $intervalSeconds Seconds to sleep between refreshes
     * @param callable|null $onTick Optional `fn (FlagmintClient $client): void` after each refresh
     * @param int|null $maxIterations Cap iterations for tests; `null` runs forever
     */
    public function __construct(
        private readonly FlagmintClient $client,
        private readonly int $intervalSeconds = 30,
        ?callable $onTick = null,
        private readonly ?int $maxIterations = null,
    ) {
        $this->onTick = $onTick;
    }

    /**
     * Block and refresh until `$maxIterations` is hit (or forever).
     *
     * @param callable|null $sleeper Injected sleeper for tests: `fn (int $seconds): void`
     */
    public function run(?callable $sleeper = null): void
    {
        $sleep = $sleeper ?? static function (int $seconds): void {
            sleep($seconds);
        };
        $iterations = 0;

        while (true) {
            $this->client->refresh();
            if ($this->onTick !== null) {
                ($this->onTick)($this->client);
            }
            $iterations++;
            if ($this->maxIterations !== null && $iterations >= $this->maxIterations) {
                break;
            }
            $sleep($this->intervalSeconds);
        }
    }
}
