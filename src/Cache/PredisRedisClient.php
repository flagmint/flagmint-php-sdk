<?php

declare(strict_types=1);

namespace Flagmint\Cache;

/**
 * Adapts a Predis `Client` (or any object with get/setex/set) to {@see RedisClient}.
 *
 * ```php
 * $redis = new PredisRedisClient(new \Predis\Client(getenv('REDIS_URL')));
 * $adapter = new RedisAdapter($redis);
 * ```
 */
final class PredisRedisClient implements RedisClient
{
    /**
     * @param object $predis Predis\Client or duck-typed equivalent exposing get/setex/set
     */
    public function __construct(private readonly object $predis)
    {
    }

    /**
     * {@inheritdoc}
     */
    public function get(string $key): ?string
    {
        $value = $this->predis->get($key);

        return is_string($value) ? $value : null;
    }

    /**
     * {@inheritdoc}
     */
    public function setex(string $key, int $ttlSeconds, string $value): void
    {
        $this->predis->setex($key, $ttlSeconds, $value);
    }

    /**
     * {@inheritdoc}
     */
    public function set(string $key, string $value): void
    {
        $this->predis->set($key, $value);
    }
}
