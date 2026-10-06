<?php

declare(strict_types=1);

namespace Flagmint\Eval;

/**
 * In-process flag evaluator (ported from Go `evaluate` / JS `evaluateSdkFlag`).
 *
 * Applies kill-switch, targeting rules (custom + segment), and rollouts against
 * a flattened evaluation context. Used by {@see \Flagmint\FlagmintClient} after rules
 * are loaded — you normally call `$client->bool()` / `getFlag()` instead.
 */
final class Evaluator
{
    /**
     * Evaluate one compiled SdkFlagConfig for the given visitor context.
     *
     * @param array<string, mixed> $flag SdkFlagConfig document (`key`, `type`,
     *     `is_active`, `default_value`, `targeting_rules`, `variations`, `rollouts`, …)
     * @param array<string, mixed>|null $context OpenFeature-style or flat attributes;
     *     see {@see ContextFlattener::prepare()}
     * @param array<string, array<string, mixed>> $segments Segment id → segment used by
     *     `kind: segment` targeting rules
     * @return mixed Coerced variation value for the flag type
     */
    public function evaluate(array $flag, ?array $context, array $segments = []): mixed
    {
        $flat = ContextFlattener::prepare($context);
        $type = (string) ($flag['type'] ?? 'boolean');
        $default = $flag['default_value'] ?? null;
        $isActive = ($flag['is_active'] ?? true) !== false;
        $variationsById = [];
        foreach ($flag['variations'] ?? [] as $variation) {
            if (is_array($variation) && isset($variation['id'])) {
                $variationsById[(string) $variation['id']] = $variation;
            }
        }

        if (!$isActive) {
            $offId = $flag['off_variation_id'] ?? null;
            if (is_string($offId) && isset($variationsById[$offId])) {
                return self::coerce($variationsById[$offId]['value'] ?? $default, $type);
            }

            return self::coerce($default, $type);
        }

        $rules = $flag['targeting_rules'] ?? [];
        if (!is_array($rules)) {
            $rules = [];
        }
        usort($rules, static function ($a, $b): int {
            $ai = is_array($a) ? (int) ($a['order_index'] ?? 0) : 0;
            $bi = is_array($b) ? (int) ($b['order_index'] ?? 0) : 0;

            return $ai <=> $bi;
        });

        if ($rules === []) {
            $rollouts = is_array($flag['rollouts'] ?? null) ? $flag['rollouts'] : [];
            $firstRollout = $rollouts === [] ? null : reset($rollouts);
            if (is_array($firstRollout)) {
                return $this->applyRollout($firstRollout, $flat, $type, $variationsById, $default);
            }

            return $this->onVariationOrDefault($variationsById, $type, $default);
        }

        foreach ($rules as $rule) {
            if (!is_array($rule) || !$this->matchRule($rule, $segments, $flat)) {
                continue;
            }

            $variationId = $rule['variation_id'] ?? null;
            if (is_string($variationId) && isset($variationsById[$variationId])) {
                return self::coerce($variationsById[$variationId]['value'] ?? $default, $type);
            }

            $rolloutId = $rule['rollout_id'] ?? null;
            $rollouts = is_array($flag['rollouts'] ?? null) ? $flag['rollouts'] : [];
            if (is_string($rolloutId) && isset($rollouts[$rolloutId]) && is_array($rollouts[$rolloutId])) {
                return $this->applyRollout($rollouts[$rolloutId], $flat, $type, $variationsById, $default);
            }
        }

        return self::coerce($default, $type);
    }

    /**
     * @param array<string, mixed> $rule
     * @param array<string, array<string, mixed>> $segments
     * @param array<string, mixed> $flat
     */
    private function matchRule(array $rule, array $segments, array $flat): bool
    {
        $kind = (string) ($rule['kind'] ?? '');
        if ($kind === 'segment') {
            $segmentId = (string) ($rule['segment_id'] ?? '');
            $segment = $segments[$segmentId] ?? null;
            if (!is_array($segment)) {
                return false;
            }
            if (!empty($segment['force'])) {
                return true;
            }
            $conditions = is_array($segment['rules'] ?? null) ? $segment['rules'] : [];

            return ConditionMatcher::matchGroup(
                $conditions,
                (string) ($segment['logical_op'] ?? 'and'),
                $flat,
            );
        }

        if ($kind === 'custom') {
            $conditions = is_array($rule['conditions'] ?? null) ? $rule['conditions'] : [];

            return ConditionMatcher::matchGroup(
                $conditions,
                (string) ($rule['logical_op'] ?? 'and'),
                $flat,
            );
        }

        return false;
    }

