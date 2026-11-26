<?php

declare(strict_types=1);

namespace App\Domain\Flows\Library;

use App\Domain\Strategies\StrategyRegistry;

/**
 * Cómo se presenta la biblioteca de flujos en la galería de «Nueva automatización» (rediseño v2 §11.4, Mensajes):
 * el objetivo de cada flujo, a qué tipo de negocio sirve (`store`, `services` o `both`), las verticales y el lugar en
 * que se recomienda (los de su estrategia, `StrategyRegistry`) y la estrategia que lo usa, para enlazar a ella en lugar
 * de repetirla. Los flujos sin estrategia (poscompra, recordatorio de cita…) solo tienen su objetivo y su audiencia.
 */
final class LibraryCatalog
{
    /** Objetivos de la galería, en el orden en que se muestran. */
    public const GOALS = ['recover_sales', 'sell_again', 'loyalty', 'capture', 'reputation', 'convert_leads', 'appointments'];

    /** @var array<string, array{0: string, 1: string}> clave → [objetivo, audiencia] */
    private const META = [
        'welcome' => ['capture', 'store'],
        'abandoned_cart' => ['recover_sales', 'store'],
        'abandoned_checkout' => ['recover_sales', 'store'],
        'browse_abandonment' => ['recover_sales', 'store'],
        'payment_pending' => ['recover_sales', 'store'],
        'cod_confirm' => ['recover_sales', 'store'],
        'post_purchase' => ['loyalty', 'store'],
        'review_request' => ['reputation', 'store'],
        'second_purchase' => ['sell_again', 'store'],
        'replenishment' => ['sell_again', 'store'],
        'winback' => ['sell_again', 'store'],
        'vip_welcome' => ['loyalty', 'store'],
        'birthday' => ['loyalty', 'store'],
        'back_in_stock' => ['sell_again', 'store'],
        'points_expiring' => ['loyalty', 'store'],
        'referral_invite' => ['loyalty', 'store'],
        'nps' => ['reputation', 'store'],
        'service_lead' => ['convert_leads', 'services'],
        'appointment_reminder' => ['appointments', 'services'],
        'renewal' => ['sell_again', 'services'],
        'lead_nurture' => ['convert_leads', 'services'],
        'quote_followup' => ['convert_leads', 'services'],
        'appointment_confirmation' => ['appointments', 'services'],
        'no_show_recovery' => ['appointments', 'services'],
        'post_service' => ['reputation', 'services'],
        'periodic_service' => ['sell_again', 'both'],
        'event_registration' => ['appointments', 'services'],
        'enrollment_followup' => ['convert_leads', 'services'],
        'property_interest' => ['convert_leads', 'services'],
        'lost_lead_revival' => ['convert_leads', 'services'],
        'new_client_welcome' => ['loyalty', 'services'],
        'services_reactivation' => ['sell_again', 'services'],
        'cross_sell' => ['sell_again', 'store'],
        'delivery_check' => ['reputation', 'store'],
        'first_purchase_anniversary' => ['loyalty', 'store'],
        'ai_reactivate' => ['sell_again', 'both'],
    ];

    public static function goal(string $key): string
    {
        return self::META[$key][0] ?? 'sell_again';
    }

    /** `store`, `services` o `both`. */
    public static function audience(string $key): string
    {
        return self::META[$key][1] ?? 'both';
    }

    /** La estrategia que lo usa (la tarjeta enlaza a ella), o null. */
    public static function strategy(string $key): ?string
    {
        return StrategyRegistry::forFlow($key) ?? (StrategyRegistry::find($key) !== null ? $key : null);
    }

    /**
     * Las verticales a las que sirve según su estrategia (null = todas las de su tipo de negocio).
     *
     * @return list<string>|null
     */
    public static function verticals(string $key): ?array
    {
        $strategy = self::strategy($key);
        $definition = $strategy !== null ? StrategyRegistry::find($strategy) : null;

        return $definition?->verticals;
    }

    /** Su lugar en «Recomendadas para ti» para la vertical (el de su estrategia), o null. */
    public static function rank(string $key, ?string $vertical): ?int
    {
        if ($vertical === null) {
            return null;
        }
        $strategy = self::strategy($key);

        return $strategy !== null ? StrategyRegistry::find($strategy)?->rankFor($vertical) : null;
    }

    /**
     * Los canales que usan sus envíos (WhatsApp, correo o SMS), en el orden en que aparecen.
     *
     * @param  array<string, mixed>|null  $graph
     * @return list<string>
     */
    public static function channels(?array $graph): array
    {
        $out = [];
        foreach ((array) ($graph['nodes'] ?? []) as $node) {
            $type = (string) ($node['type'] ?? '');
            $channels = match ($type) {
                'send_whatsapp' => ['whatsapp'],
                'send_email' => ['email'],
                'send_sms' => ['sms'],
                // El seguimiento con IA y «reactivar» prueban sus canales en orden.
                'lead_followup', 'ai_reactivate' => array_values(array_filter((array) ($node['config']['channels'] ?? []), 'is_string')),
                default => [],
            };
            foreach ($channels as $channel) {
                if (! in_array($channel, $out, true)) {
                    $out[] = $channel;
                }
            }
        }

        return $out;
    }

    /**
     * Cuántos mensajes manda como máximo un recorrido (los pasos de envío, de seguimiento con IA y de reactivar).
     *
     * @param  array<string, mixed>|null  $graph
     */
    public static function messages(?array $graph): int
    {
        return count(array_filter((array) ($graph['nodes'] ?? []), fn ($n) => in_array($n['type'] ?? null, ['send_whatsapp', 'send_email', 'send_sms', 'lead_followup', 'ai_reactivate'], true)));
    }
}
