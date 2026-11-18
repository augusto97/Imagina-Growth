<?php

declare(strict_types=1);

namespace App\Domain\Quotes\Support;

/**
 * Qué datos pide el cotizador antes de mostrar el precio (`quote_calculators.capture`).
 */
final class QuoteCapture
{
    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            'required' => true,                       // el precio solo a cambio del contacto
            'fields' => ['first_name', 'phone', 'email'],
            'require' => 'phone_or_email',            // phone | email | phone_or_email
            'consent_channels' => ['whatsapp', 'email'],
            'title' => null,                          // «¿A dónde te enviamos la cotización?»
            'cta' => null,                            // «Ver mi cotización»
            'success' => null,                        // texto debajo del precio
            'whatsapp_number' => null,                // botón «Hablar por WhatsApp» con la cotización
        ];
    }

    /**
     * @param  array<string, mixed>|null  $capture
     * @return array<string, mixed>
     */
    public static function for(?array $capture): array
    {
        $out = array_replace(self::defaults(), array_intersect_key((array) $capture, self::defaults()));
        $out['required'] = (bool) $out['required'];
        $out['fields'] = array_values(array_intersect(['first_name', 'last_name', 'phone', 'email'], (array) $out['fields']));
        $out['require'] = in_array($out['require'], ['phone', 'email', 'phone_or_email'], true) ? $out['require'] : 'phone_or_email';
        $out['consent_channels'] = array_values(array_intersect(['whatsapp', 'email', 'sms'], (array) $out['consent_channels']));
        $out['whatsapp_number'] = is_string($out['whatsapp_number']) && preg_match('/^\d{8,15}$/', $out['whatsapp_number']) ? $out['whatsapp_number'] : null;
        foreach (['title', 'cta', 'success'] as $text) {
            $out[$text] = is_string($out[$text]) && trim($out[$text]) !== '' ? mb_substr(trim($out[$text]), 0, 200) : null;
        }

        return $out;
    }
}
