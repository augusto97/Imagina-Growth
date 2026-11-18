<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Actions;

use App\Domain\Commerce\Contracts\CouponIssuer;
use App\Domain\Commerce\Data\CouponSpec;
use App\Domain\Commerce\Exceptions\CouponIssueException;
use App\Domain\Commerce\Models\Cart;
use App\Domain\Commerce\Support\CartRestoreLink;
use App\Domain\Inbox\Models\ConversationEvent;
use App\Domain\Inbox\Realtime\InboxUpdated;
use App\Domain\Messaging\Contracts\MessageDispatcher;
use App\Domain\Messaging\Data\DispatchResult;
use App\Domain\Messaging\Data\OutboundMessage;
use App\Domain\Messaging\Enums\Channel;
use App\Domain\Messaging\Enums\MessageKind;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\Message;
use App\Domain\Sites\Actions\SendSiteCommand;
use App\Domain\Sites\Models\Site;
use App\Domain\Templates\Models\Template;
use App\Support\Ids\PrefixedId;
use App\Support\Money\Decimal;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * Envíos desde la bandeja (M11). Todo pasa por `MessageDispatcher` con la clave
 * `inbox:{conversación}:{uuid del cliente}` (un doble clic o un reintento nunca
 * duplica):
 *
 * - `text`: mensaje libre (de servicio), solo con la ventana de 24 h abierta.
 * - `template`: plantilla aprobada de WhatsApp (utilidad o marketing según su
 *   categoría); funciona también fuera de la ventana. Las variables que escribe el
 *   agente llegan a la plantilla como `bandeja.<clave>`.
 * - `product`: precio y stock **en vivo** (`product.lookup`) con el enlace.
 * - `cart_link`: carrito restaurable con los productos elegidos (y un cupón).
 * - `payment_link`: pedido pendiente en WooCommerce con su enlace de pago
 *   (`order.create_payment_link`).
 * - `coupon`: cupón único para el contacto (`CouponIssuer`, origen `inbox`).
 *
 * El enviado queda en la conversación (`conversation_id`), con su autor en
 * `conversation_events` (para las métricas por agente) y, si es la primera
 * respuesta del ciclo, su tiempo.
 */
final class SendInboxMessage
{
    public function __construct(
        private readonly MessageDispatcher $dispatcher,
        private readonly SendSiteCommand $site,
        private readonly CouponIssuer $coupons,
    ) {}

    /**
     * @param  array<string, mixed>  $input  type, client_id y lo propio de cada tipo
     * @return array{status: string, message_id: string|null, until: string|null, reason: string|null, text: string|null}
     */
    public function handle(Conversation $conversation, array $input, string $actor): array
    {
        $type = (string) ($input['type'] ?? 'text');
        $clientId = (string) ($input['client_id'] ?? Str::uuid());
        $key = 'inbox:'.$conversation->id.':'.$clientId;
        $existing = Message::query()->where('idempotency_key', $key)->first();
        if ($existing !== null) {
            return ['status' => DispatchResult::DUPLICATE, 'message_id' => $existing->publicId(), 'until' => null, 'reason' => null, 'text' => null];
        }

        [$kind, $templateId, $context, $text] = match ($type) {
            'text' => [MessageKind::Service, null, [], trim((string) ($input['text'] ?? ''))],
            'template' => $this->template($input),
            'product' => [MessageKind::Service, null, [], $this->productText($conversation, (string) ($input['product_id'] ?? ''), $input['note'] ?? null)],
            'cart_link' => [MessageKind::Service, null, [], $this->cartText($conversation, (array) ($input['items'] ?? []), $input['coupon'] ?? null)],
            'payment_link' => [MessageKind::Service, null, [], $this->paymentText($conversation, (array) ($input['items'] ?? []))],
            'coupon' => [MessageKind::Service, null, [], $this->couponText($conversation, $input)],
            default => throw new InvalidArgumentException(__('Tipo de envío desconocido.')),
        };
        if ($templateId === null && ($text === null || $text === '')) {
            throw new InvalidArgumentException(__('Escribe el mensaje.'));
        }
        if ($templateId === null) {
            $context = ['text' => $text];
        }

        $result = $this->dispatcher->dispatch(new OutboundMessage(
            workspaceId: $conversation->workspace_id,
            contactId: $conversation->contact_id,
            channel: Channel::WhatsApp,
            kind: $kind,
            sourceType: 'inbox',
            sourceId: $conversation->id,
            templateId: $templateId,
            context: $context,
            idempotencyKey: $key,
            channelAccountId: $conversation->channel_account_id,
        ));

        if ($result->sent() && $result->messageId !== null) {
            $this->record($conversation, $result->messageId, $type, $actor);
        }

        return [
            'status' => $result->status,
            'message_id' => $result->messageId !== null ? PrefixedId::encode(Message::ID_PREFIX, $result->messageId) : null,
            'until' => $result->until?->toIso8601String(),
            'reason' => $result->reason,
            'text' => $text,
        ];
    }

