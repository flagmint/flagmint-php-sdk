<?php

declare(strict_types=1);

namespace Flagmint\Eval;

/**
 * Stable bucketing hash for rollouts (ported from Go/JS HashPercent).
 */
final class Hash
{
    /**
     * Return an integer bucket in [0, 99] for the given key.
     *
     * @param string $key
     */
    public static function percent(string $key): int
    {
        $hash = hash('sha256', $key, true);
        $value = unpack('N', substr($hash, 0, 4))[1];

        return (int) ($value % 100);
    }
}
