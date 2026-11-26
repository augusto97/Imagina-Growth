<?php

declare(strict_types=1);

namespace App\Domain\Quotes\Support;

use App\Domain\Intelligence\Ai\Contracts\AiGateway;
use App\Domain\Intelligence\Ai\Copilot\Support\CopilotFailed;
use App\Domain\Intelligence\Ai\Data\AiRequest;
use App\Domain\Intelligence\Ai\Data\AiResult;
use App\Domain\Intelligence\Ai\Exceptions\AiUnavailable;
use App\Domain\Workspaces\Models\Workspace;
use Illuminate\Validation\ValidationException;

/**
 * «Descríbelo y la IA lo arma» (M18): el negocio cuenta qué vende y cómo cobra, y la IA propone las preguntas, las
 * opciones y sus emojis. Es solo una propuesta: nunca se guarda, la persona la revisa en el asistente.
 *
 * La IA no inventa números (CLAUDE.md §3.6): un precio (por unidad, de una opción o de un paquete, base o mínimo) solo
 * se queda si está escrito en la descripción («15 mil el metro», «$80.000»); si no, queda en 0 y va en `needs_price`
 * para que la persona lo ponga. Un multiplicador solo se queda si sale de un porcentaje de la descripción («30 % más»
 * → 1,3); si no, vuelve a 1 y también se marca. La definición pasa por `QuoteEngine::normalize` en modo borrador: si
 * no es válida, se le pide a la IA que la corrija una vez y, si sigue mal, se descarta.
 */
final class QuoteAssistant
{
    public const FEATURE = 'copilot';

    public function __construct(private readonly AiGateway $ai) {}

    /**
     * @return array{name: string, kind: string, definition: array<string, mixed>, needs_price: list<string>}
     *
     * @throws AiUnavailable
     * @throws CopilotFailed
     */
    public function propose(Workspace $workspace, string $description): array
    {
        $currency = (string) ($workspace->currency ?: 'COP');
        $request = new AiRequest($workspace->id, self::FEATURE, self::SYSTEM, [['role' => 'user', 'content' => "Moneda: {$currency}\n\nDescripción del negocio:\n".$description]], self::schema());
        $result = $this->call($request);
        [$proposal, $problems] = $this->check((array) $result->json, $description, $currency);
        if ($problems !== []) {
            $result = $this->call($request->followUp((string) json_encode($result->json, JSON_UNESCAPED_UNICODE),
                "La propuesta no es válida:\n- ".implode("\n- ", $problems)."\nCorrígela y devuelve la propuesta completa otra vez."));
            [$proposal, $problems] = $this->check((array) $result->json, $description, $currency);
            if ($problems !== []) {
                throw new CopilotFailed(__('La IA no logró armar un cotizador válido. Cuéntalo con más detalle o empieza con una plantilla.'), ['definition' => $problems]);
            }
        }

        return $proposal;
    }

    private function call(AiRequest $request): AiResult
    {
        $result = $this->ai->generate($request);
        if ($result->refused()) {
            throw new AiUnavailable('refusal', __('El modelo no quiso hacer esta propuesta.'));
        }
        if (! is_array($result->json) || $result->json === []) {
            throw new CopilotFailed(__('La IA no devolvió un cotizador. Intenta de nuevo con una descripción más concreta.'));
        }

        return $result;
    }