    private function record(Conversation $conversation, string $messageId, string $type, string $actor): void
    {
        $now = now();
        Message::query()->whereKey($messageId)->update(['conversation_id' => $conversation->id]);
        $first = $conversation->first_response_seconds === null && $conversation->getAttribute('opened_at') !== null && $actor !== 'ai';
        $conversation->forceFill([
            'last_outbound_at' => $now,
            'last_message_at' => $now,
            'unread_count' => 0,
            'awaiting_since' => null,
            'status' => $conversation->status === 'closed' ? 'open' : $conversation->status,
        ]);
        if ($first) {
            $conversation->forceFill(['first_response_seconds' => max(0, (int) $now->diffInSeconds($conversation->getAttribute('opened_at'), true))]);
        }
        $conversation->save();
        ConversationEvent::record($conversation->workspace_id, $conversation->id, 'message', $actor, ['message' => PrefixedId::encode(Message::ID_PREFIX, $messageId), 'type' => $type]);
        if ($first) {
            ConversationEvent::record($conversation->workspace_id, $conversation->id, 'first_response', $actor, ['seconds' => $conversation->first_response_seconds]);
        }
        event(new InboxUpdated($conversation->workspace_id, $conversation->publicId(), 'outbound', $conversation->assignee_id));
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{0: MessageKind, 1: string, 2: array<string, mixed>, 3: null}
     */
    private function template(array $input): array
    {
        $id = PrefixedId::decode(Template::ID_PREFIX, (string) ($input['template_id'] ?? ''));
        $template = $id !== null ? Template::query()->where('channel', 'whatsapp')->find($id) : null;
        if ($template === null) {
            throw new InvalidArgumentException(__('Elige una plantilla de WhatsApp.'));
        }
        $vars = [];
        foreach ((array) ($input['variables'] ?? []) as $k => $v) {
            $vars[mb_substr((string) $k, 0, 32)] = mb_substr(str_replace(["\r", "\n"], ' ', (string) $v), 0, 500);
        }
        $kind = match ($template->category) {
            'marketing' => MessageKind::Marketing,
            'authentication' => MessageKind::Authentication,
            default => MessageKind::Utility,
        };

        return [$kind, $template->id, ['bandeja' => $vars], null];
    }

    private function productText(Conversation $conversation, string $productId, mixed $note): string
    {
        $product = $this->lookup($conversation, $productId);
        $currency = (string) ($this->siteFor($conversation)->currency ?: 'COP');
        $lines = [(string) ($product['name'] ?? '')];
        $price = (string) ($product['sale_price'] ?? '') !== '' ? (string) $product['sale_price'] : (string) ($product['price'] ?? '');
        if ($price !== '') {
            $lines[] = self::money($price, $currency);
        }
        $lines[] = ($product['stock_status'] ?? 'instock') === 'outofstock' ? __('Agotado por ahora') : __('Disponible');
        if (is_string($note) && trim($note) !== '') {
            array_unshift($lines, trim($note));
        }
        if (is_string($product['url'] ?? null)) {
            $lines[] = (string) $product['url'];
        }

        return implode("\n", array_filter($lines, fn ($l) => $l !== ''));
    }

    /** @return array<string, mixed> el producto, con precio y stock en vivo */
    public function lookup(Conversation $conversation, string $productId): array
    {
        if (preg_match('/^\d{1,20}$/', $productId) !== 1) {
            throw new InvalidArgumentException(__('Elige un producto.'));
        }
        try {
            $data = $this->site->handle($this->siteFor($conversation), 'product.lookup', ['ids' => [$productId], 'limit' => 1], timeout: 8);
        } catch (RuntimeException $e) {
            throw new InvalidArgumentException(__('La tienda no respondió: :msg', ['msg' => $e->getMessage()]), 0, $e);
        }
        $product = $data['products'][0] ?? null;
        if (! is_array($product)) {
            throw new InvalidArgumentException(__('Ese producto ya no está publicado en la tienda.'));
        }

        return $product;
    }

    /** @param array<mixed> $items */
    private function cartText(Conversation $conversation, array $items, mixed $coupon): string
    {
        $site = $this->siteFor($conversation);
        $cart = new Cart;
        $cart->forceFill(['id' => strtoupper((string) Str::ulid()), 'workspace_id' => $conversation->workspace_id, 'site_id' => $site->id, 'items' => self::items($items)]);
        $url = CartRestoreLink::for($cart, is_string($coupon) && $coupon !== '' ? mb_substr($coupon, 0, 40) : null, 3);
        if ($url === null) {
            throw new InvalidArgumentException(__('No se pudo armar el carrito: revisa los productos.'));
        }

        return __('Te dejé el carrito listo, solo falta pagar:')."\n".$url;
    }

    /** @param array<mixed> $items */
    private function paymentText(Conversation $conversation, array $items): string
    {
        try {
            $data = $this->site->handle($this->siteFor($conversation), 'order.create_payment_link', [
                'items' => self::items($items), 'customer' => ['contact_id' => PrefixedId::encode('ct', $conversation->contact_id)],
            ], timeout: 10);
        } catch (RuntimeException $e) {
            throw new InvalidArgumentException(__('La tienda no pudo crear el enlace de pago: :msg', ['msg' => $e->getMessage()]), 0, $e);
        }
        $url = $data['url'] ?? $data['payment_url'] ?? null;
        if (! is_string($url) || $url === '') {
            throw new InvalidArgumentException(__('La tienda no devolvió el enlace de pago.'));
        }

        return __('Este es tu enlace de pago:')."\n".$url;
    }

    /** @param array<string, mixed> $input */
    private function couponText(Conversation $conversation, array $input): string
    {
        $kind = in_array($input['kind'] ?? 'percent', ['percent', 'fixed_cart', 'free_shipping'], true) ? (string) ($input['kind'] ?? 'percent') : 'percent';
        $amount = (string) ($input['amount'] ?? '10');
        if ($kind !== 'free_shipping' && (! is_numeric($amount) || (float) $amount <= 0 || ($kind === 'percent' && (float) $amount > 50))) {
            throw new InvalidArgumentException(__('El descuento debe ser mayor que cero (máximo 50%).'));
        }
        try {
            $coupon = $this->coupons->issue(new CouponSpec(
                kind: $kind, amount: $kind === 'free_shipping' ? '0' : number_format((float) $amount, 2, '.', ''), sourceType: 'inbox',
                sourceId: $conversation->id, contactId: $conversation->contact_id, expiresInDays: max(1, min(30, (int) ($input['days'] ?? 7))),
                prefix: 'CHAT', siteId: $this->siteFor($conversation)->id, description: __('Cupón de la bandeja'),
            ));
        } catch (CouponIssueException $e) {
            throw new InvalidArgumentException($e->getMessage(), 0, $e);
        }

        return __('Tu cupón es :code y vence el :date.', ['code' => $coupon->code, 'date' => $coupon->expires_at?->format('d/m/Y')]);
    }

    /**
     * @param  array<mixed>  $items
     * @return list<array{product_id: string, variation_id: string|null, qty: int}>
     */
    private static function items(array $items): array
    {
        $out = [];
        foreach (array_slice($items, 0, 20) as $item) {
            if (! is_array($item) || preg_match('/^\d{1,20}$/', (string) ($item['product_id'] ?? '')) !== 1) {
                continue;
            }
            $out[] = ['product_id' => (string) $item['product_id'], 'variation_id' => isset($item['variation_id']) && $item['variation_id'] !== '' ? (string) $item['variation_id'] : null,
                'qty' => max(1, min(99, (int) ($item['qty'] ?? 1)))];
        }
        if ($out === []) {
            throw new InvalidArgumentException(__('Agrega al menos un producto.'));
        }

        return $out;
    }

    public function siteFor(Conversation $conversation): Site
    {
        return Site::query()->whereIn('status', [Site::STATUS_CONNECTED, Site::STATUS_DEGRADED])->orderBy('created_at')->first()
            ?? throw new InvalidArgumentException(__('No hay una tienda conectada.'));
    }

    private static function money(string $amount, string $currency): string
    {
        $decimals = in_array($currency, ['COP', 'CLP'], true) ? 0 : 2;

        return '$'.number_format((float) Decimal::of($amount), $decimals, ',', '.').($currency !== 'COP' ? ' '.$currency : '');
    }
}
