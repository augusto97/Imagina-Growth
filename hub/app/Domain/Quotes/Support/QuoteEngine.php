<?php

declare(strict_types=1);

namespace App\Domain\Quotes\Support;

use App\Domain\Quotes\Models\QuoteCalculator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * El cálculo del cotizador (M18), siempre en el servidor.
 *
 * Definición (`quote_calculators.definition`):
 *   base_price · minimum · range_pct (±%) · round_to · currency · intro y note (públicas) · internal_notes
 *   fields: [{key, label, type: number|select|checkbox, required, help, icon?,
 *             number:   unit, min, max, step, default, unit_price
 *             select:   options [{value, label, price, multiplier, icon?, description?}]
 *             checkbox: options [{value, label, price, multiplier, icon?, description?}] (varias a la vez)}]
 *   packages (kind package): [{key, label, description, price, icon?}]
 *
 * `icon` es un emoji (hasta 8 caracteres) que el cotizador público muestra en la tarjeta de la opción; las claves
 * opcionales solo se guardan si traen algo.
 *
 * Total = (base + paquete + Σ número × precio por unidad + Σ precios de las opciones)
 *         × Π multiplicadores de las opciones elegidas; nunca menos que el mínimo.
 * El rango es ese total ± range_pct, redondeado a `round_to`.
 *
 * «Por área» es el mismo motor con un campo numérico principal con precio por
 * unidad (m², metros lineales, horas): sirve para pintura, pisos, limpieza o
 * jardinería sin una calculadora propia de cada oficio.
 */
final class QuoteEngine
{
    public const TYPES = ['number', 'select', 'checkbox'];

