<?php

declare(strict_types=1);

namespace Flagmint\Eval;

/**
 * Targeting condition matching (ported from Go evaluate/rules.go).
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
        $attrPresent = array_key_exists($attribute, $attrs);
        $attrVal = $attrPresent ? $attrs[$attribute] : null;

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
