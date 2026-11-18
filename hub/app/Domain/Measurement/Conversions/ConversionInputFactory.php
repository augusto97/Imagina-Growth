<?php

declare(strict_types=1);

namespace App\Domain\Measurement\Conversions;

use App\Domain\Contacts\Models\Contact;
use App\Domain\Sites\Models\Site;
use App\Domain\Workspaces\Models\Workspace;
use App\Ingest\Events\EventIngested;
use Carbon\CarbonImmutable;

/**
 * Arma el ConversionInput desde el sobre del evento (eventos.md §2 y §5) y,
 * si faltan datos coincidentes, desde el perfil del contacto (cifrado en reposo).
 */
final class ConversionInputFactory
{
    public function make(EventIngested $e, Workspace $workspace, ?Site $site): ConversionInput
    {
        $env = $e->envelope;
        $ctx = is_array($env['context'] ?? null) ? $env['context'] : [];
        $identity = is_array($env['identity'] ?? null) ? $env['identity'] : [];
        $props = is_array($env['properties'] ?? null) ? $env['properties'] : [];
        $touch = is_array($ctx['last_touch'] ?? null) ? $ctx['last_touch'] : [];
        $clicks = array_filter(array_merge(
            is_array($touch['click_ids'] ?? null) ? $touch['click_ids'] : [],
            is_array($ctx['click_ids'] ?? null) ? $ctx['click_ids'] : [],
        ), fn ($v) => is_string($v) && $v !== '');
        $cookies = is_array($ctx['ad_cookies'] ?? null) ? $ctx['ad_cookies'] : [];

        $user = $this->userFromEvent($e->event, $identity, $props);
        if ($e->contactId !== null && ($user['email'] === null || $user['phone'] === null || $user['first_name'] === null)) {
            $contact = Contact::query()->find($e->contactId);
            if ($contact !== null) {
                $user['email'] ??= $contact->email;
                $user['phone'] ??= $contact->phone;
                $user['first_name'] ??= $contact->first_name;
                $user['last_name'] ??= $contact->last_name;
                $user['city'] ??= $contact->getAttribute('city');
                $user['state'] ??= $contact->getAttribute('region_code');
                $user['country'] ??= $contact->getAttribute('country');
            }
        }

        [$value, $currency, $orderId, $items] = $this->commerce($e->event, $props, $ctx);

        $fbclid = $clicks['fbclid'] ?? null;
        $fbc = self::str($cookies['fbc'] ?? null);
        $occurred = CarbonImmutable::parse((string) ($env['occurred_at'] ?? 'now'));
        if ($fbc === null && $fbclid !== null) {
            // Formato de Meta para el clic cuando la cookie todavía no existe.
            $fbc = 'fb.1.'.$occurred->getTimestampMs().'.'.$fbclid;
        }

        $page = is_array($ctx['page'] ?? null) ? $ctx['page'] : [];
        $sourceUrl = self::str($page['url'] ?? null) ?? self::str($touch['landing'] ?? null) ?? $site?->url;

        return new ConversionInput(
            workspaceId: $workspace->id,
            siteId: $e->siteId,
            event: $e->event,
            eventId: $e->eventId,
            occurredAt: $occurred->getTimestamp(),
            sourceUrl: $sourceUrl,
            referrer: self::str($page['referrer'] ?? null),
            ip: $e->ip,
            userAgent: $e->userAgent,
            contactId: $e->contactId,
            anonymousId: self::str($identity['anonymous_id'] ?? null),
            sessionId: self::str($identity['session_id'] ?? null),
            defaultCountry: (string) ($workspace->setting('default_phone_country') ?? $workspace->country),
            email: $user['email'],
            phone: $user['phone'],
            firstName: $user['first_name'],
            lastName: $user['last_name'],
            city: $user['city'],
            state: $user['state'],
            zip: $user['zip'],
            country: $user['country'] ?? $workspace->country,
            fbp: self::str($cookies['fbp'] ?? null),
            fbc: $fbc,
            gaClientId: self::str($cookies['ga_client_id'] ?? null),
            ttp: self::str($cookies['ttp'] ?? null),
            fbclid: $fbclid,
            gclid: $clicks['gclid'] ?? null,
            gbraid: $clicks['gbraid'] ?? null,
            wbraid: $clicks['wbraid'] ?? null,
            ttclid: $clicks['ttclid'] ?? null,
            value: $value,
            currency: $currency,
            orderId: $orderId,
            items: $items,
            ctwaClid: self::str($props['ctwa_clid'] ?? null),
            consentGranted: (bool) ($ctx['consent']['marketing'] ?? false),
        );
    }