    /**
     * Valida y deja limpia una definición del constructor. Con `$draft` (la propuesta del asistente con IA, que la
     * persona revisa antes de guardar) no exige todavía el precio por unidad del cotizador por área: ese precio lo
     * pone la persona.
     *
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public static function normalize(array $definition, string $kind, bool $draft = false): array
    {
        $errors = [];
        $fields = [];
        $keys = [];
        foreach (array_values((array) ($definition['fields'] ?? [])) as $i => $f) {
            $f = (array) $f;
            $key = self::key($f['key'] ?? null) ?? 'campo_'.($i + 1);
            $type = (string) ($f['type'] ?? '');
            if (! in_array($type, self::TYPES, true)) {
                $errors["definition.fields.{$i}.type"] = __('Elige el tipo de la pregunta.');

                continue;
            }
            if (isset($keys[$key])) {
                $key .= '_'.($i + 1);
            }
            $keys[$key] = true;
            $label = trim((string) ($f['label'] ?? ''));
            if ($label === '') {
                $errors["definition.fields.{$i}.label"] = __('Escribe la pregunta.');
            }
            $field = ['key' => $key, 'label' => mb_substr($label, 0, 120), 'type' => $type, 'required' => (bool) ($f['required'] ?? $type !== 'checkbox'),
                'help' => self::text($f['help'] ?? null, 200)] + self::extras($f, false);
            if ($type === 'number') {
                $min = self::num($f['min'] ?? 0) ?? 0.0;
                $max = self::num($f['max'] ?? null);
                if ($max !== null && $max < $min) {
                    $errors["definition.fields.{$i}.max"] = __('El máximo debe ser mayor que el mínimo.');
                }
                $field += ['unit' => self::text($f['unit'] ?? null, 20), 'min' => $min, 'max' => $max, 'step' => max(0.01, self::num($f['step'] ?? 1) ?? 1.0),
                    'default' => self::num($f['default'] ?? null), 'unit_price' => max(0.0, self::num($f['unit_price'] ?? 0) ?? 0.0)];
            } else {
                $options = [];
                foreach (array_values((array) ($f['options'] ?? [])) as $j => $o) {
                    $o = (array) $o;
                    $olabel = trim((string) ($o['label'] ?? ''));
                    if ($olabel === '') {
                        continue;
                    }
                    $multiplier = self::num($o['multiplier'] ?? 1) ?? 1.0;
                    if ($multiplier <= 0 || $multiplier > 100) {
                        $errors["definition.fields.{$i}.options.{$j}.multiplier"] = __('El multiplicador va entre 0,01 y 100.');
                    }
                    $options[] = ['value' => self::key($o['value'] ?? null) ?? 'op_'.($j + 1), 'label' => mb_substr($olabel, 0, 120),
                        'price' => self::num($o['price'] ?? 0) ?? 0.0, 'multiplier' => $multiplier] + self::extras($o, true);
                }
                if ($options === []) {
                    $errors["definition.fields.{$i}.options"] = __('Agrega al menos una opción.');
                }
                $field['options'] = $options;
            }
            $fields[] = $field;
        }
        $packages = [];
        foreach (array_values((array) ($definition['packages'] ?? [])) as $i => $p) {
            $p = (array) $p;
            $label = trim((string) ($p['label'] ?? ''));
            if ($label === '') {
                continue;
            }
            $packages[] = ['key' => self::key($p['key'] ?? null) ?? 'paquete_'.($i + 1), 'label' => mb_substr($label, 0, 120),
                'description' => self::text($p['description'] ?? null, 300), 'price' => max(0.0, self::num($p['price'] ?? 0) ?? 0.0)] + self::extras($p, false);
        }
        if ($kind === 'package' && $packages === []) {
            $errors['definition.packages'] = __('Agrega al menos un paquete.');
        }
        if ($kind !== 'package' && $fields === []) {
            $errors['definition.fields'] = __('Agrega al menos una pregunta.');
        }
        if ($kind === 'area' && ! collect($fields)->contains(fn (array $f) => $f['type'] === 'number' && ($draft || ($f['unit_price'] ?? 0) > 0))) {
            $errors['definition.fields'] = __('El cotizador por área necesita una pregunta de número con precio por unidad (por ejemplo, metros cuadrados).');
        }
        $range = self::num($definition['range_pct'] ?? 10) ?? 10.0;
        if ($range < 0 || $range > 90) {
            $errors['definition.range_pct'] = __('El rango va de 0 % a 90 %.');
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'currency' => preg_match('/^[A-Z]{3}$/', (string) ($definition['currency'] ?? '')) ? (string) $definition['currency'] : null,
            'base_price' => max(0.0, self::num($definition['base_price'] ?? 0) ?? 0.0),
            'minimum' => max(0.0, self::num($definition['minimum'] ?? 0) ?? 0.0),
            'range_pct' => $range,
            'round_to' => max(0.0, self::num($definition['round_to'] ?? 0) ?? 0.0),
            'intro' => self::text($definition['intro'] ?? null, 200),
            'note' => self::text($definition['note'] ?? null, 400),
            'internal_notes' => self::text($definition['internal_notes'] ?? null, 2000),
            'fields' => $fields,
            'packages' => $packages,
        ];
    }

    /**
     * El rango estimado para unas respuestas. Valida las respuestas contra la definición.
     *
     * @param  array<string, mixed>  $answers  {campo: número|valor|[valores], package: clave}
     * @return array{min: string, max: string, mid: string, answers: array<string, mixed>, lines: list<array{label: string, amount: string}>}
     *
     * @throws ValidationException
     */
    public static function estimate(QuoteCalculator $calculator, array $answers): array
    {
        $def = (array) $calculator->definition;
        $errors = [];
        $clean = [];
        $total = (float) ($def['base_price'] ?? 0);
        $multiplier = 1.0;
        $lines = [];
        if ($calculator->kind === 'package' || (array) ($def['packages'] ?? []) !== []) {
            $chosen = collect((array) ($def['packages'] ?? []))->firstWhere('key', $answers['package'] ?? null);
            if ($chosen === null && $calculator->kind === 'package') {
                $errors['answers.package'] = __('Elige un paquete.');
            } elseif ($chosen !== null) {
                $clean['package'] = $chosen['key'];
                $total += (float) $chosen['price'];
                $lines[] = ['label' => (string) $chosen['label'], 'amount' => self::money((float) $chosen['price'])];
            }
        }
        foreach ((array) ($def['fields'] ?? []) as $f) {
            $key = (string) $f['key'];
            $value = $answers[$key] ?? null;
            $empty = $value === null || $value === '' || $value === [];
            if ($empty) {
                if ($f['required']) {
                    $errors['answers.'.$key] = __('Responde «:label».', ['label' => $f['label']]);
                }

                continue;
            }
            if ($f['type'] === 'number') {
                $n = self::num($value);
                if ($n === null || $n < (float) $f['min'] || ($f['max'] !== null && $n > (float) $f['max'])) {
                    $errors['answers.'.$key] = $f['max'] !== null
                        ? __('Escribe un número entre :min y :max.', ['min' => self::plain((float) $f['min']), 'max' => self::plain((float) $f['max'])])
                        : __('Escribe un número desde :min.', ['min' => self::plain((float) $f['min'])]);

                    continue;
                }
                $clean[$key] = $n;
                $amount = $n * (float) $f['unit_price'];
                $total += $amount;
                if ($amount > 0) {
                    $lines[] = ['label' => $f['label'].' ('.self::plain($n).($f['unit'] ? ' '.$f['unit'] : '').')', 'amount' => self::money($amount)];
                }

                continue;
            }
            $values = $f['type'] === 'checkbox' ? array_values(array_unique(array_map('strval', (array) $value))) : [(string) (is_array($value) ? '' : $value)];
            $options = collect((array) $f['options'])->keyBy('value');
            $picked = [];
            foreach ($values as $v) {
                $option = $options->get($v);
                if ($option === null) {
                    $errors['answers.'.$key] = __('Elige una opción de la lista.');

                    continue 2;
                }
                $picked[] = $v;
                $total += (float) $option['price'];
                $multiplier *= (float) $option['multiplier'];
                if ((float) $option['price'] > 0) {
                    $lines[] = ['label' => $f['label'].': '.$option['label'], 'amount' => self::money((float) $option['price'])];
                }
            }
            $clean[$key] = $f['type'] === 'checkbox' ? $picked : $picked[0];
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        $mid = max($total * $multiplier, (float) ($def['minimum'] ?? 0));
        $pct = (float) ($def['range_pct'] ?? 0) / 100;
        $round = (float) ($def['round_to'] ?? 0);

        return [
            'min' => self::money(self::round($mid * (1 - $pct), $round, 'floor')),
            'max' => self::money(self::round($mid * (1 + $pct), $round, 'ceil')),
            'mid' => self::money(self::round($mid, $round, 'round')),
            'answers' => $clean,
            'lines' => $lines,
        ];
    }

    /**
     * La definición que ve el visitante: sin notas internas y, si el precio se
     * muestra solo a cambio del contacto, sin precios (se calculan en el hub).
     *
     * @return array<string, mixed>
     */
    public static function publicDefinition(QuoteCalculator $calculator, bool $withPrices): array
    {
        $def = (array) $calculator->definition;
        $strip = fn (array $o) => $withPrices ? $o : array_diff_key($o, ['price' => 1, 'multiplier' => 1, 'unit_price' => 1]);

        return [
            'fields' => array_map(function (array $f) use ($strip) {
                $f = $strip($f);
                if (isset($f['options'])) {
                    $f['options'] = array_map($strip, (array) $f['options']);
                }

                return $f;
            }, (array) ($def['fields'] ?? [])),
            'packages' => array_map($strip, (array) ($def['packages'] ?? [])),
            'intro' => $def['intro'] ?? null,
            'note' => $def['note'] ?? null,
        ] + ($withPrices ? ['base_price' => $def['base_price'] ?? 0, 'minimum' => $def['minimum'] ?? 0, 'range_pct' => $def['range_pct'] ?? 0, 'round_to' => $def['round_to'] ?? 0] : []);
    }

    private static function round(float $value, float $to, string $mode): float
    {
        if ($to <= 0) {
            return round($value, 2);
        }

        return match ($mode) {
            'floor' => floor($value / $to) * $to,
            'ceil' => ceil($value / $to) * $to,
            default => round($value / $to) * $to,
        };
    }

    public static function money(float $value): string
    {
        return number_format(max(0.0, $value), 2, '.', '');
    }

    private static function plain(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, ',', '.'), '0'), ',');
    }

    private static function num(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return is_finite((float) $value) ? (float) $value : null;
        }
        if (is_string($value) && preg_match('/^\s*-?\d{1,12}([.,]\d{1,4})?\s*$/', $value)) {
            return (float) str_replace(',', '.', trim($value));
        }

        return null;
    }

    private static function key(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $key = trim((string) preg_replace('/[^a-z0-9_]+/', '_', strtolower(Str::ascii($value))), '_');

        return $key !== '' ? mb_substr($key, 0, 40) : null;
    }

    /**
     * El emoji de la tarjeta y, en las opciones, una línea que la explica. Solo se guardan si traen algo.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, string>
     */
    private static function extras(array $item, bool $withDescription): array
    {
        $out = [];
        $icon = is_string($item['icon'] ?? null) ? trim((string) preg_replace('/[\x00-\x1F\x7F<>]/u', '', $item['icon'])) : '';
        if ($icon !== '') {
            $out['icon'] = mb_substr($icon, 0, 8);
        }
        $description = $withDescription ? self::text($item['description'] ?? null, 120) : null;
        if ($description !== null) {
            $out['description'] = $description;
        }

        return $out;
    }

    private static function text(mixed $value, int $max): ?string
    {
        return is_string($value) && trim($value) !== '' ? mb_substr(trim($value), 0, $max) : null;
    }
}
