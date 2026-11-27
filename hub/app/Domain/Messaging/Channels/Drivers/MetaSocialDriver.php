<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Channels\Drivers;

use App\Domain\Contacts\Models\ContactIdentity;
use App\Domain\Messaging\Channels\ChannelCapabilities;
use App\Domain\Messaging\Channels\ChannelDriver;
use App\Domain\Messaging\Channels\Data\InboundMessage;
use App\Domain\Messaging\Channels\Data\OutgoingMessage;
use App\Domain\Messaging\Channels\Data\SendResult;
use App\Domain\Messaging\Channels\Data\StatusUpdate;
use App\Domain\Messaging\Channels\Data\WebhookBatch;
use App\Domain\Messaging\Enums\MessageKind;
use App\Domain\Messaging\Models\ChannelAccount;
use App\Domain\Messaging\Models\Message;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Base de Messenger e Instagram Direct (M11, Fase 5): la misma app de Meta, la
 * Send API de la página y webhooks con `entry[].messaging[]` **[verificar]** los
 * campos y la versión vigentes con la app real.
 *
 * - Entrantes: mensajes de texto, adjuntos (con la URL, que caduca), botones
 *   (postback) y respuestas rápidas. Los ecos (`is_echo`: lo que mandó la propia
 *   página) se ignoran.
 * - Recibos: `delivery` con los `mids` y `read` (Messenger manda una marca de
 *   tiempo: se marcan leídos los salientes enviados hasta ese momento).
 * - Salientes: solo texto. Dentro de la ventana de 24 h van como `RESPONSE`; en
 *   Messenger, fuera de ella, solo una persona puede responder con la etiqueta
 *   `HUMAN_AGENT` (hasta 7 días). Nunca marketing.
 */
abstract class MetaSocialDriver implements ChannelDriver
{
    /** Errores de la Send API que se reintentan: límites de velocidad y fallas temporales [verificar]. */
    private const RETRYABLE = ['1', '2', '4', '17', '32', '613', '1200'];

    /** Proveedor en channel_accounts (`meta_messenger` | `meta_instagram`). */
    abstract public function provider(): string;

    /** `object` del webhook de Meta (`page` | `instagram`). */
    abstract public function webhookObject(): string;

    /** Tipo de identidad del remitente (`messenger_psid` | `instagram_igsid`). */
    abstract protected function identityType(): string;

    /** Largo máximo del texto de un mensaje en el canal [verificar]. */
    abstract protected function maxTextLength(): int;

    /** Si el canal acepta la etiqueta `HUMAN_AGENT` fuera de la ventana. */
    abstract public function supportsHumanAgentTag(): bool;

    /**
     * Nombre visible del remitente con la API de perfil, si la app tiene el permiso.
     *
     * @return array{first_name: string|null, last_name: string|null}|null
     */
    abstract public function profile(ChannelAccount $account, string $senderId): ?array;

    /**
     * Comprueba la página o la cuenta de Instagram con el token y devuelve su nombre.
     *
     * @param  array<string, string>  $config
     * @return array{name: string, external_id: string}
     */
    abstract public function verify(array $config): array;

    public function send(ChannelAccount $account, OutgoingMessage $message): SendResult
    {
        $pageId = $account->credential('page_id');
        $token = $account->credential('page_access_token');
        if ($pageId === null || $token === null) {
            return SendResult::failed(__('Falta la página o el token en la cuenta.'), 'not_configured');
        }
        if ($message->isMarketing) {
            return SendResult::failed(__('Este canal no envía marketing.'), 'channel_no_marketing');
        }
        if ($message->messagingTag !== null && ($message->messagingTag !== 'HUMAN_AGENT' || ! $this->supportsHumanAgentTag())) {
            return SendResult::failed(__('Este canal no acepta la etiqueta :tag.', ['tag' => $message->messagingTag]), 'tag_not_supported');
        }
        $text = trim((string) $message->text);
        if ($text === '') {
            return SendResult::failed(__('El mensaje está vacío.'), 'empty');
        }
        if (mb_strlen($text) > $this->maxTextLength()) {
            return SendResult::failed(__('El mensaje supera los :max caracteres que permite el canal.', ['max' => $this->maxTextLength()]), 'too_long');
        }
        $body = array_filter([
            'recipient' => ['id' => $message->to],
            'messaging_type' => $message->messagingTag !== null ? 'MESSAGE_TAG' : 'RESPONSE',
            'tag' => $message->messagingTag,
            'message' => ['text' => $text],
        ], fn ($v) => $v !== null);

        try {
            $response = Http::withToken($token)->timeout(15)->acceptJson()->post($this->url($pageId.'/messages'), $body);
        } catch (ConnectionException $e) {
            return SendResult::failed(__('Sin conexión con Meta: :msg', ['msg' => $e->getMessage()]), 'connection', true);
        }
        if ($response->successful() && is_string($response->json('message_id'))) {
            return SendResult::sent((string) $response->json('message_id'));
        }

        return $this->error($response);
    }

