<?php

declare(strict_types=1);

namespace Flagmint\ConfigSync;

use Flagmint\Cache\RulesSnapshot;

/**
 * Owns the live {@see RulesState}: verify signatures, apply lease/fullConfig/deltas.
 *
 * Semantics match the JS SDK `RulesStore` / `reduceRules`, including the rule that
 * a lease renews expiry but must **not** advance the version bookmark when the
 * server version disagrees (that would skip catch-up).
 *
 * Most apps never touch this directly — use {@see \Flagmint\FlagmintClient}.
 */
final class RulesStore
{
    private RulesState $state;

    private ?string $macKey;

    /**
     * @param string|null $macKey ECDH-derived session MAC key (32 raw bytes), or null until handshake
     * @param RulesState|null $initial Optional preloaded state (tests / hydrate)
     */
    public function __construct(?string $macKey = null, ?RulesState $initial = null)
    {
        $this->macKey = $macKey;
        $this->state = $initial?->clone() ?? RulesState::empty();
    }

    /**
     * Replace the session MAC key after a new ASL handshake.
     *
     * @param string|null $macKey Raw 32-byte key, or null to clear
     */
    public function setMacKey(?string $macKey): void
    {
        $this->macKey = $macKey;
    }

    /**
     * Current session MAC key (null before handshake).
     */
    public function getMacKey(): ?string
    {
        return $this->macKey;
    }

    /**
     * Current rules state (version, lease, flags, segments).
     */
    public function getState(): RulesState
    {
        return $this->state;
    }

    /**
     * Whether local evaluation is allowed right now.
     *
     * Requires `ready === true` and a non-expired lease (`expiresAt` in the future).
     *
     * @param int|null $nowMs Override “now” in epoch ms (tests / QA clock sync)
     */
    public function isReady(?int $nowMs = null): bool
    {
        if (!$this->state->ready || $this->state->expiresAt <= 0) {
            return false;
        }

        return !SignPayload::isExpired($this->state->expiresAt, $nowMs);
    }

    /**
     * Force fail-closed locally (e.g. after detecting an expired lease offline).
     *
     * Next refresh should request a full config (`needsFullConfig = true`).
     */
    public function markExpired(): void
    {
        $next = $this->state->clone();
        $next->ready = false;
        $next->needsFullConfig = true;
        $this->state = $next;
    }

    /**
     * Look up a compiled flag document by key.
     *
     * @param string $key Flag key
     * @return array<string, mixed>|null SdkFlagConfig array, or null if unknown
     */
    public function getFlag(string $key): ?array
    {
        return $this->state->flags[$key] ?? null;
    }

    /**
     * Export state for {@see \Flagmint\Cache\CacheAdapter} persistence.
     */
    public function toSnapshot(): RulesSnapshot
    {
        return $this->state->toSnapshot();
    }

    /**
     * Replace in-memory state from a cached snapshot (boot hydrate).
     *
     * @param RulesSnapshot $snapshot Previously saved rules
     */
    public function hydrateFromSnapshot(RulesSnapshot $snapshot): void
    {
        $this->state = RulesState::fromSnapshot($snapshot);
    }

    /**
     * Verify HMAC signature then reduce a config-sync payload into state.
     *
     * @param array<string, mixed> $payload Signed `lease` | `fullConfig` | `delta` | `deltas`
     * @param int|null $nowMs Optional clock override (epoch ms)
     * @return array{ok: bool, reason?: string} `reason` is one of
     *     `bad_signature` | `expired` | `stale` | `version_gap` | `invalid_payload`
     */
    public function apply(array $payload, ?int $nowMs = null): array
    {
        if ($this->macKey === null || $this->macKey === '') {
            return ['ok' => false, 'reason' => 'bad_signature'];
        }

        if (!SignPayload::verify($payload, $this->macKey)) {
            return ['ok' => false, 'reason' => 'bad_signature'];
        }

        return $this->reduce($payload, $nowMs);
    }

    /**
     * Apply an already-trusted payload (signature checked by {@see apply()}, or
     * unsigned fixtures in unit tests).
     *
     * @param array<string, mixed> $action Config-sync action with a `type` field
     * @param int|null $nowMs Optional clock override (epoch ms)
     * @return array{ok: bool, reason?: string}
     */
    public function reduce(array $action, ?int $nowMs = null): array
    {
        $now = $nowMs ?? (int) (microtime(true) * 1000);
        $expiresAt = isset($action['expiresAt']) ? (int) $action['expiresAt'] : 0;
        if ($expiresAt > 0 && SignPayload::isExpired($expiresAt, $now)) {
            $next = $this->state->clone();
            $next->ready = false;
            $this->state = $next;

            return ['ok' => false, 'reason' => 'expired'];
        }

        $type = $action['type'] ?? null;
        return match ($type) {
            'lease' => $this->applyLease($action),
            'fullConfig' => $this->applyFullConfig($action),
            'delta' => $this->applyDelta($action),
            'deltas' => $this->applyDeltas($action, $now),
            default => ['ok' => false, 'reason' => 'invalid_payload'],
        };
    }

