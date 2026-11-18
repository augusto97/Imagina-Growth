<?php

declare(strict_types=1);

namespace App\Domain\Surveys\Actions;

use App\Domain\Surveys\Models\SurveyDefinition;
use App\Domain\Workspaces\Models\Workspace;
use InvalidArgumentException;

/**
 * Encuestas NPS y CSAT (M15, flujo 17): una de cada tipo por espacio de trabajo,
 * en `survey_definitions` (kind `nps` o `csat`). Empiezan pausadas.
 *
 * - `questions`: pregunta, etiqueta del comentario y agradecimiento.
 * - `trigger`: `after` (order.completed o order.paid), `days` de espera, `channels`
 *   en orden de preferencia, las plantillas, `alert_threshold` (NPS ≤ 6 y CSAT ≤ 2
 *   por defecto: detractores e insatisfechos) y `alert_emails` además de los dueños.
 */
final class NpsSurvey
{
    public const KINDS = ['nps', 'csat'];

    public function for(Workspace $workspace, string $kind): SurveyDefinition
    {
        if (! in_array($kind, self::KINDS, true)) {
            throw new InvalidArgumentException("Tipo de encuesta no válido: {$kind}");
        }
        /** @var SurveyDefinition|null $survey */
        $survey = SurveyDefinition::query()->where('workspace_id', $workspace->id)->where('kind', $kind)->orderBy('created_at')->first();

        return $survey ?? SurveyDefinition::query()->create([
            'workspace_id' => $workspace->id,
            'kind' => $kind,
            'status' => 'paused',
            'questions' => self::defaultQuestions($kind, $workspace->name),
            'trigger' => self::defaultTrigger($kind),
        ]);
    }

    /** @return array{question: string, comment_label: string, thanks: string} */
    public static function defaultQuestions(string $kind, string $store): array
    {
        return $kind === 'nps' ? [
            'question' => __('Del 0 al 10, ¿qué tan probable es que recomiendes :store a un amigo o familiar?', ['store' => $store]),
            'comment_label' => __('¿Qué es lo principal que te hizo dar esa nota?'),
            'thanks' => __('¡Gracias! Tu respuesta nos ayuda a mejorar.'),
        ] : [
            'question' => __('¿Qué tan satisfecho quedaste con tu compra?'),
            'comment_label' => __('¿Qué podríamos mejorar?'),
            'thanks' => __('¡Gracias por contarnos!'),
        ];
    }

    /** @return array{after: string, days: int, channels: list<string>, email_template_id: string|null, whatsapp_template_id: string|null, sms_template_id: string|null, alert_threshold: int, alert_emails: list<string>} */
    public static function defaultTrigger(string $kind): array
    {
        return [
            'after' => 'completed',
            'days' => $kind === 'nps' ? 14 : 3,
            'channels' => ['whatsapp', 'email'],
            'email_template_id' => null,
            'whatsapp_template_id' => null,
            'sms_template_id' => null,
            'alert_threshold' => $kind === 'nps' ? 6 : 2,
            'alert_emails' => [],
        ];
    }

    /** @return array{after: string, days: int, channels: list<string>, email_template_id: string|null, whatsapp_template_id: string|null, sms_template_id: string|null, alert_threshold: int, alert_emails: list<string>} */
    public static function trigger(SurveyDefinition $survey): array
    {
        $t = is_array($survey->trigger) ? $survey->trigger : [];
        $d = self::defaultTrigger($survey->kind);
        $channels = array_values(array_filter(array_map('strval', (array) ($t['channels'] ?? $d['channels'])), fn ($c) => in_array($c, ['whatsapp', 'email', 'sms'], true)));
        $max = $survey->kind === 'nps' ? 10 : 5;

        return [
            'after' => in_array($t['after'] ?? null, ['completed', 'paid'], true) ? (string) $t['after'] : 'completed',
            'days' => is_numeric($t['days'] ?? null) ? max(0, min(120, (int) $t['days'])) : $d['days'],
            'channels' => $channels === [] ? $d['channels'] : array_values(array_unique($channels)),
            'email_template_id' => is_string($t['email_template_id'] ?? null) && $t['email_template_id'] !== '' ? $t['email_template_id'] : null,
            'whatsapp_template_id' => is_string($t['whatsapp_template_id'] ?? null) && $t['whatsapp_template_id'] !== '' ? $t['whatsapp_template_id'] : null,
            'sms_template_id' => is_string($t['sms_template_id'] ?? null) && $t['sms_template_id'] !== '' ? $t['sms_template_id'] : null,
            'alert_threshold' => is_numeric($t['alert_threshold'] ?? null) ? max(-1, min($max, (int) $t['alert_threshold'])) : $d['alert_threshold'],
            'alert_emails' => array_values(array_filter(array_map(fn ($e) => trim((string) $e), (array) ($t['alert_emails'] ?? [])), fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL) !== false)),
        ];
    }

    /**
     * Escala de la encuesta: NPS de 0 a 10 y CSAT de 1 a 5.
     *
     * @return array{0: int, 1: int}
     */
    public static function range(string $kind): array
    {
        return $kind === 'nps' ? [0, 10] : [1, 5];
    }

    /** Categoría de una nota (eventos.md, nps.answered). */
    public static function category(string $kind, int $score): string
    {
        if ($kind === 'nps') {
            return $score >= 9 ? 'promoter' : ($score >= 7 ? 'passive' : 'detractor');
        }

        return $score >= 4 ? 'satisfied' : ($score === 3 ? 'neutral' : 'unsatisfied');
    }
}
