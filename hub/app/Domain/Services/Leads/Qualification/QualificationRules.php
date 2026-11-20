<?php

declare(strict_types=1);

namespace App\Domain\Services\Leads\Qualification;

use App\Domain\Services\Leads\Models\LeadPipeline;
use Illuminate\Validation\ValidationException;

/**
 * Forma de un juego de preguntas de calificación (M18, auditoría §5.1 función 3):
 * valida lo que guarda el dueño en Leads → Calificación y lo deja normalizado.
 *
 * Preguntas (hasta 8), cada una con `key`, `text`, `type` y `required`:
 * - `choice`: opciones (2 a 8) con `label`, `points` (−50 a 50) y `disqualifies`.
 * - `yes_no`: las opciones fijas `yes` y `no`, cada una con sus puntos y si descalifica.
 * - `number`: rango aceptado (`min`, `max`, abiertos si faltan), `points` si cae dentro y
 *   `out_of_range` = `disqualify` | `none`.
 * - `text`: `points` si el cliente la responde.
 *
 * Derivación: reglas en orden (hasta 10) que se revisan cuando el cliente respondió
 * todas las obligatorias o cuando una respuesta lo descalifica; la primera que se
 * cumple manda, y si ninguna, `default`. Condiciones: `disqualified`, `score_gte`,
 * `score_lt` (con `value`) y `answer` (`question` + `option`). Acciones: `owner`
 * (al dueño), `seller` (a un vendedor con las reglas de asignación de leads), `agent`
 * (sigue la IA) y `web` (un enlace: `catalog`, `quote`, `booking` o `custom`, con `url`
 * cuando hace falta).
 */
final class QualificationRules
{
    public const TYPES = ['choice', 'yes_no', 'number', 'text'];

    public const ACTIONS = ['owner', 'seller', 'agent', 'web'];

    public const LINKS = ['catalog', 'quote', 'booking', 'custom'];

    public const WHEN = ['disqualified', 'score_gte', 'score_lt', 'answer'];

    public const MAX_QUESTIONS = 8;

    public const MAX_OPTIONS = 8;

    public const MAX_RULES = 10;

    public const MAX_POINTS = 50;

    /**
     * @param  array<string, mixed>  $input
     * @return array{name: string, pipeline_id: string|null, services: list<string>, active: bool, questions: list<array<string, mixed>>, routing: array<string, mixed>}
     *
     * @throws ValidationException
     */
    public static function normalize(array $input): array
    {
        $errors = [];
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 80) {
            $errors['name'] = __('Ponle un nombre de hasta 80 caracteres.');
        }
        $pipelineId = null;
        if (is_string($input['pipeline_id'] ?? null) && $input['pipeline_id'] !== '') {
            $pipeline = LeadPipeline::findByPublicId($input['pipeline_id']);
            if ($pipeline === null) {
                $errors['pipeline_id'] = __('Ese pipeline no existe.');
            } else {
                $pipelineId = $pipeline->id;
            }
        }
        $services = [];
        foreach (array_slice((array) ($input['services'] ?? []), 0, 20) as $word) {
            $word = trim(mb_substr((string) $word, 0, 60));
            if ($word !== '' && ! in_array($word, $services, true)) {
                $services[] = $word;
            }
        }

        $questions = [];
        $raw = array_values((array) ($input['questions'] ?? []));
        if ($raw === [] || count($raw) > self::MAX_QUESTIONS) {
            $errors['questions'] = __('Escribe de 1 a :max preguntas.', ['max' => self::MAX_QUESTIONS]);
        }
        $keys = [];
        foreach (array_slice($raw, 0, self::MAX_QUESTIONS) as $i => $q) {
            $q = (array) $q;
            $key = is_string($q['key'] ?? null) && preg_match('/^[a-z0-9_]{1,24}$/', $q['key']) === 1 ? $q['key'] : 'q'.($i + 1);
            while (in_array($key, $keys, true)) {
                $key .= '_'.($i + 1);
            }
            $keys[] = $key;
            [$question, $problem] = self::question($key, $q);
            if ($problem !== null) {
                $errors["questions.{$i}"] = $problem;
            }
            $questions[] = $question;
        }

