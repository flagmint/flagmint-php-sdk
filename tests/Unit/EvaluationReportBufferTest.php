<?php

declare(strict_types=1);

namespace Flagmint\Tests\Unit;

use Flagmint\Events\EvaluationReportBuffer;
use PHPUnit\Framework\TestCase;

final class EvaluationReportBufferTest extends TestCase
{
    public function testCoalescesSameFlagAndDrainsAsEvents(): void
    {
        $buffer = new EvaluationReportBuffer();
        $buffer->record('checkout', true, 'u1');
        $buffer->record('checkout', false, 'u2');
        $buffer->record('banner', 'a', null);

        $this->assertSame(2, $buffer->size());
        $events = $buffer->drainAsEvents();
        $this->assertTrue($buffer->isEmpty());
        $this->assertCount(2, $events);

        $byFlag = [];
        foreach ($events as $event) {
            $byFlag[$event['flagKey']] = $event;
        }

        $this->assertSame('evaluation', $byFlag['checkout']['kind']);
        $this->assertSame(2, $byFlag['checkout']['count']);
        $this->assertFalse($byFlag['checkout']['variationValue']);
        $this->assertSame('u2', $byFlag['checkout']['userKey']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', $byFlag['checkout']['timestamp']);

        $this->assertSame(1, $byFlag['banner']['count']);
        $this->assertArrayNotHasKey('userKey', $byFlag['banner']);
    }

    public function testIgnoresEmptyFlagKey(): void
    {
        $buffer = new EvaluationReportBuffer();
        $buffer->record('', true, 'u1');
        $this->assertTrue($buffer->isEmpty());
    }
}
