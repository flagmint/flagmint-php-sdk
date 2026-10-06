<?php

declare(strict_types=1);

namespace Flagmint\Examples;

use Flagmint\Cache\CacheAdapter;
use Flagmint\Cache\RulesSnapshot;

/**
 * Example custom CacheAdapter writing rules snapshots to a directory.
 *
 * Not a supported production adapter — copy and adapt for your store.
 */
final class FileCacheAdapter implements CacheAdapter
{
    public function __construct(private readonly string $directory)
    {
        if (!is_dir($this->directory)) {
            mkdir($this->directory, 0777, true);
        }
    }

    public function loadRulesSnapshot(string $apiKey): ?RulesSnapshot
    {
        $path = $this->path($apiKey);
        if (!is_file($path)) {
            return null;
        }

        return RulesSnapshot::fromJson((string) file_get_contents($path));
    }

    public function saveRulesSnapshot(string $apiKey, RulesSnapshot $snapshot): void
    {
        file_put_contents($this->path($apiKey), $snapshot->toJson());
    }

    private function path(string $apiKey): string
    {
        return rtrim($this->directory, '/') . '/' . hash('sha256', $apiKey) . '.json';
    }
}
