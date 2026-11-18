<?php

declare(strict_types=1);

namespace App\Domain\Measurement\Attribution;

use App\Domain\Commerce\Models\Order;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Contacts\Models\ContactIdentity;
use App\Domain\Sites\Models\Site;
use App\Domain\Workspaces\Models\Workspace;
use App\Ingest\Enrichment\ChannelClassifier;
use Carbon\CarbonImmutable;

/**
 * Canales de primer y último contacto de un pedido (hub/CLAUDE.md §9 paso 1).
 * Preferencia: visitas del tracker en los 30 días anteriores → contexto del
 * evento del pedido (cookie _igr_src) → atribución nativa de WooCommerce.
 * El último contacto es el último no directo («último clic no directo»).
 * Los pasos de mensajes, referidos y widgets llegan en las fases 2 y 3.
 */
final class OrderAttributor
{
    public const WINDOW_DAYS = 30;

    public function __construct(
        private readonly TouchQuery $touches,
        private readonly WcAttributionChannel $wc,
        private readonly ChannelClassifier $classifier,
    ) {}

    /**
     * @param  array{first_touch?: array<string, mixed>|null, last_touch?: array<string, mixed>|null, anonymous_id?: string|null}  $hints  datos del sobre del pedido (sin datos personales)
     */
    public function attribute(Workspace $workspace, Order $order, array $hints = [], bool $useTracker = true): void
    {
        $site = Site::query()->find($order->site_id);
        $createdAt = CarbonImmutable::instance($order->created_at ?? now());
        $anon = $this->anonymousIds($order->contact_id, $hints['anonymous_id'] ?? null);

        $sessions = $useTracker ? $this->touches->sessions($workspace, $order->contact_id, $anon, $createdAt->subDays(self::WINDOW_DAYS), $createdAt) : [];
        $first = $sessions[0] ?? null;
        $last = null;
        foreach (array_reverse($sessions) as $touch) {
            if ($touch->channel !== ChannelClassifier::DIRECT) {
                $last = $touch;
                break;
            }
        }
        $last ??= $sessions === [] ? null : end($sessions);

        $first ??= $this->hint($hints['first_touch'] ?? null, $site);
        $last ??= $this->hint($hints['last_touch'] ?? null, $site);
        $wc = $order->getAttribute('wc_attribution');
        $wcTouch = $this->wc->touch(is_array($wc) ? $wc : null, $site?->host, $createdAt->toIso8601String());
        $first ??= $wcTouch ?? $last;
        $last ??= $wcTouch ?? $first;

        $order->forceFill([
            'channel_first' => $first?->channel,
            'channel_last' => $last?->channel,
            'utm_last' => $last !== null && $last->utm() !== [] ? $last->utm() : null,
        ])->save();

        $this->fillContact($workspace, $order, $first, $useTracker, $anon);
    }

    /**
     * anonymous_id del contacto ya confirmados en MariaDB (identity_map de
     * ClickHouse puede ir unos segundos atrás) más el del evento del pedido.
     *
     * @return list<string>
     */
    private function anonymousIds(?string $contactId, mixed $hint): array
    {
        $ids = $contactId === null ? [] : ContactIdentity::query()
            ->where('contact_id', $contactId)->where('type', 'anonymous_id')
            ->limit(200)->pluck('value')->map(fn ($v) => (string) $v)->all();
        if (is_string($hint) && $hint !== '') {
            $ids[] = $hint;
        }

        return array_values(array_unique($ids));
    }

    /** @param list<string> $anon */
    private function fillContact(Workspace $workspace, Order $order, ?Touch $first, bool $useTracker, array $anon): void
    {
        if ($order->contact_id === null) {
            return;
        }
        $contact = Contact::query()->find($order->contact_id);
        if ($contact === null) {
            return;
        }
        $changes = [];
        if ($contact->getAttribute('first_source') === null) {
            // Primera fuente de la persona: su primera visita conocida, sin límite de ventana.
            $earliest = $useTracker ? ($this->touches->sessions($workspace, $contact->id, $anon, null, CarbonImmutable::now())[0] ?? null) : null;
            $source = $earliest ?? $first;
            if ($source !== null) {
                $changes['first_source'] = $source->toArray();
            }
        }
        if ($contact->getAttribute('first_order_source') === null && $first !== null && $order->is_paid && $this->isFirstPaidOrder($order)) {
            $changes['first_order_source'] = $first->toArray();
        }
        if ($changes !== []) {
            $contact->forceFill($changes)->save();
        }
    }

    private function isFirstPaidOrder(Order $order): bool
    {
        return ! Order::query()
            ->where('contact_id', $order->contact_id)
            ->where('is_paid', true)
            ->where('created_at', '<', $order->created_at)
            ->exists();
    }

    /** @param array<string, mixed>|null $touch */
    private function hint(?array $touch, ?Site $site): ?Touch
    {
        if ($touch === null || $touch === []) {
            return null;
        }
        $utm = is_array($touch['utm'] ?? null) ? $touch['utm'] : [];
        $clicks = is_array($touch['click_ids'] ?? null) ? $touch['click_ids'] : [];
        $referrer = is_string($touch['referrer'] ?? null) ? $touch['referrer'] : null;
        if ($utm === [] && $clicks === [] && ($referrer === null || $referrer === '')) {
            return new Touch(ChannelClassifier::DIRECT, at: is_string($touch['at'] ?? null) ? $touch['at'] : null, source: 'order_context');
        }

        return new Touch(
            $this->classifier->classify(['utm' => $utm, 'click_ids' => $clicks, 'referrer' => $referrer, 'site_host' => $site?->host]),
            isset($utm['source']) ? (string) $utm['source'] : null,
            isset($utm['medium']) ? (string) $utm['medium'] : null,
            isset($utm['campaign']) ? (string) $utm['campaign'] : null,
            is_string($touch['at'] ?? null) ? $touch['at'] : null,
            'order_context',
        );
    }
}
