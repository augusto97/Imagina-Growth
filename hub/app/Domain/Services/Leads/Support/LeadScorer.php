<?php

declare(strict_types=1);

namespace App\Domain\Services\Leads\Support;

use App\Analytics\Ch;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Services\Leads\Models\Lead;
use App\Domain\Services\Leads\Models\LeadSettings;
use App\Domain\Workspaces\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Puntaje de lead (inteligencia.md §4.9). El puntaje es la suma de las razones
 * guardadas en `leads.score_reasons` ([{signal, points, at, detail?, reason?}]),
 * de 0 a 100:
 *
 * - Cada señal de comportamiento suma una sola vez por lead (página de precios,
 *   visita recurrente, formulario, clic en WhatsApp o llamada, correo corporativo,
 *   ciudad del área de servicio), con los puntos vigentes cuando ocurrió.
 * - La IA (opcional) deja una razón `ai` de −20 a +20 con su motivo; una nueva
 *   reemplaza a la anterior.
 * - El decaimiento es −5 por cada semana completa sin actividad desde la última
 *   señal (`decay`, la recalcula `igr:leads:decay` cada noche); una señal nueva lo
 *   reinicia.
 *
 * Umbrales de «tibio» y «caliente» por espacio de trabajo (30 y 60 por defecto).
 */
final class LeadScorer
{
    public const MAX = 100;

    /**
     * Suma una señal si el lead no la tenía. Devuelve true si cambió algo.
     *
     * @param  array<string, mixed>  $detail
     */
    public function signal(Lead $lead, LeadSettings $settings, string $signal, array $detail = []): bool
    {
        $reasons = $lead->score_reasons ?? [];
        foreach ($reasons as $i => $reason) {
            if (($reason['signal'] ?? null) === $signal) {
                if ($detail !== [] && ($reason['detail'] ?? null) !== $detail) {
                    $reasons[$i]['detail'] = $detail; // p. ej. cuántas veces vio precios
                    $lead->score_reasons = $reasons;

                    return true;
                }

                return false;
            }
        }
        $points = (int) ($settings->scoringRules()['points'][$signal] ?? 0);
        if ($points === 0) {
            return false;
        }
        $reasons[] = array_filter(['signal' => $signal, 'points' => $points, 'at' => now()->toIso8601String(), 'detail' => $detail ?: null], fn ($v) => $v !== null);
        $lead->score_reasons = array_values($reasons);
        $lead->last_activity_at = now();

        return true;
    }

    /** Razón de la IA (−máx a +máx) con su motivo; reemplaza la anterior. */
    public function aiReason(Lead $lead, LeadSettings $settings, int $points, string $reason): void
    {
        $max = (int) ($settings->scoringRules()['ai_max_points'] ?? 20);
        $points = max(-$max, min($max, $points));
        $reasons = array_values(array_filter($lead->score_reasons ?? [], fn (array $r) => ($r['signal'] ?? null) !== 'ai'));
        if ($points !== 0 || $reason !== '') {
            $reasons[] = ['signal' => 'ai', 'points' => $points, 'at' => now()->toIso8601String(), 'reason' => mb_substr($reason, 0, 200)];
        }
        $lead->score_reasons = $reasons;
    }

    /** Decaimiento por semanas completas sin actividad. Devuelve true si cambió. */
    public function decay(Lead $lead, LeadSettings $settings, ?CarbonImmutable $now = null): bool
    {
        $now ??= CarbonImmutable::now();
        $perWeek = abs((int) ($settings->scoringRules()['points']['decay_weekly'] ?? 5));
        $since = $lead->last_activity_at ?? $lead->created_at;
        $weeks = $since === null ? 0 : (int) floor(max(0, $since->diffInDays($now)) / 7);
        $points = -$perWeek * $weeks;
        $reasons = $lead->score_reasons ?? [];
        $current = null;
        foreach ($reasons as $r) {
            if (($r['signal'] ?? null) === 'decay') {
                $current = (int) ($r['points'] ?? 0);
            }
        }
        if ($current === $points || ($current === null && $points === 0)) {
            return false;
        }
        $reasons = array_values(array_filter($reasons, fn (array $r) => ($r['signal'] ?? null) !== 'decay'));
        if ($points !== 0) {
            $reasons[] = ['signal' => 'decay', 'points' => $points, 'at' => $now->toIso8601String(), 'detail' => ['weeks' => $weeks]];
        }
        $lead->score_reasons = $reasons;

        return true;
    }

    /**
     * Recalcula puntaje y temperatura desde las razones (sin guardar).
     *
     * @return array{score: int, temperature: string, became_hot: bool}
     */
    public function recompute(Lead $lead, LeadSettings $settings): array
    {
        $before = $lead->temperature;
        $sum = 0;
        foreach ($lead->score_reasons ?? [] as $r) {
            $sum += (int) ($r['points'] ?? 0);
        }
        $score = max(0, min(self::MAX, $sum));
        $temperature = self::temperature($score, $settings);
        $lead->score = $score;
        $lead->temperature = $temperature;

        return ['score' => $score, 'temperature' => $temperature, 'became_hot' => $temperature === 'hot' && $before !== 'hot'];
    }

    public static function temperature(int $score, LeadSettings $settings): string
    {
        $t = $settings->scoringRules()['thresholds'];

        return match (true) {
            $score >= (int) $t['hot'] => 'hot',
            $score >= (int) $t['warm'] => 'warm',
            default => 'cold',
        };
    }