    /**
     * Limpia la propuesta (precios y multiplicadores que no salen de la descripción) y la valida.
     *
     * @param  array<string, mixed>  $raw
     * @return array{0: array{name: string, kind: string, definition: array<string, mixed>, needs_price: list<string>}, 1: list<string>}
     */
    public function check(array $raw, string $description, string $currency): array
    {
        ['money' => $money, 'percents' => $percents] = self::numbers($description);
        $needs = [];
        $price = function (mixed $value, string $path) use ($money, &$needs): float {
            $n = is_numeric($value) ? (float) $value : 0.0;
            if ($n <= 0) {
                return 0.0;
            }
            foreach ($money as $m) {
                if (abs($m - $n) < 0.005) {
                    return $n;
                }
            }
            $needs[] = $path;

            return 0.0;
        };
        $multiplier = function (mixed $value, string $path) use ($percents, &$needs): float {
            $m = is_numeric($value) ? (float) $value : 1.0;
            if (abs($m - 1) < 0.0001) {
                return 1.0;
            }
            foreach ($percents as $p) {
                if (abs($m - (1 + $p / 100)) < 0.0001 || abs($m - (1 - $p / 100)) < 0.0001) {
                    return $m;
                }
            }
            $needs[] = $path;

            return 1.0;
        };
        $kind = in_array($raw['kind'] ?? null, ['service', 'area', 'package'], true) ? (string) $raw['kind'] : 'service';
        $fields = [];
        foreach (array_values((array) ($raw['fields'] ?? [])) as $i => $f) {
            $f = (array) $f;
            $field = ['label' => (string) ($f['label'] ?? ''), 'type' => (string) ($f['type'] ?? ''), 'icon' => $f['icon'] ?? null, 'help' => $f['help'] ?? null,
                'required' => ($f['type'] ?? '') !== 'checkbox'];
            if (($f['type'] ?? '') === 'number') {
                $field += ['unit' => $f['unit'] ?? null, 'min' => max(0, (float) ($f['min'] ?? 0)), 'max' => isset($f['max']) && is_numeric($f['max']) ? (float) $f['max'] : null,
                    'step' => (float) ($f['step'] ?? 1) > 0 ? (float) $f['step'] : 1, 'default' => null,
                    'unit_price' => $price($f['unit_price'] ?? 0, "fields.{$i}.unit_price")];
                if ($field['unit_price'] <= 0 && ! in_array("fields.{$i}.unit_price", $needs, true) && $kind === 'area') {
                    $needs[] = "fields.{$i}.unit_price";
                }
            } else {
                $field['options'] = [];
                foreach (array_values((array) ($f['options'] ?? [])) as $j => $o) {
                    $o = (array) $o;
                    $field['options'][] = ['label' => (string) ($o['label'] ?? ''), 'icon' => $o['icon'] ?? null, 'description' => $o['description'] ?? null,
                        'price' => $price($o['price'] ?? 0, "fields.{$i}.options.{$j}.price"),
                        'multiplier' => $multiplier($o['multiplier'] ?? 1, "fields.{$i}.options.{$j}.multiplier")];
                }
            }
            $fields[] = $field;
        }
        $packages = [];
        foreach (array_values((array) ($raw['packages'] ?? [])) as $i => $p) {
            $p = (array) $p;
            $packages[] = ['label' => (string) ($p['label'] ?? ''), 'description' => $p['description'] ?? null, 'icon' => $p['icon'] ?? null,
                'price' => $price($p['price'] ?? 0, "packages.{$i}.price")];
            if ($packages[$i]['price'] <= 0 && ! in_array("packages.{$i}.price", $needs, true)) {
                $needs[] = "packages.{$i}.price";
            }
        }
        $range = 10.0;
        if (is_numeric($raw['range_pct'] ?? null)) {
            foreach ($percents as $p) {
                if (abs($p - (float) $raw['range_pct']) < 0.0001) {
                    $range = $p;
                }
            }
        }
        $draft = [
            'currency' => null, 'intro' => is_string($raw['intro'] ?? null) ? $raw['intro'] : null, 'note' => is_string($raw['note'] ?? null) ? $raw['note'] : null,
            'base_price' => $price($raw['base_price'] ?? 0, 'base_price'), 'minimum' => $price($raw['minimum'] ?? 0, 'minimum'),
            'range_pct' => $range, 'round_to' => QuoteTemplates::roundTo($currency), 'internal_notes' => null,
            'fields' => $fields, 'packages' => $packages,
        ];
        $name = trim((string) ($raw['name'] ?? ''));
        $problems = [];
        if ($name === '') {
            $problems[] = 'name: falta el nombre del cotizador.';
        }
        try {
            $definition = QuoteEngine::normalize($draft, $kind, draft: true);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $key => $messages) {
                $problems[] = $key.': '.implode(' ', $messages);
            }
            $definition = $draft;
        }

