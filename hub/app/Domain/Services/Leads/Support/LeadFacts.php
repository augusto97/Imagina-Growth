<?php

declare(strict_types=1);

namespace App\Domain\Services\Leads\Support;

use App\Domain\Contacts\Models\Contact;
use App\Domain\Services\Leads\Models\Lead;

/**
 * El texto del aviso al vendedor, armado solo con hechos del lead (nada de IA):
 * «Lead nuevo (puntaje 72): Ana Ruiz, Bogotá, interesada en Asesoría laboral.
 * Vino de Google Ads, vio Precios 3 veces».
 */
final class LeadFacts
{
    public static function channelLabel(?string $channel): ?string
    {
        $key = match ($channel) {
            'paid_google' => 'Google Ads',
            'paid_meta' => 'anuncios de Meta',
            'paid_tiktok' => 'anuncios de TikTok',
            'paid_other' => 'anuncios',
            'ctwa' => 'un anuncio de clic a WhatsApp',
            'organic_search' => 'búsqueda en Google',
            'organic_social' => 'redes sociales',
            'email', 'igr_email' => 'un correo',
            'whatsapp', 'igr_whatsapp' => 'WhatsApp',
            'igr_sms' => 'un SMS',
            'ai_assistants' => 'un asistente de IA',
            'affiliates' => 'un afiliado',
            'referral' => 'otro sitio',
            'direct' => 'visita directa',
            'manual' => 'alta manual',
            null, '' => null,
            default => $channel,
        };
        if ($key === null) {
            return null;
        }
        $label = __($key);

        return is_string($label) ? $label : $key;
    }

    public static function alertText(Lead $lead, ?Contact $contact, string $reason): string
    {
        $title = $reason === 'hot'
            ? __('Lead caliente (puntaje :score)', ['score' => $lead->score])
            : __('Lead nuevo (puntaje :score)', ['score' => $lead->score]);
        $who = array_values(array_filter([
            $contact !== null && $contact->displayName() !== '' ? $contact->displayName() : __('Sin nombre'),
            $contact !== null && is_string($contact->getAttribute('city')) && $contact->getAttribute('city') !== '' ? (string) $contact->getAttribute('city') : null,
            $lead->service !== null ? __('le interesa :service', ['service' => $lead->service]) : null,
        ]));
        $parts = [];
        $channel = self::channelLabel(is_string($lead->source['channel'] ?? null) ? $lead->source['channel'] : null);
        if ($lead->origin === 'whatsapp') {
            $parts[] = __('Escribió por WhatsApp');
        } elseif ($channel !== null) {
            $parts[] = __('Vino de :channel', ['channel' => $channel]);
        }
        $views = self::pricingViews($lead);
        if ($views > 0) {
            $parts[] = trans_choice('{1} vio Precios 1 vez|[2,*] vio Precios :n veces', $views, ['n' => $views]);
        }

        return $title.': '.implode(', ', $who).'.'.($parts !== [] ? ' '.implode(', ', $parts) : '');
    }

    public static function pricingViews(Lead $lead): int
    {
        foreach ($lead->score_reasons ?? [] as $r) {
            if (($r['signal'] ?? null) === 'pricing_page') {
                return (int) ($r['detail']['views'] ?? 1);
            }
        }

        return 0;
    }
}