    /**
     * Señales del perfil y de la navegación previa al lead (30 días en ClickHouse):
     * vistas de precios, visita recurrente en 7 días, clics en WhatsApp o llamada,
     * correo corporativo y ciudad del área de servicio. Sin ClickHouse quedan solo
     * las del perfil.
     */
    public function history(Lead $lead, Contact $contact, LeadSettings $settings, Workspace $workspace, ?string $email = null, ?string $city = null): void
    {
        $rules = $settings->scoringRules();
        $email ??= $contact->email;
        if ($email !== null && self::isCorporate($email, (array) $rules['personal_domains'])) {
            $this->signal($lead, $settings, 'corporate_email');
        }
        $city ??= is_string($contact->getAttribute('city')) ? $contact->getAttribute('city') : null;
        if ($city !== null && self::inServiceArea($city, (array) $rules['service_cities'])) {
            $this->signal($lead, $settings, 'service_area', ['city' => mb_substr($city, 0, 80)]);
        }

        $stats = $this->browsing($workspace, $contact->id, (array) $rules['pricing_paths']);
        if ($stats['pricing_views'] > 0) {
            $this->signal($lead, $settings, 'pricing_page', ['views' => $stats['pricing_views']]);
        }
        if ($stats['visit_days_7d'] >= 2) {
            $this->signal($lead, $settings, 'recurring_visit', ['days' => $stats['visit_days_7d']]);
        }
        if ($stats['contact_clicks'] > 0) {
            $this->signal($lead, $settings, 'whatsapp_click', ['clicks' => $stats['contact_clicks']]);
        }
    }

    /**
     * @param  array<mixed>  $paths
     * @return array{pricing_views: int, visit_days_7d: int, contact_clicks: int}
     */
    public function browsing(Workspace $workspace, string $contactId, array $paths): array
    {
        $empty = ['pricing_views' => 0, 'visit_days_7d' => 0, 'contact_clicks' => 0];
        $paths = self::cleanPaths($paths);
        try {
            $row = Ch::forWorkspace($workspace)->select(
                "SELECT countIf(event = 'page.viewed' AND arrayExists(p -> startsWith(lower(path), p), {paths:Array(String)})) AS pricing_views,
                        uniqExactIf(toDate(occurred_at), event = 'page.viewed' AND occurred_at >= {since7:DateTime64(3)}) AS visit_days_7d,
                        countIf(event = 'cta.clicked' AND toString(properties.kind) IN ('whatsapp', 'phone')) AS contact_clicks
                 FROM events
                 WHERE workspace_id = {workspace_id:UInt32} AND event IN ('page.viewed', 'cta.clicked') AND occurred_at >= {since30:DateTime64(3)}
                   AND (contact_id = {contact:String} OR anonymous_id IN (
                        SELECT anonymous_id FROM identity_map WHERE workspace_id = {workspace_id:UInt32} AND contact_id = {contact:String}))",
                [
                    'paths' => '['.implode(',', array_map(fn (string $p) => "'".$p."'", $paths)).']',
                    'since7' => now()->subDays(7)->utc()->format('Y-m-d H:i:s.v'),
                    'since30' => now()->subDays(30)->utc()->format('Y-m-d H:i:s.v'),
                    'contact' => $contactId,
                ],
            )[0] ?? null;
        } catch (Throwable $e) {
            report($e);

            return $empty;
        }

        return $row === null ? $empty : [
            'pricing_views' => (int) ($row['pricing_views'] ?? 0),
            'visit_days_7d' => (int) ($row['visit_days_7d'] ?? 0),
            'contact_clicks' => (int) ($row['contact_clicks'] ?? 0),
        ];
    }

    /** @param array<mixed> $extra */
    public static function isCorporate(string $email, array $extra = []): bool
    {
        $at = strrpos($email, '@');
        if ($at === false) {
            return false;
        }
        $domain = mb_strtolower(trim(substr($email, $at + 1)));
        if ($domain === '' || ! str_contains($domain, '.')) {
            return false;
        }
        $personal = array_merge(LeadDefaults::FREE_EMAIL_DOMAINS, array_map(fn ($d) => mb_strtolower(trim((string) $d)), $extra));

        return ! in_array($domain, $personal, true);
    }

    /** @param array<mixed> $cities */
    public static function inServiceArea(string $city, array $cities): bool
    {
        $norm = fn (string $s) => mb_strtolower(trim(preg_replace('/\s+/', ' ', (string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s)) ?? ''));
        $target = $norm($city);
        foreach ($cities as $c) {
            if (is_string($c) && $target !== '' && $norm($c) === $target) {
                return true;
            }
        }

        return false;
    }

    /** @param array<mixed> $paths */
    public static function isPricingPath(?string $path, array $paths): bool
    {
        if ($path === null || $path === '') {
            return false;
        }
        $path = mb_strtolower($path);
        foreach (self::cleanPaths($paths) as $p) {
            if (str_starts_with($path, $p)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<mixed>  $paths
     * @return list<string>
     */
    public static function cleanPaths(array $paths): array
    {
        $out = [];
        foreach ($paths as $p) {
            if (is_string($p) && preg_match('#^/[A-Za-z0-9/_\-.%]{0,120}$#', $p) === 1) {
                $out[] = mb_strtolower($p);
            }
        }

        return $out === [] ? ['/__ninguna__'] : array_values(array_unique($out));
    }

    /** Copia el puntaje del lead abierto al perfil (contacts.lead_score). */
    public static function mirror(Lead $lead): void
    {
        DB::table('contacts')->where('workspace_id', $lead->workspace_id)->where('id', $lead->contact_id)->update(['lead_score' => min(255, max(0, $lead->score))]);
    }
}