    /**
     * @param  array<string, mixed>  $identity
     * @param  array<string, mixed>  $props
     * @return array{email: string|null, phone: string|null, first_name: string|null, last_name: string|null, city: string|null, state: string|null, zip: string|null, country: string|null}
     */
    private function userFromEvent(string $event, array $identity, array $props): array
    {
        $u = ['email' => self::str($identity['email'] ?? null), 'phone' => self::str($identity['phone'] ?? null), 'first_name' => null, 'last_name' => null, 'city' => null, 'state' => null, 'zip' => null, 'country' => null];
        $address = null;
        if (is_array($props['order'] ?? null)) {
            $address = is_array($props['order']['billing'] ?? null) ? $props['order']['billing'] : null;
        } elseif (str_starts_with($event, 'customer.')) {
            $address = is_array($props['billing'] ?? null) ? $props['billing'] : [];
            $u['email'] ??= self::str($props['email'] ?? null);
            $u['phone'] ??= self::str($props['phone'] ?? null);
            $u['first_name'] = self::str($props['first_name'] ?? null);
            $u['last_name'] = self::str($props['last_name'] ?? null);
        } elseif ($event === 'form.submitted' && is_array($props['fields'] ?? null)) {
            $address = $props['fields'];
        }
        if ($address !== null) {
            $u['email'] ??= self::str($address['email'] ?? null);
            $u['phone'] ??= self::str($address['phone'] ?? null);
            $u['first_name'] ??= self::str($address['first_name'] ?? null);
            $u['last_name'] ??= self::str($address['last_name'] ?? null);
            $u['city'] = self::str($address['city'] ?? null);
            $u['state'] = self::str($address['state'] ?? null);
            $u['zip'] = self::str($address['postcode'] ?? null);
            $u['country'] = self::str($address['country'] ?? null);
        }

        return $u;
    }

    /**
     * @param  array<string, mixed>  $props
     * @param  array<string, mixed>  $ctx
     * @return array{0: string|null, 1: string|null, 2: string|null, 3: list<array{id: string, name: string|null, price: string|null, quantity: float, category: string|null}>}
     */
    private function commerce(string $event, array $props, array $ctx): array
    {
        $currency = self::str($props['currency'] ?? null) ?? self::str($ctx['currency'] ?? null);
        $item = fn (string $id, ?string $name, mixed $price, mixed $qty, mixed $cats) => [
            'id' => $id,
            'name' => $name,
            'price' => is_scalar($price) ? (string) $price : null,
            'quantity' => is_numeric($qty) ? (float) $qty : 1.0,
            'category' => is_array($cats) && isset($cats[0]) ? (string) $cats[0] : null,
        ];

        if (is_array($props['order'] ?? null)) {
            $o = $props['order'];
            $items = [];
            foreach (is_array($o['items'] ?? null) ? $o['items'] : [] as $i) {
                if (is_array($i) && isset($i['product_id'])) {
                    $items[] = $item((string) ($i['variation_id'] ?? null ?: $i['product_id']), self::str($i['name'] ?? null), $i['unit_price'] ?? null, $i['qty'] ?? 1, $i['category_ids'] ?? null);
                }
            }

            return [self::str($o['totals']['total'] ?? null), self::str($o['currency'] ?? null) ?? $currency, self::str($o['order_id'] ?? null), $items];
        }

        return match ($event) {
            'product.viewed' => [self::str($props['price'] ?? null), $currency, null, [$item((string) $props['product_id'], self::str($props['name'] ?? null), $props['price'] ?? null, 1, $props['category_ids'] ?? null)]],
            'cart.item_added' => [
                is_scalar($props['price'] ?? null) ? number_format((float) $props['price'] * (float) ($props['qty'] ?? 1), 2, '.', '') : null,
                $currency, null,
                [$item((string) ($props['variation_id'] ?? null ?: $props['product_id']), null, $props['price'] ?? null, $props['qty'] ?? 1, null)],
            ],
            'checkout.started' => [self::str($props['subtotal'] ?? null), $currency, null, []],
            default => [null, $currency, null, []],
        };
    }

    private static function str(mixed $v): ?string
    {
        return is_string($v) && trim($v) !== '' ? trim($v) : null;
    }
}
