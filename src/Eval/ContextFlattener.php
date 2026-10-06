<?php

declare(strict_types=1);

namespace Flagmint\Eval;

/**
 * Normalize evaluation context into the flat attribute map the evaluator expects.
 *
 * Port of FF-EU / JS `flattenContext` + `flattenEvaluationContext` with
 * `addKindLabel=true` (evaluate path):
 *
 * - `kind: user|organization` → `user.key`, `organization.plan`, …
 * - Nested `custom` always becomes `custom.<key>` (never `user.custom`)
 * - `kind: multi` merges only `user` + `organization` (same as FF-EU MultiContext)
 * - Already-flat maps (`user.*` / `organization.*` / `custom.*`) pass through
 * - Nested `{ user, organization, custom }` without `kind` is also supported
 *
 * Targeting conditions resolve attributes via {@see ConditionMatcher} aliases
 * (bare `plan` → `organization.plan` / `user.plan` / `custom.plan`).
 */
final class ContextFlattener
{
    /**
     * Prepare context for {@see Evaluator::evaluate()}.
     *
     * @param array<string, mixed>|null $context Raw context from the app / SDK
     * @return array<string, mixed> Flat attribute map (`user.*`, `organization.*`, `custom.*`)
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
     * Flatten a structured EvaluationContext (`kind` present) with kind labels.
     *
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private static function flattenKindContext(array $context): array
    {
        $kind = $context['kind'] ?? null;
        if ($kind === 'multi') {
            $flat = [];
            if (isset($context['user']) && is_array($context['user'])) {
                $flat = array_merge($flat, self::prefixKind('user', $context['user']));
            }
            if (isset($context['organization']) && is_array($context['organization'])) {
                $flat = array_merge($flat, self::prefixKind('organization', $context['organization']));
            }

            return $flat;
        }

        return self::prefixKind(is_string($kind) ? $kind : 'user', $context);
    }

    /**
     * Nested SDK shape without top-level `kind`.
     *
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private static function flattenNested(array $context): array
    {
        $flat = [];
        if (isset($context['user']) && is_array($context['user'])) {
            $flat = array_merge($flat, self::prefixKind('user', $context['user']));
        }
        if (isset($context['organization']) && is_array($context['organization'])) {
            $flat = array_merge($flat, self::prefixKind('organization', $context['organization']));
        }
        if (isset($context['custom']) && is_array($context['custom'])) {
            $flat = array_merge($flat, self::flattenCustom($context['custom']));
        }
        foreach ($context as $key => $value) {
            if (
                !is_string($key)
                || in_array($key, ['user', 'organization', 'custom', 'kind'], true)
            ) {
                continue;
            }
            // Skip nested objects (entities already handled); keep scalars / lists.
            if (is_array($value) && !array_is_list($value)) {
                continue;
            }
            $flat[$key] = $value;
        }

        return $flat;
    }

    /**
     * Prefix entity attributes with the kind label (FF-EU `prefixKind` + addKindLabel).
     *
     * @param string $kind `user` | `organization`
     * @param array<string, mixed> $obj Entity attributes (may include nested `custom`)
     * @return array<string, mixed>
     */
    private static function prefixKind(string $kind, array $obj): array
    {
        $out = [];
        foreach ($obj as $key => $value) {
            if (!is_string($key) || $key === 'kind') {
                continue;
            }
            // Match FF-EU: custom is never kind-prefixed (always `custom.<key>`).
            if ($key === 'custom' && is_array($value) && !array_is_list($value)) {
                $out = array_merge($out, self::flattenCustom($value));

                continue;
            }
            $out[$kind . '.' . $key] = $value;
        }

        return $out;
    }

    /**
     * Flatten custom attributes to `custom.<key>` (FF-EU `flattenCustom`).
     *
     * @param array<string, mixed> $custom
     * @return array<string, mixed>
     */
    private static function flattenCustom(array $custom): array
    {
        $out = [];
        foreach ($custom as $key => $value) {
            if (is_string($key)) {
                $out['custom.' . $key] = $value;
            }
        }

        return $out;
    }
}