        return [['name' => mb_substr($name, 0, 160), 'kind' => $kind, 'definition' => $definition, 'needs_price' => array_values(array_unique($needs))], $problems];
    }

    /**
     * Las cifras de dinero y los porcentajes escritos en la descripción: «15 mil», «15k», «$80.000», «1,5 millones»,
     * «30 %» o «30 por ciento».
     *
     * @return array{money: list<float>, percents: list<float>}
     */
    public static function numbers(string $text): array
    {
        $money = [];
        $percents = [];
        $text = mb_strtolower($text);
        preg_match_all('/(\d{1,3}(?:[.,]\d{3})+|\d+(?:[.,]\d+)?)\s*(%|por\s*ciento|millones|millón|millon|mil|k\b)?/u', $text, $matches, PREG_SET_ORDER);
        foreach ($matches as $m) {
            $raw = $m[1];
            // «80.000», «1.500.000» y «1,500» son miles; «15,5» y «2.5» son decimales.
            $value = preg_match('/^\d{1,3}(?:[.,]\d{3})+$/', $raw) === 1 ? (float) str_replace(['.', ','], '', $raw) : (float) str_replace(',', '.', $raw);
            $suffix = trim($m[2] ?? '');
            if ($suffix === '%' || str_starts_with($suffix, 'por')) {
                $percents[] = $value;

                continue;
            }
            $money[] = match ($suffix) {
                'mil', 'k' => $value * 1000,
                'millones', 'millón', 'millon' => $value * 1_000_000,
                default => $value,
            };
        }

        return ['money' => $money, 'percents' => $percents];
    }

    /** @return array<string, mixed> */
    public static function schema(): array
    {
        $nullableString = ['type' => ['string', 'null']];
        $option = ['type' => 'object', 'additionalProperties' => false, 'required' => ['label', 'icon', 'description', 'price', 'multiplier'], 'properties' => [
            'label' => ['type' => 'string'], 'icon' => $nullableString, 'description' => $nullableString,
            'price' => ['type' => 'number'], 'multiplier' => ['type' => 'number'],
        ]];

        return ['type' => 'object', 'additionalProperties' => false,
            'required' => ['name', 'kind', 'intro', 'note', 'base_price', 'minimum', 'range_pct', 'fields', 'packages'], 'properties' => [
                'name' => ['type' => 'string'], 'kind' => ['type' => 'string', 'enum' => ['service', 'area', 'package']],
                'intro' => $nullableString, 'note' => $nullableString,
                'base_price' => ['type' => 'number'], 'minimum' => ['type' => 'number'], 'range_pct' => ['type' => 'number'],
                'fields' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => false,
                    'required' => ['label', 'type', 'icon', 'help', 'unit', 'unit_price', 'min', 'max', 'step', 'options'], 'properties' => [
                        'label' => ['type' => 'string'], 'type' => ['type' => 'string', 'enum' => ['number', 'select', 'checkbox']],
                        'icon' => $nullableString, 'help' => $nullableString, 'unit' => $nullableString,
                        'unit_price' => ['type' => 'number'], 'min' => ['type' => 'number'], 'max' => ['type' => ['number', 'null']], 'step' => ['type' => 'number'],
                        'options' => ['type' => 'array', 'items' => $option],
                    ]]],
                'packages' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => false,
                    'required' => ['label', 'description', 'icon', 'price'], 'properties' => [
                        'label' => ['type' => 'string'], 'description' => $nullableString, 'icon' => $nullableString, 'price' => ['type' => 'number'],
                    ]]],
            ]];
    }

    private const SYSTEM = <<<'TXT'
        Armas cotizadores (calculadoras de precio) para negocios de servicios en Latinoamérica. La persona describe qué vende
        y cómo cobra; tú devuelves la estructura del cotizador en el esquema pedido, en español neutro y en segunda persona
        («¿Cuántos metros cuadrados vas a pintar?»).

        Tipos (kind):
        - "area": cobra por unidad (m², metros lineales, horas, km, personas). Lleva una pregunta "number" con unit_price.
        - "package": la persona elige un paquete (packages) y puede sumar adicionales con preguntas "checkbox".
        - "service": preguntas "select" (una opción) o "checkbox" (varias) cuyas opciones suman (price) o multiplican el total
          (multiplier), y si hace falta una pregunta "number".

        Reglas:
        - Entre 2 y 4 preguntas, claras y cortas. Cada "select" o "checkbox" con 2 a 5 opciones.
        - Un emoji en "icon" para cada pregunta, opción y paquete, que ayude a reconocerla de un vistazo.
        - "description" de una opción: una línea corta solo si aclara algo; si no, null.
        - "intro": una frase que invite a cotizar. "note": qué incluye o qué se confirma después, solo si la descripción lo dice.
        - NUNCA inventes precios ni porcentajes. Usa solo los valores escritos en la descripción. Si un precio no está escrito,
          pon 0 (la persona lo completará). Si un recargo o descuento no está escrito como porcentaje, deja multiplier en 1.
        - range_pct: el margen ± del rango solo si la descripción lo dice; si no, 10.
        - min, max y step de una pregunta "number" son límites razonables para la unidad (por ejemplo 1 a 5000 m²).
        TXT;
}
