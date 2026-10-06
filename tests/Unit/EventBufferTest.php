<?php

declare(strict_types=1);

namespace Flagmint\Tests\Unit;

use Flagmint\Events\EventBuffer;
use PHPUnit\Framework\TestCase;

final class EventBufferTest extends TestCase
{
    public function testDrainClearsBuffer(): void
    {
        $buffer = new EventBuffer();
        $buffer->push(['kind' => 'custom', 'flagKey' => 'a']);
        $buffer->push(['kind' => 'error', 'flagKey' => 'b']);
        $this->assertSame(2, $buffer->count());
        $events = $buffer->drain();
        $this->assertCount(2, $events);
        $this->assertSame(0, $buffer->count());
    }
}