    public function handleWebhook(Request $request, ?ChannelAccount $account): WebhookBatch
    {
        $batch = new WebhookBatch;
        if ($request->input('object') !== $this->webhookObject()) {
            return $batch;
        }
        foreach ((array) $request->input('entry', []) as $entry) {
            $owner = isset($entry['id']) ? (string) $entry['id'] : null;
            foreach ((array) ($entry['messaging'] ?? []) as $event) {
                if (is_array($event) && $owner !== null) {
                    $this->parseEvent($batch, $owner, $event);
                }
            }
        }

        return $batch;
    }

    /** @param array<string, mixed> $event */
    private function parseEvent(WebhookBatch $batch, string $owner, array $event): void
    {
        $sender = (string) ($event['sender']['id'] ?? '');
        $at = CarbonImmutable::createFromTimestampMs((int) ($event['timestamp'] ?? (int) (microtime(true) * 1000)));
        if (is_array($event['delivery'] ?? null)) {
            foreach ((array) ($event['delivery']['mids'] ?? []) as $mid) {
                $batch->statuses[] = ['account' => $owner, 'update' => new StatusUpdate((string) $mid, 'delivered', $at)];
            }

            return;
        }
        if (is_array($event['read'] ?? null)) {
            foreach ($this->readMids($owner, $sender, $event['read']) as $mid) {
                $batch->statuses[] = ['account' => $owner, 'update' => new StatusUpdate($mid, 'read', $at)];
            }

            return;
        }
        if ($sender === '' || $sender === $owner) {
            return;
        }
        $referral = is_array($event['referral'] ?? null) ? $event['referral'] : (is_array($event['postback']['referral'] ?? null) ? $event['postback']['referral'] : (is_array($event['message']['referral'] ?? null) ? $event['message']['referral'] : []));
        if (is_array($event['postback'] ?? null)) {
            $postback = $event['postback'];
            $batch->inbound[] = ['account' => $owner, 'message' => new InboundMessage(
                $sender,
                (string) ($postback['mid'] ?? 'pb:'.$sender.':'.($event['timestamp'] ?? '')),
                'button',
                $at,
                isset($postback['title']) ? (string) $postback['title'] : null,
                isset($postback['payload']) ? mb_substr((string) $postback['payload'], 0, 500) : null,
                referralSourceType: self::referralType($referral),
                referralSourceId: self::referralId($referral),
                recipientId: $owner,
            )];

            return;
        }
        $message = is_array($event['message'] ?? null) ? $event['message'] : null;
        if ($message === null || ($message['is_echo'] ?? false) === true || ($message['is_deleted'] ?? false) === true) {
            return; // ecos de la propia página, borrados, reacciones y otros eventos que no son mensajes
        }
        $attachment = is_array($message['attachments'][0] ?? null) ? $message['attachments'][0] : null;
        $type = $this->typeOf($message, $attachment);
        $url = $attachment !== null && is_string($attachment['payload']['url'] ?? null) ? $attachment['payload']['url'] : null;
        $story = is_array($message['reply_to']['story'] ?? null) ? $message['reply_to']['story'] : null;
        $batch->inbound[] = ['account' => $owner, 'message' => new InboundMessage(
            $sender,
            (string) ($message['mid'] ?? ''),
            $type,
            $at,
            isset($message['text']) ? (string) $message['text'] : null,
            isset($message['quick_reply']['payload']) ? mb_substr((string) $message['quick_reply']['payload'], 0, 500) : null,
            isset($message['reply_to']['mid']) ? (string) $message['reply_to']['mid'] : null,
            referralSourceType: $story !== null ? 'story' : self::referralType($referral),
            referralSourceId: $story !== null && isset($story['id']) ? (string) $story['id'] : self::referralId($referral),
            recipientId: $owner,
            mediaUrl: $url,
        )];
    }

    /**
     * @param  array<string, mixed>  $message
     * @param  array<string, mixed>|null  $attachment
     */
    private function typeOf(array $message, ?array $attachment): string
    {
        if (is_array($message['reply_to']['story'] ?? null)) {
            return 'story_reply';
        }
        if ($attachment === null) {
            return ($message['is_unsupported'] ?? false) === true ? 'other' : 'text';
        }

        return match ((string) ($attachment['type'] ?? '')) {
            'image' => 'image',
            'video' => 'video',
            'audio' => 'audio',
            'file' => 'document',
            'story_mention' => 'story_mention',
            'share', 'ig_reel', 'reel', 'template', 'fallback' => 'share',
            default => 'other',
        };
    }

