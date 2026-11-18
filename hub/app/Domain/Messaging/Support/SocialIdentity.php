<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Support;

use App\Domain\Contacts\Models\ContactIdentity;
use App\Domain\Messaging\Enums\Channel;
use App\Domain\Messaging\Models\ChannelAccount;
use App\Support\Pii\BlindIndex;

/**
 * Identidad de un contacto en Messenger e Instagram (M11, Fase 5). Meta da un ID
 * por página (PSID) o por cuenta profesional (IGSID): el mismo cliente tiene un ID
 * distinto en cada página, así que la identidad se guarda como
 * `{page_id o ig_user_id}:{psid o igsid}` en `contact_identities`.
 */
final class SocialIdentity
{
    /** @var array<string, string> canal → tipo de identidad */
    public const TYPES = ['messenger' => 'messenger_psid', 'instagram' => 'instagram_igsid'];

    public static function type(Channel $channel): ?string
    {
        return self::TYPES[$channel->value] ?? null;
    }

    public static function value(ChannelAccount $account, string $senderId): string
    {
        return $account->external_id.':'.$senderId;
    }

    /** La identidad del contacto en el canal (en la página de la cuenta si se indica), o null. */
    public static function identityFor(Channel $channel, string $contactId, ?ChannelAccount $account = null): ?string
    {
        $type = self::type($channel);
        if ($type === null) {
            return null;
        }
        $query = ContactIdentity::query()->where('contact_id', $contactId)->where('type', $type);
        if ($account !== null && $account->external_id !== null) {
            $query->where('value', 'like', addcslashes($account->external_id, '%_\\').':%');
        }
        $value = $query->latest('last_seen_at')->value('value');

        return is_string($value) ? $value : null;
    }

    /** El PSID o IGSID al que se le escribe desde la cuenta, o null si el contacto nunca escribió a esa página. */
    public static function recipientFor(ChannelAccount $account, string $contactId): ?string
    {
        $value = self::identityFor($account->channel, $contactId, $account);

        return $value === null ? null : substr($value, strlen((string) $account->external_id) + 1);
    }

    /** Índice para supresiones (`suppressions.value_bidx`, 64 caracteres). */
    public static function bidx(string $workspaceId, string $identityValue): string
    {
        return BlindIndex::value($workspaceId, 'social', $identityValue);
    }
}