        [$routing, $problems] = self::routing((array) ($input['routing'] ?? []), $questions);
        $errors += $problems;
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return ['name' => $name, 'pipeline_id' => $pipelineId, 'services' => $services, 'active' => (bool) ($input['active'] ?? true), 'questions' => $questions, 'routing' => $routing];
    }

    /**
     * @param  array<string, mixed>  $q
     * @return array{0: array<string, mixed>, 1: string|null}
     */
    private static function question(string $key, array $q): array
    {
        $text = trim((string) ($q['text'] ?? ''));
        $type = in_array($q['type'] ?? null, self::TYPES, true) ? (string) $q['type'] : 'text';
        $out = ['key' => $key, 'text' => mb_substr($text, 0, 200), 'type' => $type, 'required' => (bool) ($q['required'] ?? true)];
        $problem = mb_strlen($text) < 3 || mb_strlen($text) > 200 ? (string) __('Escribe la pregunta (de 3 a 200 caracteres).') : null;

        if ($type === 'choice' || $type === 'yes_no') {
            $given = array_values((array) ($q['options'] ?? []));
            $options = [];
            if ($type === 'yes_no') {
                $byKey = [];
                foreach ($given as $o) {
                    $byKey[(string) (((array) $o)['key'] ?? '')] = (array) $o;
                }
                foreach (['yes' => (string) __('Sí'), 'no' => (string) __('No')] as $k => $label) {
                    $options[] = ['key' => $k, 'label' => $label, 'points' => self::points($byKey[$k]['points'] ?? 0), 'disqualifies' => (bool) ($byKey[$k]['disqualifies'] ?? false)];
                }
            } else {
                if (count($given) < 2 || count($given) > self::MAX_OPTIONS) {
                    $problem ??= (string) __('Una pregunta de opciones necesita de 2 a :max opciones.', ['max' => self::MAX_OPTIONS]);
                }
                $labels = [];
                foreach (array_slice($given, 0, self::MAX_OPTIONS) as $j => $o) {
                    $o = (array) $o;
                    $label = trim(mb_substr((string) ($o['label'] ?? ''), 0, 60));
                    if ($label === '' || in_array(mb_strtolower($label), $labels, true)) {
                        $problem ??= (string) __('Cada opción necesita un texto distinto.');
                    }
                    $labels[] = mb_strtolower($label);
                    $okey = is_string($o['key'] ?? null) && preg_match('/^[a-z0-9_]{1,24}$/', $o['key']) === 1 ? $o['key'] : 'o'.($j + 1);
                    $options[] = ['key' => $okey, 'label' => $label, 'points' => self::points($o['points'] ?? 0), 'disqualifies' => (bool) ($o['disqualifies'] ?? false)];
                }
                if (count(array_unique(array_column($options, 'key'))) !== count($options)) {
                    $problem ??= (string) __('Cada opción necesita una clave distinta.');
                }
            }
            $out['options'] = $options;
        } elseif ($type === 'number') {
            $min = is_numeric($q['min'] ?? null) ? (float) $q['min'] : null;
            $max = is_numeric($q['max'] ?? null) ? (float) $q['max'] : null;
            if ($min !== null && $max !== null && $min > $max) {
                $problem ??= (string) __('El mínimo no puede ser mayor que el máximo.');
            }
            $out += ['min' => $min, 'max' => $max, 'points' => self::points($q['points'] ?? 0),
                'out_of_range' => ($q['out_of_range'] ?? 'none') === 'disqualify' ? 'disqualify' : 'none'];
        } else {
            $out['points'] = self::points($q['points'] ?? 0);
        }

        return [$out, $problem];
    }

    /**
     * @param  array<string, mixed>  $routing
     * @param  list<array<string, mixed>>  $questions
     * @return array{0: array{rules: list<array<string, mixed>>, default: array<string, mixed>}, 1: array<string, string>}
     */
    private static function routing(array $routing, array $questions): array
    {
        $errors = [];
        $rules = [];
        $raw = array_values((array) ($routing['rules'] ?? []));
        if (count($raw) > self::MAX_RULES) {
            $errors['routing.rules'] = __('Hasta :max reglas de derivación.', ['max' => self::MAX_RULES]);
        }
        $byKey = [];
        foreach ($questions as $q) {
            $byKey[$q['key']] = $q;
        }
        foreach (array_slice($raw, 0, self::MAX_RULES) as $i => $r) {
            $r = (array) $r;
            $when = in_array($r['when'] ?? null, self::WHEN, true) ? (string) $r['when'] : null;
            if ($when === null) {
                $errors["routing.rules.{$i}"] = __('Elige cuándo aplica la regla.');

                continue;
            }
            $rule = ['when' => $when];
            if ($when === 'score_gte' || $when === 'score_lt') {
                if (! is_numeric($r['value'] ?? null) || abs((int) $r['value']) > 400) {
                    $errors["routing.rules.{$i}"] = __('Indica el puntaje de la regla.');
                }
                $rule['value'] = (int) ($r['value'] ?? 0);
            } elseif ($when === 'answer') {
                $question = $byKey[(string) ($r['question'] ?? '')] ?? null;
                $options = $question !== null ? array_column((array) ($question['options'] ?? []), 'key') : [];
                if ($question === null || ! in_array($r['option'] ?? null, $options, true)) {
                    $errors["routing.rules.{$i}"] = __('Elige la pregunta de opciones y la respuesta de la regla.');
                }
                $rule['question'] = (string) ($r['question'] ?? '');
                $rule['option'] = (string) ($r['option'] ?? '');
            }
            [$action, $problem] = self::action($r);
            if ($problem !== null) {
                $errors["routing.rules.{$i}"] ??= $problem;
            }
            $rules[] = $rule + $action;
        }
        [$default, $problem] = self::action((array) ($routing['default'] ?? ['action' => 'agent']));
        if ($problem !== null) {
            $errors['routing.default'] = $problem;
        }

        return [['rules' => $rules, 'default' => $default], $errors];
    }

    /**
     * @param  array<string, mixed>  $r
     * @return array{0: array<string, mixed>, 1: string|null}
     */
    private static function action(array $r): array
    {
        $action = in_array($r['action'] ?? null, self::ACTIONS, true) ? (string) $r['action'] : null;
        if ($action === null) {
            return [['action' => 'agent'], __('Elige a dónde se deriva: al dueño, a un vendedor, seguir con la IA o a la web.')];
        }
        if ($action !== 'web') {
            return [['action' => $action], null];
        }
        $link = in_array($r['link'] ?? null, self::LINKS, true) ? (string) $r['link'] : 'catalog';
        $url = is_string($r['url'] ?? null) && trim($r['url']) !== '' ? trim($r['url']) : null;
        if ($url !== null && (mb_strlen($url) > 300 || filter_var($url, FILTER_VALIDATE_URL) === false || ! in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['https', 'http'], true))) {
            return [['action' => 'web', 'link' => $link, 'url' => null], __('Escribe una dirección web completa (https://…).')];
        }
        if ($url === null && in_array($link, ['quote', 'custom'], true)) {
            return [['action' => 'web', 'link' => $link, 'url' => null], __('Escribe la dirección de la página a la que se envía al cliente.')];
        }

        return [['action' => 'web', 'link' => $link, 'url' => $url], null];
    }

    private static function points(mixed $value): int
    {
        return is_numeric($value) ? max(-self::MAX_POINTS, min(self::MAX_POINTS, (int) $value)) : 0;
    }
}
