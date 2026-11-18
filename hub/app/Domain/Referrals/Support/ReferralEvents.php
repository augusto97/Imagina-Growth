<?php

declare(strict_types=1);

namespace App\Domain\Referrals\Support;

use App\Domain\Commerce\Models\Order;
use App\Domain\Referrals\Models\Referral;
use App\Domain\Sites\Models\Site;
use App\Ingest\HubEvents;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Eventos del hub de M14 (eventos.md §4.6): `referral.shared` y `referral.converted`. */
final class ReferralEvents
{
    public function __construct(private readonly HubEvents $events, private readonly CurrentWorkspace $current) {}

    /** El amigo calificó: va en el perfil de quien refiere (le avisa un flujo). */
    public function converted(Referral $referral, Order $order): void
    {
        $this->emit('referral.converted', [
            'referral_id' => $referral->publicId(),
            'order_id' => $order->external_id,
            'referral_code' => $referral->code,
            'referrer_contact_id' => $referral->referrer_contact_id,
            'status' => $referral->status,
        ], $referral->referrer_contact_id, Site::query()->find($order->site_id), 'referral-converted:'.$referral->id);
    }

    public function shared(string $contactId, string $code, string $channel, ?Site $site): void
    {
        $this->emit('referral.shared', ['referral_code' => $code, 'channel' => $channel], $contactId, $site, null);
    }

    /** @param array<string, mixed> $properties */
    private function emit(string $event, array $properties, string $contactId, ?Site $site, ?string $idempotency): void
    {
        try {
            $this->events->emit($this->current->get(), $event, $properties, $contactId, $site, eventId: $idempotency !== null ? self::uuid($idempotency) : null);
        } catch (Throwable $e) {
            // El evento es analítica: si ClickHouse falla, la recompensa sigue.
            report($e);
            Log::warning('igr.referrals.event_failed', ['event' => $event]);
        }
    }

    /** UUID estable para que el mismo hecho no se cuente dos veces en ClickHouse. */
    private static function uuid(string $seed): string
    {
        $hash = md5('igr-referrals|'.$seed);

        return sprintf('%s-%s-5%s-%s-%s', substr($hash, 0, 8), substr($hash, 8, 4), substr($hash, 13, 3), dechex(8 | (hexdec($hash[16]) & 3)).substr($hash, 17, 3), substr($hash, 20, 12));
    }
}
