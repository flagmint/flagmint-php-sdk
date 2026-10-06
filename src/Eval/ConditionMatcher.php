<?php

declare(strict_types=1);

namespace Flagmint\Eval;

/**
 * Targeting condition matching (ported from Go evaluate/rules.go).
 *
 * Attribute lookup mirrors JS `getContextAttribute` so dashboard rules with bare
 * names (`plan`) resolve against kind-prefixed multi context (`organization.plan`).
 */
final class ConditionMatcher
{
    /**
     * @param array<string, mixed> $condition
     * @param array<string, mixed> $attrs Flat context
     */
    public static function match(array $condition, array $attrs): bool
    {
        $attribute = (string) ($condition['attribute'] ?? '');
        $operator = (string) ($condition['operator'] ?? '');
        $value = $condition['value'] ?? null;
        $attrVal = self::getContextAttribute($attrs, $attribute);
        $attrPresent = $attrVal !== null || self::attributeExists($attrs, $attribute);

        return match ($operator) {
            'exists' => $attrPresent && $attrVal !== null,
            'not_exists' => !$attrPresent || $attrVal === null,
            'eq' => $attrPresent && self::matchEq($attrVal, $value),
            'neq' => $attrPresent && !self::matchEq($attrVal, $value),
            'contains' => $attrPresent && str_contains(self::toString($attrVal), self::toString($value)),
            'not_contains' => $attrPresent && !str_contains(self::toString($attrVal), self::toString($value)),
            'startsWith' => $attrPresent && str_starts_with(self::toString($attrVal), self::toString($value)),
            'endsWith' => $attrPresent && str_ends_with(self::toString($attrVal), self::toString($value)),
            'gt' => $attrPresent && self::toFloat($attrVal) > self::toFloat($value),
            'lt' => $attrPresent && self::toFloat($attrVal) < self::toFloat($value),
            'in' => $attrPresent && self::inList($attrVal, $value),
            'nin' => !$attrPresent || !self::inList($attrVal, $value),
            default => false,
        };
    }

    /**
     * Resolve a targeting attribute against a flat context (JS `getContextAttribute`).
     *
     * Tries exact key, case-insensitive exact, then bare / `custom.` / `user.` /
     * `organization.` candidates so multi-context rules keep working.
     *
     * @param array<string, mixed> $context Flat attribute map
     * @param string $attribute Rule attribute from the dashboard
     * @return mixed|null Resolved value, or null when absent
     */
    public static function getContextAttribute(array $context, string $attribute): mixed
    {
        if ($attribute === '') {
            return null;
        }

        if (array_key_exists($attribute, $context)) {
            return $context[$attribute];
        }

        $keys = array_keys($context);
        $lower = strtolower($attribute);
        foreach ($keys as $key) {
            if (is_string($key) && strtolower($key) === $lower) {
                return $context[$key];
            }
        }

        $bare = str_contains($attribute, '.')
            ? substr($attribute, (int) strrpos($attribute, '.') + 1)
            : $attribute;

        $candidates = [
            $bare,
            'custom.' . $bare,
            'user.' . $bare,
            'organization.' . $bare,
        ];

        foreach ($candidates as $candidate) {
            if (array_key_exists($candidate, $context)) {
                return $context[$candidate];
            }
            $candidateLower = strtolower($candidate);
            foreach ($keys as $key) {
                if (is_string($key) && strtolower($key) === $candidateLower) {
                    return $context[$key];
                }
            }
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $conditions
     * @param string $logicalOp
     * @param array<string, mixed> $attrs
     */
    public static function matchGroup(array $conditions, string $logicalOp, array $attrs): bool
    {
        if ($conditions === []) {
            return false;
        }

        if (strtolower($logicalOp) === 'or') {
            foreach ($conditions as $condition) {
                if (self::match($condition, $attrs)) {
                    return true;
                }
            }

            return false;
        }

        foreach ($conditions as $condition) {
            if (!self::match($condition, $attrs)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether an attribute resolves (including aliases), even when the value is null.
     *
     * @param array<string, mixed> $context
     * @param string $attribute
     */
    private static function attributeExists(array $context, string $attribute): bool
    {
        if ($attribute === '') {
            return false;
        }
        if (array_key_exists($attribute, $context)) {
            return true;
        }

        $resolved = self::getContextAttribute($context, $attribute);
        if ($resolved !== null) {
            return true;
        }

        // Distinguish "missing" from "present null" for aliased keys.
        $bare = str_contains($attribute, '.')
            ? substr($attribute, (int) strrpos($attribute, '.') + 1)
            : $attribute;
        foreach ([$attribute, $bare, 'custom.' . $bare, 'user.' . $bare, 'organization.' . $bare] as $candidate) {
            if (array_key_exists($candidate, $context)) {
                return true;
            }
            $candidateLower = strtolower($candidate);
            foreach (array_keys($context) as $key) {
                if (is_string($key) && strtolower($key) === $candidateLower) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param mixed $attrVal
     * @param mixed $condVal
     */
    private static function matchEq(mixed $attrVal, mixed $condVal): bool
    {
        if (is_bool($attrVal) && is_string($condVal)) {
            return ($attrVal ? 'true' : 'false') === $condVal;
        }
        if (is_numeric($attrVal) && is_string($condVal) && is_numeric($condVal)) {
            return (float) $attrVal === (float) $condVal;
        }

        return self::toString($attrVal) === self::toString($condVal);
    }

    /**
     * @param mixed $attrVal
     * @param mixed $list
     */
    private static function inList(mixed $attrVal, mixed $list): bool
    {
        if (!is_array($list)) {
            return false;
        }
        $needle = self::toString($attrVal);
        foreach ($list as $item) {
            if (self::toString($item) === $needle) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param mixed $value
     */
    private static function toString(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_scalar($value)) {
            return (string) $value;
        }

        return json_encode($value) ?: '';
    }

    /**
     * @param mixed $value
     */
    private static function toFloat(mixed $value): float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }

        return 0.0;
    }
}
