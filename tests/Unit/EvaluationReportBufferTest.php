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

    public function testStopsAcceptingNewKeysAtMaxBatchButStillCoalescesExisting(): void
    {
        $buffer = new EvaluationReportBuffer();
        for ($i = 0; $i < EvaluationReportBuffer::MAX_BATCH; $i++) {
            $buffer->record('flag-' . $i, true, 'u1');
        }
        $this->assertSame(EvaluationReportBuffer::MAX_BATCH, $buffer->size());

        $buffer->record('flag-overflow', false, 'u2');
        $this->assertSame(EvaluationReportBuffer::MAX_BATCH, $buffer->size());

        $buffer->record('flag-0', false, 'u3');
        $events = $buffer->drainAsEvents();
        $this->assertCount(EvaluationReportBuffer::MAX_BATCH, $events);
        $byFlag = [];
        foreach ($events as $event) {
            $byFlag[$event['flagKey']] = $event;
        }
        $this->assertArrayNotHasKey('flag-overflow', $byFlag);
        $this->assertSame(2, $byFlag['flag-0']['count']);
        $this->assertFalse($byFlag['flag-0']['variationValue']);
        $this->assertSame('u3', $byFlag['flag-0']['userKey']);
    }
}
