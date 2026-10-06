<?php

declare(strict_types=1);

namespace Flagmint\Events;

/**
 * Coalesces call-site `kind: evaluation` reports within a process/request.
 *
 * Matches JS `evaluationReportQueue`: multiple getFlag hits for the same flag
 * become one event with an incremented `count` before flush.
 */
final class EvaluationReportBuffer
{
    public const MAX_BATCH = 20;

    /** @var array<string, array{variationValue: mixed, userKey: ?string, count: int}> */
    private array $pending = [];

    /**
     * Record one local evaluation for later flush as `kind: evaluation`.
     *
     * Existing flag keys keep coalescing (count / variation / userKey). New flag
     * keys are dropped once {@see MAX_BATCH} distinct keys are pending — callers
     * must flush via shutdown or {@see \Flagmint\FlagmintClient::flushEvents()},
     * never a sync network call from the evaluate path.
     *
     * @param string $flagKey
     * @param mixed $variationValue Served value for variation breakdown
     * @param string|null $userKey Unique-user key from context
     */
    public function record(string $flagKey, mixed $variationValue, ?string $userKey): void
    {
        if ($flagKey === '') {
            return;
        }

        if (isset($this->pending[$flagKey])) {
            $this->pending[$flagKey]['count'] = min(10_000, $this->pending[$flagKey]['count'] + 1);
            $this->pending[$flagKey]['variationValue'] = $variationValue;
            $this->pending[$flagKey]['userKey'] = $userKey;

            return;
        }

        if (count($this->pending) >= self::MAX_BATCH) {
            return;
        }

        $this->pending[$flagKey] = [
            'variationValue' => $variationValue,
            'userKey' => $userKey,
            'count' => 1,
        ];
    }

    /**
     * Whether any evaluation reports are waiting.
     */
    public function isEmpty(): bool
    {
        return $this->pending === [];
    }

    /**
     * How many distinct flag keys are pending.
     */
    public function size(): int
    {
        return count($this->pending);
    }

    /**
     * Drain coalesced reports as API-shaped evaluation events.
     *
     * @return list<array<string, mixed>>
     */
    public function drainAsEvents(): array
    {
        if ($this->pending === []) {
            return [];
        }

        $pending = $this->pending;
        $this->pending = [];
        $ts = gmdate('Y-m-d\TH:i:s.000\Z');
        $events = [];
        foreach ($pending as $flagKey => $entry) {
            $event = [
                'flagKey' => $flagKey,
                'kind' => 'evaluation',
                'variationValue' => $entry['variationValue'],
                'count' => $entry['count'],
                'timestamp' => $ts,
            ];
            if ($entry['userKey'] !== null) {
                $event['userKey'] = $entry['userKey'];
            }
            $events[] = $event;
        }

        return $events;
    }
}
