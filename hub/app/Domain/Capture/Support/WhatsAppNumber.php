<?php

declare(strict_types=1);

namespace App\Domain\Capture\Support;

use App\Domain\Messaging\Enums\Channel;
use App\Domain\Messaging\Models\ChannelAccount;
use App\Domain\Templates\Support\BusinessProfile;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Phone\Phone;

/**
 * El número de WhatsApp de un chat con atribución: el de la cuenta elegida o la cuenta de WhatsApp por defecto del
 * espacio de trabajo (la que recibe los mensajes en la bandeja y deja atribuir el chat). Sin cuenta conectada queda
 * el WhatsApp del perfil del negocio: el chat se abre, pero no llega al hub ni se atribuye (la API lo advierte).
 * Debe correr dentro del contexto del espacio de trabajo.
 */
final class WhatsAppNumber
{
    public static function account(?string $accountId): ?ChannelAccount
    {
        $query = ChannelAccount::query()->where('channel', Channel::WhatsApp->value)->where('status', 'active');
        if ($accountId !== null) {
            return $query->whereKey($accountId)->first();
        }

        return $query->orderByDesc('is_default')->orderBy('created_at')->first();
    }

    /** Solo los dígitos (como los pide wa.me), o null si no hay número. */
    public static function digits(Workspace $workspace, ?ChannelAccount $account): ?string
    {
        $raw = $account?->identity;
        if (! is_string($raw) || preg_replace('/\D/', '', $raw) === '') {
            $raw = BusinessProfile::for($workspace)['whatsapp'];
        }
        $e164 = Phone::toE164($raw, strtoupper((string) ($workspace->country ?: 'CO')));

        return $e164 !== null ? ltrim($e164, '+') : null;
    }
}