    /**
     * @param array<string, mixed> $action
     * @return array{ok: bool, reason?: string}
     */
    private function applyLease(array $action): array
    {
        $leaseVersion = (int) ($action['version'] ?? 0);
        $versionMismatch = $leaseVersion !== $this->state->version;
        $next = $this->state->clone();
        $next->expiresAt = (int) ($action['expiresAt'] ?? $next->expiresAt);
        $next->ready = true;
        if (count($next->flags) === 0 || $versionMismatch) {
            $next->needsFullConfig = true;
        }
        $this->state = $next;

        return ['ok' => true];
    }

    /**
     * @param array<string, mixed> $action
     * @return array{ok: bool}
     */
    private function applyFullConfig(array $action): array
    {
        $flags = [];
        foreach ($action['flags'] ?? [] as $flag) {
            if (is_array($flag) && isset($flag['key']) && is_string($flag['key'])) {
                $flags[$flag['key']] = $flag;
            }
        }
        $segments = is_array($action['segments'] ?? null) ? $action['segments'] : [];

        $this->state = new RulesState(
            version: (int) ($action['version'] ?? 0),
            expiresAt: (int) ($action['expiresAt'] ?? 0),
            flags: $flags,
            segments: $segments,
            ready: true,
            needsFullConfig: false,
        );

        return ['ok' => true];
    }

    /**
     * @param array<string, mixed> $action
     * @return array{ok: bool, reason?: string}
     */
    private function applyDelta(array $action): array
    {
        $toVersion = (int) ($action['toVersion'] ?? 0);
        $fromVersion = (int) ($action['fromVersion'] ?? -1);

        if ($toVersion < $this->state->version) {
            return ['ok' => false, 'reason' => 'stale'];
        }
        if ($fromVersion !== $this->state->version) {
            $next = $this->state->clone();
            $next->needsFullConfig = true;
            $this->state = $next;

            return ['ok' => false, 'reason' => 'version_gap'];
        }

        $flags = $this->state->flags;
        foreach ($action['deletes'] ?? [] as $key) {
            if (is_string($key)) {
                unset($flags[$key]);
            }
        }
        foreach ($action['upserts'] ?? [] as $flag) {
            if (is_array($flag) && isset($flag['key']) && is_string($flag['key'])) {
                $flags[$flag['key']] = $flag;
            }
        }
        $segments = $this->state->segments;
        foreach ($action['segments'] ?? [] as $id => $segment) {
            if (is_string($id) && is_array($segment)) {
                $segments[$id] = $segment;
            }
        }

        $this->state = new RulesState(
            version: $toVersion,
            expiresAt: isset($action['expiresAt']) ? (int) $action['expiresAt'] : $this->state->expiresAt,
            flags: $flags,
            segments: $segments,
            ready: true,
            needsFullConfig: false,
        );

        return ['ok' => true];
    }

    /**
     * @param array<string, mixed> $action
     * @param int $now
     * @return array{ok: bool, reason?: string}
     */
    private function applyDeltas(array $action, int $now): array
    {
        $toVersion = (int) ($action['toVersion'] ?? 0);
        $fromVersion = (int) ($action['fromVersion'] ?? -1);

        if ($toVersion < $this->state->version) {
            return ['ok' => false, 'reason' => 'stale'];
        }
        if ($fromVersion !== $this->state->version) {
            $next = $this->state->clone();
            $next->needsFullConfig = true;
            $this->state = $next;

            return ['ok' => false, 'reason' => 'version_gap'];
        }

        $envelopeExpires = (int) ($action['expiresAt'] ?? $this->state->expiresAt);
        foreach ($action['items'] ?? [] as $step) {
            if (!is_array($step)) {
                continue;
            }
            $step['type'] = 'delta';
            $step['expiresAt'] = $envelopeExpires;
            $result = $this->reduce($step, $now);
            if (!($result['ok'] ?? false)) {
                return $result;
            }
        }

        if ($this->state->version !== $toVersion) {
            $next = $this->state->clone();
            $next->needsFullConfig = true;
            $this->state = $next;

            return ['ok' => false, 'reason' => 'version_gap'];
        }

        $next = $this->state->clone();
        $next->expiresAt = $envelopeExpires;
        $next->ready = true;
        $next->needsFullConfig = false;
        $this->state = $next;

        return ['ok' => true];
    }
}
