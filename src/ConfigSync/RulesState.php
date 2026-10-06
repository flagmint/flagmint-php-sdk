<?php

declare(strict_types=1);

namespace Flagmint\ConfigSync;

use Flagmint\Cache\RulesSnapshot;

/**
 * Live in-memory view of the rules the SDK evaluates against.
 *
 * Updated when signed config-sync payloads arrive (`fullConfig`, `delta`,
 * `lease`). Evaluation should only run when {@see RulesStore::isReady()} is
 * true — after the lease expires the store fails closed and callers get their
 * fallbacks instead of stale targeting.
 *
 * Prefer mutating this via {@see RulesStore}; treat instances as value objects
 * you clone when applying patches.
 */
final class RulesState
{
    /**
     * @param int $version Monotonic bookmark for flag data. Used as
     *     `sinceVersion` on the next REST catch-up. A lease may advertise a
     *     higher server version without changing this bookmark — that sets
     *     {@see $needsFullConfig} so we do not skip deltas.
     * @param int $expiresAt Lease end time in epoch **milliseconds**. After
     *     this instant, local eval must fail closed until a successful refresh.
     * @param array<string, array<string, mixed>> $flags SdkFlagConfig maps keyed
     *     by flag key (e.g. `new-checkout` → compiled flag document).
     * @param array<string, array<string, mixed>> $segments Segment definitions
     *     keyed by segment id, referenced from targeting rules.
     * @param bool $ready Whether the store believes it has usable rules. Still
     *     gated by {@see $expiresAt} in {@see RulesStore::isReady()}.
     * @param bool $needsFullConfig When true, the next refresh should request a
     *     full snapshot (cold start, version gap, or lease/bookmark mismatch)
     *     instead of delta catch-up only.
     */
    public function __construct(
        public int $version = 0,
        public int $expiresAt = 0,
        public array $flags = [],
        public array $segments = [],
        public bool $ready = false,
        public bool $needsFullConfig = true,
    ) {
    }

    /**
     * Create an empty, not-ready state (cold start before first fullConfig).
     *
     * @return self
     */
    public static function empty(): self
    {
        return new self();
    }

    /**
     * Shallow-copy for immutable-style reduce steps (flags/segments arrays are
     * shared until a patch replaces them).
     *
     * @return self
     */
    public function clone(): self
    {
        return new self(
            version: $this->version,
            expiresAt: $this->expiresAt,
            flags: $this->flags,
            segments: $this->segments,
            ready: $this->ready,
            needsFullConfig: $this->needsFullConfig,
        );
    }

    /**
     * Convert to a {@see RulesSnapshot} suitable for {@see \Flagmint\Cache\CacheAdapter}
     * persistence (list of flags, not a keyed map).
     *
     * @return RulesSnapshot
     */
    public function toSnapshot(): RulesSnapshot
    {
        return new RulesSnapshot(
            version: $this->version,
            expiresAt: $this->expiresAt,
            flags: array_values($this->flags),
            segments: $this->segments,
        );
    }

    /**
     * Rebuild state from a persisted snapshot (e.g. after FPM boot / Redis hydrate).
     *
     * Marks the store ready when both version and lease are present; sets
     * {@see $needsFullConfig} when version is still zero.
     *
     * @param RulesSnapshot $snapshot Cached rules from a previous refresh
     * @return self
     */
    public static function fromSnapshot(RulesSnapshot $snapshot): self
    {
        $flags = [];
        foreach ($snapshot->flags as $flag) {
            if (isset($flag['key']) && is_string($flag['key'])) {
                $flags[$flag['key']] = $flag;
            }
        }

        return new self(
            version: $snapshot->version,
            expiresAt: $snapshot->expiresAt,
            flags: $flags,
            segments: $snapshot->segments,
            ready: $snapshot->version > 0 && $snapshot->expiresAt > 0,
            needsFullConfig: $snapshot->version <= 0,
        );
    }
}
