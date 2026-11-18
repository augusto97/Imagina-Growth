<?php

declare(strict_types=1);

namespace App\Domain\Flows\Graph;

/**
 * Filtros sobre las propiedades de un evento (disparador, «esperar hasta»,
 * meta): `[{prop: "order.payment_method", operator: "eq", value: "cod"}]`. Todos
 * deben cumplirse. `prop` es una ruta con puntos dentro de `properties`.
 */
final class Filters
{
    public const OPERATORS = ['eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'in', 'not_in', 'contains', 'starts_with', 'exists', 'not_exists'];

    private const PATH = '/^[a-z_][a-z0-9_]{0,40}(\.[a-z0-9_]{1,40}){0,4}$/';

    /** @param  array<mixed>  $filters */
    public static function valid(array $filters): bool
    {
        if (count($filters) > 10) {
            return false;
        }
        foreach ($filters as $f) {
            if (! is_array($f) || preg_match(self::PATH, (string) ($f['prop'] ?? '')) !== 1 || ! in_array($f['operator'] ?? null, self::OPERATORS, true)) {
                return false;
            }
            $value = $f['value'] ?? null;
            $op = $f['operator'];
            if (in_array($op, ['in', 'not_in'], true) && (! is_array($value) || $value === [] || count($value) > 50)) {
                return false;
            }
            if (! in_array($op, ['in', 'not_in', 'exists', 'not_exists'], true) && ! is_scalar($value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<mixed>  $filters
     * @param  array<mixed>  $props
     */
    public static function matches(array $filters, array $props): bool
    {
        foreach ($filters as $f) {
            if (! is_array($f) || ! self::one($f, $props)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<mixed>  $f
     * @param  array<mixed>  $props
     */
    private static function one(array $f, array $props): bool
    {
        $found = true;
        $actual = $props;
        foreach (explode('.', (string) ($f['prop'] ?? '')) as $part) {
            if (is_array($actual) && array_key_exists($part, $actual)) {
                $actual = $actual[$part];
            } else {
                $found = false;
                $actual = null;

                break;
            }
        }
        $value = $f['value'] ?? null;
        $num = fn ($v) => is_numeric($v) ? (float) $v : null;

        return match ($f['operator'] ?? '') {
            'exists' => $found && $actual !== null && $actual !== '',
            'not_exists' => ! $found || $actual === null || $actual === '',
            'eq' => $found && is_scalar($actual) && (string) $actual === (string) $value,
            'neq' => ! $found || ! is_scalar($actual) || (string) $actual !== (string) $value,
            'gt' => $num($actual) !== null && $num($value) !== null && $num($actual) > $num($value),
            'gte' => $num($actual) !== null && $num($value) !== null && $num($actual) >= $num($value),
            'lt' => $num($actual) !== null && $num($value) !== null && $num($actual) < $num($value),
            'lte' => $num($actual) !== null && $num($value) !== null && $num($actual) <= $num($value),
            'in' => $found && is_scalar($actual) && in_array((string) $actual, array_map('strval', (array) $value), true),
            'not_in' => ! $found || ! is_scalar($actual) || ! in_array((string) $actual, array_map('strval', (array) $value), true),
            'contains' => $found && (is_array($actual) ? in_array((string) $value, array_map(fn ($v) => is_scalar($v) ? (string) $v : '', $actual), true) : (is_scalar($actual) && str_contains(mb_strtolower((string) $actual), mb_strtolower((string) $value)))),
            'starts_with' => $found && is_scalar($actual) && str_starts_with((string) $actual, (string) $value),
            default => false,
        };
    }
}
