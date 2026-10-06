<?php

declare(strict_types=1);

namespace Flagmint\Eval;

/**
 * Normalize evaluation context into the flat attribute map the evaluator expects.
 *
 * Accepts three common shapes (same as the JS SDK):
 *
 * 1. OpenFeature-style: `['kind' => 'user', 'key' => 'u1', 'plan' => 'premium']`
 * 2. Already flat: `['user.key' => 'u1', 'user.plan' => 'premium']`
 * 3. Nested SDK: `['user' => ['key' => 'u1'], 'organization' => […]]`
 *
 * Targeting conditions match against the flattened keys.
 */
final class ContextFlattener
{
    /**
     * Prepare context for {@see Evaluator::evaluate()}.
     *
     * @param array<string, mixed>|null $context Raw context from the app / SDK
     * @return array<string, mixed> Flat attribute map (may include `kind`, `key`, `user.*`, …)
     */
    public static function prepare(?array $context): array
    {
        if ($context === null || $context === []) {
            return [];
        }

        $kind = $context['kind'] ?? null;
        if (in_array($kind, ['user', 'organization', 'multi'], true)) {
            return self::flattenKindContext($context);
        }

        foreach (array_keys($context) as $key) {
            if (
                is_string($key)
                && (str_starts_with($key, 'user.')
                    || str_starts_with($key, 'organization.')
                    || str_starts_with($key, 'custom.'))
            ) {
                return $context;
            }
        }

        if (isset($context['user']) || isset($context['organization']) || isset($context['custom'])) {
            return self::flattenNested($context);
        }

        return $context;
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private static function flattenKindContext(array $context): array
    {
        $kind = $context['kind'] ?? null;
        if ($kind === 'multi') {
            $flat = ['kind' => 'multi'];
            foreach (['user', 'organization'] as $entity) {
                if (!isset($context[$entity]) || !is_array($context[$entity])) {
                    continue;
                }
                foreach ($context[$entity] as $attr => $value) {
                    if (is_string($attr)) {
                        $flat[$entity . '.' . $attr] = $value;
                    }
                }
            }

            return $flat;
        }

        $flat = ['kind' => $kind];
        foreach ($context as $attr => $value) {
            if ($attr === 'kind' || !is_string($attr)) {
                continue;
            }
            if (is_array($value) && !array_is_list($value)) {
                foreach ($value as $nestedKey => $nestedVal) {
                    if (is_string($nestedKey)) {
                        $flat[$attr . '.' . $nestedKey] = $nestedVal;
                    }
                }
            } else {
                $flat[$attr] = $value;
                if ($kind !== null && is_string($kind)) {
                    $flat[$kind . '.' . $attr] = $value;
                }
            }
        }

        return $flat;
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private static function flattenNested(array $context): array
    {
        $flat = [];
        foreach (['user', 'organization', 'custom'] as $entity) {
            if (!isset($context[$entity]) || !is_array($context[$entity])) {
                continue;
            }
            foreach ($context[$entity] as $attr => $value) {
                if (is_string($attr)) {
                    $flat[$entity . '.' . $attr] = $value;
                }
            }
        }
        foreach ($context as $key => $value) {
            if (in_array($key, ['user', 'organization', 'custom'], true) || !is_string($key)) {
                continue;
            }
            $flat[$key] = $value;
        }

        return $flat;
    }
}
