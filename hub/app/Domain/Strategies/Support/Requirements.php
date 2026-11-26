<?php

declare(strict_types=1);

namespace App\Domain\Strategies\Support;

use App\Domain\Intelligence\Ai\Agent\Models\AgentSettings;
use App\Domain\Reviews\Support\ReviewSettings;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Los requisitos comunes de las estrategias, con su verificación sobre los datos reales. Los propios de un bloque (el
 * canal y las plantillas de WhatsApp de un flujo) los agrega el bloque (StrategyBlock::requirements).
 */
final class Requirements
{
    public const KEYS = [
        'site', 'store', 'history', 'channel', 'email', 'whatsapp', 'inbox_channel', 'ai', 'ad_destination',
        'booking_service', 'quote_calculator', 'qualification_set', 'agent_on', 'google_url', 'wa_number',
    ];

    /** @param array<string, mixed> $options lo que trae la activación (p. ej. el enlace de Google) */
    public static function check(string $key, StrategyContext $ctx, array $options = []): Requirement
    {
        return match ($key) {
            'site' => new Requirement('site', $ctx->hasSite(), true, self::siteLink($ctx)),
            'store' => new Requirement('store', $ctx->hasStore(), true, self::siteLink($ctx)),
            'history' => new Requirement('history', $ctx->historyImported(), true,
                ($id = $ctx->import()['site_id'] ?? $ctx->siteId()) !== null ? 'sitios/st_'.$id : 'sitios?conectar=1'),
            'channel' => new Requirement('channel', $ctx->hasChannel('whatsapp', 'email'), true, 'canales'),
            'email' => new Requirement('email', $ctx->hasChannel('email'), true, 'canales'),
            'whatsapp' => new Requirement('whatsapp', $ctx->hasChannel('whatsapp'), true, 'canales'),
            'inbox_channel' => new Requirement('inbox_channel', $ctx->hasChannel('whatsapp', 'messenger', 'instagram'), true, 'canales'),
            'ai' => new Requirement('ai', $ctx->aiAvailable(), true, 'inteligencia'),
            'ad_destination' => new Requirement('ad_destination', self::count($ctx, 'ad_destinations') > 0, true, 'medicion'),
            'booking_service' => new Requirement('booking_service', self::bookingServices($ctx) > 0, true, 'agenda?tab=servicios'),
            'quote_calculator' => new Requirement('quote_calculator', self::count($ctx, 'quote_calculators', fn ($q) => $q->whereIn('status', ['active', 'paused'])) > 0, true, 'en-el-sitio/cotizador'),
            'qualification_set' => new Requirement('qualification_set', self::count($ctx, 'lead_qualification_sets') > 0, true, 'leads?tab=calificacion'),
            'agent_on' => new Requirement('agent_on', self::agentOn($ctx), true, 'bandeja/agente'),
            'google_url' => new Requirement('google_url', self::googleUrl($ctx, $options) !== null, true, 'resenas?tab=ajustes'),
            'wa_number' => new Requirement('wa_number', self::waNumber($ctx, $options) !== null, true, 'canales'),
            default => throw new InvalidArgumentException("Requisito desconocido: {$key}"),
        };
    }

    /**
     * El enlace de Google Business Profile: el que viene en la activación (https) o el guardado.
     *
     * @param  array<string, mixed>  $options
     */
    public static function googleUrl(StrategyContext $ctx, array $options = []): ?string
    {
        $given = trim((string) ($options['google_review_url'] ?? ''));
        if ($given !== '' && filter_var($given, FILTER_VALIDATE_URL) !== false && str_starts_with(strtolower($given), 'https://') && mb_strlen($given) <= 500) {
            return $given;
        }
        $saved = ReviewSettings::for($ctx->workspace)['google_review_url'];
        if ($saved !== null) {
            return $saved;
        }
        $previous = $ctx->row('google_reviews')?->settings['google_review_url'] ?? null;

        return is_string($previous) && $previous !== '' ? $previous : null;
    }

    /**
     * El número para el botón de WhatsApp: el que viene en la activación o el de la cuenta conectada (solo dígitos).
     *
     * @param  array<string, mixed>  $options
     */
    public static function waNumber(StrategyContext $ctx, array $options = []): ?string
    {
        $given = preg_replace('/\D+/', '', (string) ($options['wa_number'] ?? '')) ?? '';

        return strlen($given) >= 8 && strlen($given) <= 15 ? $given : $ctx->whatsappNumber();
    }

    private static function siteLink(StrategyContext $ctx): string
    {
        return ($pending = $ctx->pendingSiteId()) !== null ? 'sitios/st_'.$pending : 'sitios?conectar=1';
    }

    private static function count(StrategyContext $ctx, string $table, ?callable $where = null): int
    {
        return $ctx->remember('count:'.$table.($where !== null ? ':w' : ''), function () use ($ctx, $table, $where): int {
            $q = DB::table($table)->where('workspace_id', $ctx->id());

            return (int) ($where !== null ? $where($q) : $q)->count();
        });
    }

    public static function bookingServices(StrategyContext $ctx): int
    {
        return self::count($ctx, 'booking_services', fn ($q) => $q->where('active', true));
    }

    public static function agentOn(StrategyContext $ctx): bool
    {
        $settings = self::agent($ctx);

        return $settings->enabled && $settings->default_mode !== 'off';
    }

    public static function agent(StrategyContext $ctx): AgentSettings
    {
        return $ctx->remember('agent_settings', fn () => AgentSettings::current($ctx->id()));
    }
}
