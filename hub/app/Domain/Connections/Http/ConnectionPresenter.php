<?php

declare(strict_types=1);

namespace App\Domain\Connections\Http;

use App\Domain\Connections\Enums\ConnectionProvider;
use App\Domain\Connections\Models\OAuthConnection;
use App\Domain\Measurement\Models\AdDestination;
use App\Domain\Messaging\Models\ChannelAccount;

/** Una conexión para la SPA: estado, cuenta, alcances y qué la usa. Nunca tokens. */
final class ConnectionPresenter
{
    /** @return array<string, mixed> */
    public static function present(OAuthConnection $c): array
    {
        $whatsapp = $c->asset('whatsapp');

        return [
            'id' => $c->publicId(),
            'provider' => $c->provider->value,
            'kind' => match (true) {
                str_starts_with($c->subject, 'staff:') => 'calendar',
                $c->provider === ConnectionProvider::MetaWhatsApp => 'whatsapp',
                default => 'workspace',
            },
            'status' => $c->status,
            'account_name' => $c->account_name,
            'scopes' => array_values($c->scopes ?? []),
            'uses' => self::uses($c),
            'whatsapp' => $whatsapp !== [] ? ['waba_id' => $whatsapp['waba_id'] ?? null, 'coexistence' => (bool) ($whatsapp['coexistence'] ?? false)] : null,
            'expires_at' => $c->expires_at?->toIso8601String(),
            'connected_at' => $c->getAttribute('updated_at')?->toIso8601String(),
            'last_error' => $c->last_error,
            'used_by' => [
                'destinations' => AdDestination::query()->where('oauth_connection_id', $c->id)->get()
                    ->map(fn (AdDestination $d) => ['id' => $d->publicId(), 'platform' => $d->platform->value, 'name' => $d->name, 'status' => $d->status])->values(),
                'channels' => ChannelAccount::query()->where('oauth_connection_id', $c->id)->where('status', '!=', 'disconnected')->get()
                    ->map(fn (ChannelAccount $a) => ['id' => $a->publicId(), 'channel' => $a->channel->value, 'name' => $a->display_name, 'identity' => $a->identity, 'status' => $a->status])->values(),
            ],
        ];
    }

    /**
     * Para qué sirve, según lo que se concedió.
     *
     * @return list<string>
     */
    private static function uses(OAuthConnection $c): array
    {
        $scopes = $c->scopes ?? [];
        $has = fn (string ...$any): bool => array_intersect($any, $scopes) !== [];

        return array_keys(array_filter(match ($c->provider) {
            ConnectionProvider::Google => [
                'conversions' => $has('https://www.googleapis.com/auth/datamanager'),
                'ads' => $has('https://www.googleapis.com/auth/adwords'),
                'analytics' => $has('https://www.googleapis.com/auth/analytics.edit'),
                'calendar' => $has('https://www.googleapis.com/auth/calendar.events'),
            ],
            ConnectionProvider::Meta => [
                'conversions' => $has('ads_management', 'business_management'),
                'audiences' => $has('ads_management'),
                'spend' => $has('ads_read', 'ads_management'),
                'messenger' => $has('pages_messaging'),
                'instagram' => $has('instagram_manage_messages'),
            ],
            ConnectionProvider::MetaWhatsApp => ['whatsapp' => true],
            ConnectionProvider::TikTok => ['conversions' => true, 'audiences' => true, 'spend' => true],
        }));
    }
}
