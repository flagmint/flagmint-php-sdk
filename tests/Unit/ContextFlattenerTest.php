<?php

declare(strict_types=1);

namespace Flagmint\Tests\Unit;

use Flagmint\Eval\ConditionMatcher;
use Flagmint\Eval\ContextFlattener;
use Flagmint\Eval\Evaluator;
use PHPUnit\Framework\TestCase;

final class ContextFlattenerTest extends TestCase
{
    public function testSingleKindUserPrefixesAndUnwrapsCustom(): void
    {
        $flat = ContextFlattener::prepare([
            'kind' => 'user',
            'key' => 'u1',
            'country' => 'NG',
            'custom' => ['age' => 30, 'tier' => 'gold'],
        ]);

        $this->assertSame([
            'user.key' => 'u1',
            'user.country' => 'NG',
            'custom.age' => 30,
            'custom.tier' => 'gold',
        ], $flat);
        $this->assertArrayNotHasKey('key', $flat);
        $this->assertArrayNotHasKey('user.custom', $flat);
    }

    public function testMultiMergesUserAndOrganizationAndUnwrapsNestedCustom(): void
    {
        $flat = ContextFlattener::prepare([
            'kind' => 'multi',
            'user' => [
                'kind' => 'user',
                'key' => 'u1',
                'custom' => ['age' => 30],
            ],
            'organization' => [
                'kind' => 'organization',
                'key' => 'acme',
                'plan' => 'pro',
            ],
        ]);

        $this->assertSame('u1', $flat['user.key']);
        $this->assertSame('acme', $flat['organization.key']);
        $this->assertSame('pro', $flat['organization.plan']);
        $this->assertSame(30, $flat['custom.age']);
        $this->assertArrayNotHasKey('user.custom', $flat);
        $this->assertArrayNotHasKey('kind', $flat);
    }

    public function testNestedWithoutKindUsesPrefixKind(): void
    {
        $flat = ContextFlattener::prepare([
            'user' => ['key' => 'u1', 'custom' => ['source' => 'web']],
            'organization' => ['key' => 'acme', 'plan' => 'pro'],
            'custom' => ['region' => 'eu'],
        ]);

        $this->assertSame('u1', $flat['user.key']);
        $this->assertSame('web', $flat['custom.source']);
        $this->assertSame('acme', $flat['organization.key']);
        $this->assertSame('pro', $flat['organization.plan']);
        $this->assertSame('eu', $flat['custom.region']);
    }

    public function testAlreadyFlatPassesThrough(): void
    {
        $input = ['user.key' => 'u1', 'organization.plan' => 'pro', 'custom.age' => 1];
        $this->assertSame($input, ContextFlattener::prepare($input));
    }

    public function testBarePlanRuleMatchesOrganizationOnMulti(): void
    {
        $flat = ContextFlattener::prepare([
            'kind' => 'multi',
            'user' => ['key' => 'u1'],
            'organization' => ['key' => 'acme', 'plan' => 'pro'],
        ]);

        $this->assertTrue(ConditionMatcher::match([
            'attribute' => 'plan',
            'operator' => 'eq',
            'value' => 'pro',
        ], $flat));
        $this->assertSame('pro', ConditionMatcher::getContextAttribute($flat, 'plan'));
        $this->assertSame(30, ConditionMatcher::getContextAttribute(
            ContextFlattener::prepare([
                'kind' => 'user',
                'key' => 'u1',
                'custom' => ['age' => 30],
            ]),
            'custom.age',
        ));
    }

    public function testEvaluatorTargetsMultiWithCustomAndOrg(): void
    {
        $evaluator = new Evaluator();
        $flag = [
            'key' => 'org-feature',
            'type' => 'boolean',
            'is_active' => true,
            'default_value' => false,
            'targeting_rules' => [
                [
                    'id' => 'r1',
                    'kind' => 'custom',
                    'order_index' => 0,
                    'logical_op' => 'and',
                    'conditions' => [
                        ['attribute' => 'organization.plan', 'operator' => 'eq', 'value' => 'pro'],
                        ['attribute' => 'custom.age', 'operator' => 'gt', 'value' => 18],
                    ],
                    'variation_id' => 'on',
                ],
            ],
            'variations' => [
                ['id' => 'on', 'value' => true],
                ['id' => 'off', 'value' => false],
            ],
            'rollouts' => [],
        ];

        $value = $evaluator->evaluate($flag, [
            'kind' => 'multi',
            'user' => [
                'kind' => 'user',
                'key' => 'u1',
                'custom' => ['age' => 30],
            ],
            'organization' => [
                'kind' => 'organization',
                'key' => 'acme',
                'plan' => 'pro',
            ],
        ]);

        $this->assertTrue($value);
    }
}
