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

    public function testVariantRolloutCoercesToFlagType(): void
    {
        $evaluator = new Evaluator();
        $flag = [
            'key' => 'hero-title',
            'type' => 'string',
            'is_active' => true,
            'default_value' => null,
            'targeting_rules' => [],
            'variations' => [
                ['id' => 'a', 'value' => 42],
                ['id' => 'b', 'value' => 7],
            ],
            'rollouts' => [
                'r1' => [
                    'strategy' => 'variant',
                    'salt' => 's',
                    'variants' => [
                        ['variation_id' => 'a', 'weight' => 100],
                    ],
                ],
            ],
        ];

        $value = $evaluator->evaluate($flag, ['kind' => 'user', 'key' => 'u1']);
        $this->assertSame('42', $value);
        $this->assertIsString($value);

        $numberFlag = $flag;
        $numberFlag['type'] = 'number';
        $numberFlag['default_value'] = null;
        $numberFlag['rollouts'] = [
            'r1' => [
                'strategy' => 'variant',
                'salt' => 's',
                'variants' => [],
            ],
        ];
        $this->assertSame(0, $evaluator->evaluate($numberFlag, ['kind' => 'user', 'key' => 'u1']));
    }
}
