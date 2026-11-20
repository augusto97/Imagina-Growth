<?php

declare(strict_types=1);

namespace App\Domain\Services\Leads\Qualification;

use App\Domain\Intelligence\Ai\Support\NumberValidator;
use App\Domain\Services\Leads\Brief\LeadBrief;
use App\Domain\Services\Leads\Models\Lead;
use App\Domain\Services\Leads\Models\LeadQualificationSet;
use App\Domain\Services\Leads\Support\Pipelines;

/**
 * Las reglas de la calificación en el chat (M18, auditoría §5.1 función 3), sin
 * efectos: qué juego de preguntas aplica, qué falta, si una respuesta vale, el puntaje,
 * si quedó descalificado y a dónde se deriva.
 *
 * **La IA no inventa respuestas**, igual que la ficha automática (LeadBrief): cada
 * respuesta guarda la frase del cliente de la que salió y solo vale si esa frase está,
 * palabra por palabra (sin tildes ni mayúsculas), en lo que escribió el cliente. Un
 * número además debe estar en esa frase; un texto libre, también.
 *
 * Estado en `leads.qualification` (cifrado):
 * `{set_id, set_name, answers: {key: {value, option?, quote, points, disqualifies, by, at}}, score, disqualified, complete, route?}`.
 */
final class Qualification
{
    /** Tope de los puntos de la calificación en el puntaje del lead (razón `qualification`). */
    public const SCORE_CAP = 50;

    /**
     * El juego que aplica: el guardado en el lead si sigue activo; si no, el primero activo
     * cuyo pipeline y servicio coinciden (los que filtran por pipeline o servicio van antes
     * que los generales). `service` es lo que pidió el cliente si todavía no hay lead.
     */
    public static function setFor(?Lead $lead, ?string $service = null): ?LeadQualificationSet
    {
        $current = is_string($lead?->qualification['set_id'] ?? null) ? $lead->qualification['set_id'] : null;
        if ($current !== null) {
            $set = LeadQualificationSet::query()->where('active', true)->find($current);
            if ($set !== null) {
                return $set;
            }
        }
        $pipelineId = $lead !== null ? Pipelines::for($lead)->id : null;
        $text = trim((string) ($lead->service ?? $service ?? ''));
        $sets = LeadQualificationSet::query()->where('active', true)->orderBy('position')->orderBy('created_at')->get()
            ->sortBy(fn (LeadQualificationSet $s) => ($s->pipeline_id === null ? 2 : 0) + (($s->services ?? []) === [] ? 1 : 0), SORT_REGULAR)->values();
        foreach ($sets as $set) {
            if ($set->pipeline_id !== null && $set->pipeline_id !== $pipelineId) {
                continue;
            }
            $words = (array) ($set->services ?? []);
            if ($words !== [] && ! self::matchesService($text, $words)) {
                continue;
            }

            return $set;
        }

        return null;
    }

