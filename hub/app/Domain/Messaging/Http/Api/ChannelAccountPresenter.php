<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Http\Api;

use App\Domain\Messaging\Channels\DriverRegistry;
use App\Domain\Messaging\Models\ChannelAccount;
use App\Domain\Messaging\Support\ContactLinkToken;
use App\Domain\Messaging\Support\SendCaps;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;

/** Una cuenta de canal para el panel: nunca los secretos. */
final class ChannelAccountPresenter
{
    /** @return array<string, mixed> */
    public static function present(ChannelAccount $account): array
    {
        $webhook = match ($account->provider) {
            'zender' => ContactLinkToken::base().'/webhooks/zender/'.$account->publicId(),
            'twilio' => ContactLinkToken::base().'/webhooks/twilio/'.$account->publicId(),
            'meta_cloud' => ContactLinkToken::base().'/webhooks/meta',
            'ses' => ContactLinkToken::base().'/webhooks/ses',
            default => null,
        };

        return [
            'id' => $account->publicId(),
            'channel' => $account->channel->value,
            'provider' => $account->provider,
            'display_name' => $account->display_name,
            'identity' => self::displayIdentity($account->identity),
            'status' => $account->status,
            'is_default' => $account->is_default,
            'quality_rating' => $account->quality_rating,
            'messaging_limit_tier' => $account->messaging_limit_tier,
            'last_error' => $account->last_error,
            'risk_accepted_at' => $account->risk_accepted_at?->toIso8601String(),
            'from_email' => $account->credential('from_email'),
            'from_name' => $account->credential('from_name'),
            'reply_to' => $account->credential('reply_to'),
            'domain' => $account->credential('domain'),
            'warmup' => SendCaps::warmup($account),
            'caps' => $account->provider === ChannelAccount::ZENDER ? ['hourly' => (int) config('igr-messaging.zender.hourly_cap'), 'daily' => (int) config('igr-messaging.zender.daily_cap')] : null,
            'capabilities' => app(DriverRegistry::class)->for($account->provider)->capabilities(),
            'webhook_url' => $webhook,
            'created_at' => $account->created_at?->toIso8601String(),
        ];
    }

    /** Un número E.164 se muestra legible (+57 300 555 1515); lo demás (dominio, SID) tal cual. */
    private static function displayIdentity(?string $identity): ?string
    {
        if ($identity === null || preg_match('/^\+\d{8,15}$/', $identity) !== 1) {
            return $identity;
        }
        $util = PhoneNumberUtil::getInstance();
        try {
            return $util->format($util->parse($identity), PhoneNumberFormat::INTERNATIONAL);
        } catch (NumberParseException) {
            return $identity;
        }
    }
}
