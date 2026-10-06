<?php

declare(strict_types=1);

namespace Flagmint\Tests\Unit;

use Flagmint\Eval\Evaluator;
use PHPUnit\Framework\TestCase;

final class EvaluatorTest extends TestCase
{
    /**
     * @dataProvider goldenEvalFixtures
     * @param array<string, mixed> $case
     */
    public function testGoldenFixtures(array $case): void
    {
        $evaluator = new Evaluator();
        $actual = $evaluator->evaluate($case['flag'], $case['context'], $case['segments'] ?? []);
        $this->assertSame($case['expected'], $actual);
    }

    /**
     * @return \Generator<string, array{0: array<string, mixed>}>
     */
    public static function goldenEvalFixtures(): \Generator
    {
        $dir = dirname(__DIR__) . '/fixtures/eval';
        foreach (glob($dir . '/*.json') ?: [] as $file) {
            /** @var array<string, mixed> $case */
            $case = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            yield basename($file) => [$case];
        }
    }

    public function testMissingTargetingFallsToDefault(): void
    {
        $evaluator = new Evaluator();
        $value = $evaluator->evaluate([
            'key' => 'x',
            'type' => 'string',
            'is_active' => true,
            'default_value' => 'fallback',
            'targeting_rules' => [
                [
                    'id' => 'r1',
                    'kind' => 'custom',
                    'order_index' => 0,
                    'logical_op' => 'and',
                    'conditions' => [
                        ['attribute' => 'plan', 'operator' => 'eq', 'value' => 'enterprise'],
                    ],
                    'variation_id' => 'v1',
                ],
            ],
            'variations' => [['id' => 'v1', 'value' => 'hit']],
            'rollouts' => [],
        ], ['plan' => 'free']);

        $this->assertSame('fallback', $value);
    }
}
