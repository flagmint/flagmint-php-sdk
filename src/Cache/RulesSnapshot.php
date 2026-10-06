<?php

declare(strict_types=1);

namespace Flagmint\Cache;

/**
 * Serializable rules blob written by {@see CacheAdapter}.
 *
 * This is the on-disk / Redis shape of {@see \Flagmint\ConfigSync\RulesState}:
 * version bookmark, lease expiry, flag list, and segments. Use it to survive
 * process restarts and to share rules across FPM workers.
 *
 * Prefer constructing via {@see \Flagmint\ConfigSync\RulesState::toSnapshot()}
 * rather than hand-building payloads.
 */
final class RulesSnapshot
{
    /**
     * @param int $version Config version bookmark for delta catch-up (`sinceVersion`)
     * @param int $expiresAt Lease end in epoch **milliseconds** (fail-closed after this)
     * @param list<array<string, mixed>> $flags SdkFlagConfig documents (list form for JSON)
     * @param array<string, array<string, mixed>> $segments Segment id → segment document
     */
    public function __construct(
        public readonly int $version,
        public readonly int $expiresAt,
        public readonly array $flags,
        public readonly array $segments = [],
    ) {
    }

    /**
     * Associative array suitable for `json_encode` / cache drivers.
     *
     * @return array{
     *     version: int,
     *     expiresAt: int,
     *     flags: list<array<string, mixed>>,
     *     segments: array<string, array<string, mixed>>
     * }
     */
    public function toArray(): array
    {
        return [
            'version' => $this->version,
            'expiresAt' => $this->expiresAt,
            'flags' => $this->flags,
            'segments' => $this->segments,
        ];
    }

    /**
     * Hydrate from a decoded array (missing keys become empty defaults).
     *
     * @param array<string, mixed> $data
     * @return self
     */
    public static function fromArray(array $data): self
    {
        return new self(
            version: (int) ($data['version'] ?? 0),
            expiresAt: (int) ($data['expiresAt'] ?? 0),
            flags: array_values($data['flags'] ?? []),
            segments: is_array($data['segments'] ?? null) ? $data['segments'] : [],
        );
    }

    /**
     * JSON-encode for Redis / file adapters.
     *
     * @return string
     * @throws \JsonException When encoding fails
     */
    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR);
    }

    /**
     * Decode a JSON snapshot previously written by {@see toJson()}.
     *
     * @param string $json
     * @return self
     * @throws \JsonException When JSON is invalid
     */
    public static function fromJson(string $json): self
    {
        /** @var array<string, mixed> $data */
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return self::fromArray($data);
    }
}