    /**
     * Instagram manda el `mid` leído; Messenger, una marca de tiempo: se buscan los
     * salientes a ese remitente enviados hasta entonces y aún sin leer.
     *
     * @param  array<string, mixed>  $read
     * @return list<string>
     */
    private function readMids(string $owner, string $sender, array $read): array
    {
        if (isset($read['mid'])) {
            return [(string) $read['mid']];
        }
        if (! isset($read['watermark']) || $sender === '') {
            return [];
        }
        $account = ChannelAccount::withoutWorkspaceScope()->where('provider', $this->provider())->where('external_id', $owner)->first();
        if ($account === null) {
            return [];
        }
        $contactId = ContactIdentity::withoutWorkspaceScope()->where('workspace_id', $account->workspace_id)
            ->where('type', $this->identityType())->where('value', $owner.':'.$sender)->value('contact_id');
        if (! is_string($contactId)) {
            return [];
        }

        $mids = [];
        $rows = Message::withoutWorkspaceScope()->where('workspace_id', $account->workspace_id)->where('channel_account_id', $account->id)
            ->where('contact_id', $contactId)->where('direction', 'out')->whereNull('read_at')->whereNotNull('provider_message_id')
            ->where('sent_at', '<=', CarbonImmutable::createFromTimestampMs((int) $read['watermark']))
            ->latest('sent_at')->limit(50)->pluck('provider_message_id');
        foreach ($rows as $mid) {
            if (is_string($mid) && $mid !== '') {
                $mids[] = $mid;
            }
        }

        return $mids;
    }

    /** @param array<string, mixed> $referral */
    private static function referralType(array $referral): ?string
    {
        return isset($referral['source']) ? mb_substr(strtolower((string) $referral['source']), 0, 20) : null;
    }

    /** @param array<string, mixed> $referral */
    private static function referralId(array $referral): ?string
    {
        $id = $referral['ad_id'] ?? $referral['ref'] ?? null;

        return is_scalar($id) ? mb_substr((string) $id, 0, 60) : null;
    }

    /** Suscribe la app a los eventos de mensajes de la página [verificar] campos con la app real. */
    public function subscribePage(string $pageId, string $token): ?string
    {
        // La suscripción reemplaza la lista de campos: se conservan los que la app ya tenía en la página (p. ej. `leadgen`
        // de Meta Lead Ads, app/Domain/Capture/LeadAds).
        $fields = array_values(array_unique(array_merge($this->subscribedFields($pageId, $token),
            ['messages', 'messaging_postbacks', 'message_deliveries', 'message_reads', 'messaging_referrals'])));
        try {
            $response = Http::withToken($token)->timeout(15)->acceptJson()->post($this->url($pageId.'/subscribed_apps'), [
                'subscribed_fields' => implode(',', $fields),
            ]);
        } catch (ConnectionException $e) {
            return $e->getMessage();
        }

        return $response->successful() ? null : $this->errorMessage($response);
    }

    /** @return list<string> los campos de la página a los que ya está suscrita esta app */
    private function subscribedFields(string $pageId, string $token): array
    {
        try {
            $response = Http::withToken($token)->timeout(15)->acceptJson()->get($this->url($pageId.'/subscribed_apps'));
        } catch (ConnectionException) {
            return [];
        }
        $appId = (string) config('igr-connections.meta.app_id');
        foreach ((array) $response->json('data', []) as $app) {
            if (is_array($app) && $appId !== '' && (string) ($app['id'] ?? '') === $appId) {
                return array_values(array_filter(array_map(fn ($f) => is_array($f) ? (string) ($f['name'] ?? '') : (string) $f, (array) ($app['subscribed_fields'] ?? []))));
            }
        }

        return [];
    }

    public function syncTemplates(ChannelAccount $account): int
    {
        return 0; // sin plantillas: fuera de la ventana no hay mensaje que se pueda aprobar
    }

    public function estimateCost(ChannelAccount $account, string $country, MessageKind $kind): ?float
    {
        return 0.0; // Meta no cobra los mensajes de Messenger ni de Instagram
    }

    /**
     * Messenger e Instagram (Send API): texto dentro de las 24 h (Messenger, con HUMAN_AGENT, una persona hasta 7 días).
     * El hub no manda botones ni archivos por aquí: las opciones salen numeradas. Messenger corta a 2.000 caracteres e
     * Instagram a 1.000 (`maxText`).
     */
    public function capabilities(): ChannelCapabilities
    {
        return new ChannelCapabilities(
            official: true, marketing: false, templates: false, freeText: true, buttons: false, lists: false,
            templateButtons: false, templateMediaHeader: false, media: [], location: false, contactCard: false, reactions: false,
            window24h: true, maxText: $this->maxText(), ratePerSecond: 20,
        );
    }

    /** Largo máximo de un texto en este canal. */
    protected function maxText(): int
    {
        return 2000;
    }

    /**
     * GET a la Graph API con el token de la página.
     *
     * @param  array<string, string>  $query
     */
    protected function graphGet(string $token, string $path, array $query): Response
    {
        return Http::withToken($token)->timeout(15)->acceptJson()->get($this->url($path), $query);
    }

    protected function url(string $path): string
    {
        return rtrim((string) config('igr-messaging.meta.graph_url'), '/').'/'.config('igr-messaging.meta.graph_version').'/'.ltrim($path, '/');
    }

    private function error(Response $response): SendResult
    {
        $code = $response->json('error.code');
        $code = is_scalar($code) ? (string) $code : (string) $response->status();
        $retryable = $response->serverError() || $response->status() === 429 || in_array($code, self::RETRYABLE, true);

        return SendResult::failed($this->errorMessage($response), $code, $retryable);
    }

    protected function errorMessage(Response $response): string
    {
        $message = $response->json('error.message');

        return is_string($message) ? $message : __('Meta respondió :status', ['status' => $response->status()]);
    }
}
