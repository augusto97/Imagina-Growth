<?php

declare(strict_types=1);

namespace App\Domain\Recommendations\Watches;

use App\Domain\Consent\Models\ContactConsent;
use App\Domain\Contacts\Actions\CaptureContact;
use App\Domain\Contacts\Data\CaptureInput;
use App\Domain\Recommendations\Models\CommerceSettings;
use App\Domain\Recommendations\Models\ProductWatch;
use App\Domain\Sites\Models\Site;
use App\Domain\Workspaces\Models\Workspace;
use App\Ingest\HubEvents;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * «Avísame cuando vuelva» y «avísame si baja de precio» (modulos.md M16,
 * api-connector.md §8.3 `POST /watch`).
 *
 * El aviso es un mensaje comercial (cumplimiento.md §4.1: la persona lo pidió,
 * pero se trata como marketing por prudencia), así que hace falta el
 * consentimiento del canal: la casilla marcada ahora o uno que ya tenía. El canal
 * se elige en este orden: WhatsApp, correo, SMS, entre los que tienen dirección y
 * consentimiento y el comercio tiene activos.
 */
final class SubscribeToProduct
{
    public function __construct(private readonly CaptureContact $capture, private readonly HubEvents $events) {}

    /**
     * @param  array{product_id: string, kind: string, email?: string|null, phone?: string|null, consents?: array<string, bool>, anonymous_id?: string|null, page_url?: string|null, first_name?: string|null}  $input
     * @return array{watch: ProductWatch|null, created: bool, available: bool}
     */
    public function handle(Workspace $workspace, Site $site, array $input, ?string $ip, ?string $userAgent): array
    {
        $settings = CommerceSettings::current()->watchSettings();
        if (! $settings['enabled']) {
            throw ValidationException::withMessages(['product_id' => __('Los avisos de producto no están activos en esta tienda.')]);
        }
        $kind = $input['kind'];
        $product = DB::table('products')->where('workspace_id', $workspace->id)->where('site_id', $site->id)
            ->where('external_id', $input['product_id'])->whereNull('deleted_at')->first(['external_id', 'price', 'stock_status', 'status']);
        if ($product === null || $product->status !== 'publish') {
            throw ValidationException::withMessages(['product_id' => __('Ese producto no existe en la tienda.')]);
        }
        if ($kind === 'back_in_stock' && in_array($product->stock_status, ['instock', 'onbackorder'], true)) {
            return ['watch' => null, 'created' => false, 'available' => true];
        }

        $consents = [];
        foreach ((array) ($input['consents'] ?? []) as $channel => $checked) {
            if (in_array($channel, ['email', 'whatsapp', 'sms'], true)) {
                $consents[$channel] = $checked === true;
            }
        }
        $result = $this->capture->handle($workspace, $site, new CaptureInput(
            source: 'watch:'.mb_substr((string) $product->external_id, 0, 40),
            email: $input['email'] ?? null,
            phone: $input['phone'] ?? null,
            firstName: $input['first_name'] ?? null,
            anonymousId: $input['anonymous_id'] ?? null,
            consents: $consents,
            pageUrl: $input['page_url'] ?? null,
            ip: $ip,
            userAgent: $userAgent,
        ));
        $contact = $result->contact;

        $channel = null;
        foreach (['whatsapp', 'email', 'sms'] as $candidate) {
            if (! in_array($candidate, $settings['channels'], true)) {
                continue;
            }
            $hasAddress = $candidate === 'email' ? $contact->email !== null : $contact->phone !== null;
            $given = $candidate === 'email' ? ($input['email'] ?? null) !== null : ($input['phone'] ?? null) !== null;
            if (! $hasAddress || ! $given) {
                continue; // se avisa por la dirección que dejó en este formulario
            }
            $granted = in_array($candidate, $result->granted, true) || ContactConsent::query()->where('contact_id', $contact->id)
                ->where('channel', $candidate)->where('purpose', 'marketing')->where('status', 'granted')->exists();
            if ($granted) {
                $channel = $candidate;
                break;
            }
        }
        if ($channel === null) {
            throw ValidationException::withMessages(['consents' => __('Marca la casilla para que podamos avisarte por ese medio.')]);
        }

        $existing = ProductWatch::query()->where('site_id', $site->id)->where('contact_id', $contact->id)
            ->where('external_product_id', (string) $product->external_id)->where('kind', $kind)->where('status', ProductWatch::ACTIVE)->first();
        if ($existing !== null) {
            if ($existing->channel !== $channel) {
                $existing->forceFill(['channel' => $channel])->save();
            }

            return ['watch' => $existing, 'created' => false, 'available' => false];
        }

        $price = number_format((float) $product->price, 2, '.', '');
        $watch = ProductWatch::query()->create([
            'workspace_id' => $workspace->id,
            'site_id' => $site->id,
            'contact_id' => $contact->id,
            'external_product_id' => (string) $product->external_id,
            'kind' => $kind,
            'threshold' => $kind === 'price_drop' ? $price : null,
            'price_at_subscribe' => $price,
            'channel' => $channel,
        ]);
        $this->events->emit($workspace, 'product_watch.created', array_filter([
            'watch_id' => $watch->publicId(),
            'product_id' => (string) $product->external_id,
            'kind' => $kind,
            'price' => $price,
            'threshold' => $kind === 'price_drop' ? $price : null,
        ], fn ($v) => $v !== null), $contact->id, $site, null, null, $input['anonymous_id'] ?? null);

        return ['watch' => $watch, 'created' => true, 'available' => false];
    }
}