    /** @param list<string> $words */
    public static function matchesService(string $service, array $words): bool
    {
        foreach ($words as $word) {
            if ($service !== '' && LeadBrief::containsWords($service, (string) $word)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Las preguntas para el agente: texto, tipo y opciones (sin puntos ni qué descalifica:
     * el modelo no los necesita ni debe contarlos), con lo ya respondido.
     *
     * @param  array<string, mixed>|null  $state
     * @return list<array<string, mixed>>
     */
    public static function forAgent(LeadQualificationSet $set, ?array $state): array
    {
        $answers = self::answersOf($set, $state);
        $out = [];
        foreach ($set->questions as $q) {
            $answer = $answers[$q['key']] ?? null;
            $out[] = array_filter([
                'key' => $q['key'],
                'question' => $q['text'],
                'type' => $q['type'],
                'required' => (bool) $q['required'],
                'options' => isset($q['options']) ? array_map(fn (array $o) => ['key' => $o['key'], 'label' => $o['label']], $q['options']) : null,
                'answered' => $answer !== null,
                'answer' => $answer['value'] ?? null,
            ], fn ($v) => $v !== null);
        }

        return $out;
    }

    /**
     * Revisa una respuesta contra la pregunta y lo que escribió el cliente.
     *
     * @param  array<string, mixed>  $question
     * @param  list<string>  $customerTexts  mensajes del cliente, del más viejo al más nuevo
     * @return array{ok: true, entry: array<string, mixed>}|array{ok: false, error: string}
     */
    public static function check(array $question, string $answer, ?string $quote, array $customerTexts): array
    {
        $answer = trim($answer);
        $quote = trim((string) $quote);
        if ($answer === '') {
            return ['ok' => false, 'error' => 'Falta la respuesta.'];
        }
        $grounded = self::groundQuote($quote, $customerTexts);

        switch ($question['type']) {
            case 'choice':
            case 'yes_no':
                $option = self::option($question, $answer);
                if ($option === null) {
                    return ['ok' => false, 'error' => 'Esa respuesta no es una de las opciones: usa la clave o el texto de una opción.'];
                }
                if ($grounded === null) {
                    return ['ok' => false, 'error' => 'La frase del cliente no está en lo que escribió: copia sus palabras exactas.'];
                }

                return ['ok' => true, 'entry' => ['value' => $option['label'], 'option' => $option['key'], 'quote' => $grounded,
                    'points' => (int) $option['points'], 'disqualifies' => (bool) $option['disqualifies']]];
            case 'number':
                $value = self::numbersIn($answer)[0] ?? null;
                if ($value === null) {
                    return ['ok' => false, 'error' => 'La respuesta debe ser un número.'];
                }
                if ($grounded === null || ! in_array(true, array_map(fn (float $n) => abs($n - $value) < 0.0001, self::numbersIn($grounded)), true)) {
                    return ['ok' => false, 'error' => 'Ese número no está en lo que escribió el cliente: guarda solo lo que dijo.'];
                }
                $min = is_numeric($question['min'] ?? null) ? (float) $question['min'] : null;
                $max = is_numeric($question['max'] ?? null) ? (float) $question['max'] : null;
                $inRange = ($min === null || $value >= $min) && ($max === null || $value <= $max);

                return ['ok' => true, 'entry' => ['value' => self::numberText($value), 'quote' => $grounded,
                    'points' => $inRange ? (int) ($question['points'] ?? 0) : 0,
                    'disqualifies' => ! $inRange && ($question['out_of_range'] ?? 'none') === 'disqualify']];
            default:
                $ground = LeadBrief::ground(mb_substr($answer, 0, 160), $quote !== '' ? $quote : null, $customerTexts);
                if ($ground === null) {
                    return ['ok' => false, 'error' => 'Esa respuesta no está en lo que escribió el cliente: guarda sus palabras.'];
                }

                return ['ok' => true, 'entry' => ['value' => mb_substr($answer, 0, 160), 'quote' => $ground['quote'], 'points' => (int) ($question['points'] ?? 0), 'disqualifies' => false]];
        }
    }

    /**
     * Puntaje, descalificación y si ya respondió todas las obligatorias.
     *
     * @param  array<string, array<string, mixed>>  $answers
     * @return array{score: int, disqualified: bool, complete: bool, pending: list<string>}
     */
    public static function tally(LeadQualificationSet $set, array $answers): array
    {
        $score = 0;
        $disqualified = false;
        $pending = [];
        foreach ($set->questions as $q) {
            $a = $answers[$q['key']] ?? null;
            if ($a === null) {
                $pending[] = $q['key'];

                continue;
            }
            $score += (int) ($a['points'] ?? 0);
            $disqualified = $disqualified || (bool) ($a['disqualifies'] ?? false);
        }
        $required = array_column(array_filter($set->questions, fn (array $q) => (bool) $q['required']), 'key');

        return ['score' => $score, 'disqualified' => $disqualified, 'complete' => array_intersect($required, $pending) === [], 'pending' => $pending];
    }

    /**
     * La derivación, cuando corresponde: con todas las obligatorias respondidas o al quedar
     * descalificado. La primera regla que se cumple manda; si ninguna, la de `default`.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>|null {action, link?, url?, rule}
     */
    public static function route(LeadQualificationSet $set, array $state): ?array
    {
        if (! ($state['complete'] ?? false) && ! ($state['disqualified'] ?? false)) {
            return null;
        }
        $answers = (array) ($state['answers'] ?? []);
        $score = (int) ($state['score'] ?? 0);
        foreach ((array) ($set->routing['rules'] ?? []) as $i => $rule) {
            $match = match ($rule['when'] ?? null) {
                'disqualified' => (bool) ($state['disqualified'] ?? false),
                'score_gte' => $score >= (int) ($rule['value'] ?? 0),
                'score_lt' => $score < (int) ($rule['value'] ?? 0),
                'answer' => ($answers[(string) ($rule['question'] ?? '')]['option'] ?? null) === ($rule['option'] ?? null),
                default => false,
            };
            if ($match) {
                return self::action($rule) + ['rule' => $i];
            }
        }

        return self::action((array) ($set->routing['default'] ?? ['action' => 'agent'])) + ['rule' => 'default'];
    }

    /**
     * Las respuestas guardadas del juego actual (si el lead cambió de juego, las de otras preguntas no cuentan).
     *
     * @param  array<string, mixed>|null  $state
     * @return array<string, array<string, mixed>>
     */
    public static function answersOf(LeadQualificationSet $set, ?array $state): array
    {
        if ($state === null || ($state['set_id'] ?? null) !== $set->id) {
            return [];
        }
        $keys = array_column($set->questions, 'key');

        return array_intersect_key((array) ($state['answers'] ?? []), array_flip($keys));
    }

    /**
     * La calificación para la SPA: cada pregunta con su respuesta y la frase de origen.
     *
     * @param  array<string, mixed>|null  $state
     * @return array<string, mixed>|null
     */
    public static function present(?array $state): ?array
    {
        if ($state === null || ! is_array($state['answers'] ?? null)) {
            return null;
        }
        $set = is_string($state['set_id'] ?? null) ? LeadQualificationSet::query()->find($state['set_id']) : null;
        $questions = $set !== null ? $set->questions : [];
        $answers = [];
        foreach ($questions as $q) {
            $a = $state['answers'][$q['key']] ?? null;
            $answers[] = [
                'key' => $q['key'], 'question' => $q['text'], 'required' => (bool) $q['required'],
                'value' => is_array($a) ? ($a['value'] ?? null) : null, 'quote' => is_array($a) ? ($a['quote'] ?? null) : null,
                'points' => is_array($a) ? (int) ($a['points'] ?? 0) : null, 'disqualifies' => is_array($a) && (bool) ($a['disqualifies'] ?? false),
                'at' => is_array($a) ? ($a['at'] ?? null) : null,
            ];
        }
        $route = is_array($state['route'] ?? null) ? array_intersect_key($state['route'], array_flip(['action', 'link', 'at'])) : null;

        return [
            'set' => $set !== null ? ['id' => $set->publicId(), 'name' => $set->name] : (is_string($state['set_name'] ?? null) ? ['id' => null, 'name' => $state['set_name']] : null),
            'answers' => $answers,
            'score' => (int) ($state['score'] ?? 0),
            'disqualified' => (bool) ($state['disqualified'] ?? false),
            'complete' => (bool) ($state['complete'] ?? false),
            'route' => $route,
        ];
    }

    /**
     * Números de un texto en formato de Colombia y México («2.500.000», «1,5», «3 millones», «80 mil»).
     *
     * @return list<float>
     */
    public static function numbersIn(string $text): array
    {
        $out = [];
        if (preg_match_all('/(?<![\d.,])(\d{1,3}(?:\.\d{3})+(?:,\d+)?|\d+(?:[.,]\d+)?)(?:\s*(mil\s+millones|millones|mill[oó]n|mil)\b)?/iu', $text, $m, PREG_SET_ORDER) > 0) {
            foreach ($m as $match) {
                [$value] = NumberValidator::parse($match[1]);
                $scale = mb_strtolower($match[2] ?? '');
                $value *= match (true) {
                    str_starts_with($scale, 'mil ') => 1_000_000_000,
                    str_starts_with($scale, 'mill') => 1_000_000,
                    $scale === 'mil' => 1_000,
                    default => 1,
                };
                $out[] = (float) $value;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $rule
     * @return array<string, mixed>
     */
    private static function action(array $rule): array
    {
        $action = in_array($rule['action'] ?? null, QualificationRules::ACTIONS, true) ? (string) $rule['action'] : 'agent';

        return $action === 'web'
            ? ['action' => 'web', 'link' => (string) ($rule['link'] ?? 'catalog'), 'url' => is_string($rule['url'] ?? null) ? $rule['url'] : null]
            : ['action' => $action];
    }

    /**
     * La frase tal como la escribió el cliente (o la del mensaje que la contiene), si está en sus mensajes.
     *
     * @param  list<string>  $customerTexts
     */
    private static function groundQuote(string $quote, array $customerTexts): ?string
    {
        if ($quote === '') {
            return null;
        }
        for ($i = count($customerTexts) - 1; $i >= 0; $i--) {
            if (LeadBrief::containsWords($customerTexts[$i], $quote)) {
                return mb_substr($quote, 0, LeadBrief::QUOTE_MAX);
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $question
     * @return array<string, mixed>|null
     */
    private static function option(array $question, string $answer): ?array
    {
        $norm = LeadBrief::normalize($answer);
        $aliases = ['si' => 'yes', 'sí' => 'yes', 'yes' => 'yes', 'no' => 'no'];
        foreach ((array) ($question['options'] ?? []) as $o) {
            if ($o['key'] === $answer || LeadBrief::normalize((string) $o['label']) === $norm || ($question['type'] === 'yes_no' && ($aliases[$norm] ?? null) === $o['key'])) {
                return $o;
            }
        }

        return null;
    }

    private static function numberText(float $value): string
    {
        $decimals = abs($value - round($value)) < 0.0001 ? 0 : 2;

        return number_format($value, $decimals, ',', '.');
    }
}