    /**
     * @param array<string, mixed> $rollout
     * @param array<string, mixed> $flat
     * @param array<string, array<string, mixed>> $variationsById
     */
    private function applyRollout(
        array $rollout,
        array $flat,
        string $type,
        array $variationsById,
        mixed $default,
    ): mixed {
        $strategy = (string) ($rollout['strategy'] ?? 'off');
        $key = $this->stableKey($flat);
        $salt = (string) ($rollout['salt'] ?? '');

        return match ($strategy) {
            'percentage' => $this->percentageRollout($rollout, $key, $salt, $type, $default),
            'variant' => self::coerce(
                $this->variantRollout($rollout, $key, $salt, $variationsById, $default),
                $type,
            ),
            default => self::coerce($default, $type),
        };
    }

    /**
     * @param array<string, mixed> $rollout
     */
    private function percentageRollout(array $rollout, string $key, string $salt, string $type, mixed $default): mixed
    {
        if ($type !== 'boolean' || $key === '') {
            return self::coerce($default, $type);
        }
        $percentage = (float) ($rollout['percentage'] ?? 0);
        if ($percentage >= 100) {
            return true;
        }
        if ($percentage <= 0) {
            return false;
        }

        return Hash::percent($key . $salt) < (int) $percentage;
    }

    /**
     * @param array<string, mixed> $rollout
     * @param array<string, array<string, mixed>> $variationsById
     */
    private function variantRollout(
        array $rollout,
        string $key,
        string $salt,
        array $variationsById,
        mixed $default,
    ): mixed {
        $variants = $rollout['variants'] ?? [];
        if (!is_array($variants) || $variants === [] || $key === '') {
            return $default;
        }
        $bucket = Hash::percent($key . $salt);
        $cursor = 0;
        foreach ($variants as $variant) {
            if (!is_array($variant)) {
                continue;
            }
            $cursor += (int) ($variant['weight'] ?? 0);
            if ($bucket < $cursor) {
                $vid = (string) ($variant['variation_id'] ?? '');
                if (isset($variationsById[$vid])) {
                    return $variationsById[$vid]['value'] ?? $default;
                }
            }
        }

        return $default;
    }

    /**
     * @param array<string, mixed> $flat
     */
    private function stableKey(array $flat): string
    {
        if (isset($flat['kind'], $flat['key'])) {
            return (string) $flat['kind'] . '.' . (string) $flat['key'];
        }
        if (isset($flat['user.key'])) {
            return 'user.' . (string) $flat['user.key'];
        }
        if (isset($flat['user_id'])) {
            return (string) $flat['user_id'];
        }

        return '';
    }

    /**
     * @param array<string, array<string, mixed>> $variationsById
     */
    private function onVariationOrDefault(array $variationsById, string $type, mixed $default): mixed
    {
        if ($type === 'boolean') {
            foreach ($variationsById as $variation) {
                if (($variation['value'] ?? null) === true) {
                    return true;
                }
            }
            if ($variationsById !== []) {
                return false;
            }
        }
        foreach ($variationsById as $variation) {
            return self::coerce($variation['value'] ?? $default, $type);
        }

        return self::coerce($default, $type);
    }

    /**
     * Coerce a raw variation value to the flag's declared type.
     *
     * @param mixed $value
     * @param string $type `boolean` | `string` | `number` | `json`
     * @return mixed
     */
    public static function coerce(mixed $value, string $type): mixed
    {
        return match ($type) {
            'boolean' => (bool) $value,
            'string' => $value === null ? '' : (string) $value,
            'number' => is_numeric($value) ? $value + 0 : 0,
            'json' => $value,
            default => $value,
        };
    }
}
