<?php

declare(strict_types=1);

namespace App\Domain\Consent\Support;

use App\Domain\Consent\Models\ContactConsent;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Declaración del consentimiento de contactos importados (cumplimiento.md §3.1): canales, de dónde salió, cómo se
 * obtuvo la autorización (la base legal: siempre una autorización previa y expresa, §2) y su fecha. La usan la
 * importación CSV y la del historial del plugin. Sin declaración, los importados solo reciben mensajes
 * transaccionales: nunca se infiere consentimiento.
 *
 * Nunca se le devuelve el consentimiento a quien lo revocó o se dio de baja: una lista vieja no pisa una baja.
 */
final class ConsentDeclaration
{
    /** Cómo se obtuvo la autorización (Ley 1581: previa, expresa e informada). */
    public const LEGAL_BASES = ['online_form', 'checkout_checkbox', 'written_form', 'recorded_call', 'messaging_optin'];

    public const CHANNELS = ['email', 'whatsapp', 'sms'];

    /**
     * @return array{channels: list<string>, source: string, legal_basis: string, date: string}
     *
     * @throws ValidationException
     */
    public static function validate(mixed $input, string $field = 'consent'): array
    {
        $valid = is_array($input) && is_array($input['channels'] ?? null) && $input['channels'] !== []
            && array_diff($input['channels'], self::CHANNELS) === []
            && is_string($input['source'] ?? null) && mb_strlen(trim($input['source'])) >= 10 && mb_strlen(trim($input['source'])) <= 300
            && is_string($input['date'] ?? null) && strtotime($input['date']) !== false && strtotime($input['date']) <= time();
        if (! $valid) {
            throw ValidationException::withMessages([$field => __('Declara los canales, de dónde salió el consentimiento (al menos 10 caracteres) y su fecha.')]);
        }
        if (! in_array($input['legal_basis'] ?? null, self::LEGAL_BASES, true)) {
            throw ValidationException::withMessages([$field.'.legal_basis' => __('Indica cómo se obtuvo la autorización.')]);
        }

        return [
            'channels' => array_values(array_unique(array_map('strval', $input['channels']))),
            'source' => trim($input['source']),
            'legal_basis' => (string) $input['legal_basis'],
            'date' => date('Y-m-d', (int) strtotime($input['date'])),
        ];
    }

    /**
     * Canales en los que el contacto revocó o se dio de baja (no se le vuelve a dar el consentimiento por una lista).
     *
     * @return list<string>
     */
    public static function blockedChannels(string $workspaceId, ?string $contactId, ?string $emailBidx, ?string $phoneBidx): array
    {
        // Un contacto nuevo no tiene revocaciones, pero su correo o teléfono sí puede estar en las bajas.
        $blocked = $contactId === null ? [] : ContactConsent::query()->where('contact_id', $contactId)->where('purpose', 'marketing')->where('status', 'revoked')
            ->pluck('channel')->map(fn ($c) => (string) $c)->all();
        foreach (['email' => $emailBidx, 'whatsapp' => $phoneBidx, 'sms' => $phoneBidx] as $channel => $bidx) {
            if ($bidx !== null && $bidx !== '' && DB::table('suppressions')->where('workspace_id', $workspaceId)->where('channel', $channel)
                ->where('value_bidx', $bidx)->whereIn('reason', ['unsubscribe', 'complaint', 'bounce', 'wa_block', 'manual'])->exists()) {
                $blocked[] = $channel;
            }
        }

        return array_values(array_unique($blocked));
    }
}
