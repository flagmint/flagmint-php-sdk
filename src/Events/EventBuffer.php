<?php

declare(strict_types=1);

namespace Flagmint\Events;

/**
 * In-memory batch buffer for evaluator application events.
 */
final class EventBuffer
{
    /** @var list<array<string, mixed>> */
    private array $events = [];

    /**
     * @param array<string, mixed> $event
     */
    public function push(array $event): void
    {
        $this->events[] = $event;
    }

    /**
     * Drain and return buffered events.
     *
     * @return list<array<string, mixed>>
     */
    public function drain(): array
    {
        $out = $this->events;
        $this->events = [];

        return $out;
    }

    public function count(): int
    {
        return count($this->events);
    }
}
