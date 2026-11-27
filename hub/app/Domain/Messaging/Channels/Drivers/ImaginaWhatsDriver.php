<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Channels\Drivers;

use App\Domain\Messaging\Channels\ChannelCapabilities;
use App\Domain\Messaging\Channels\ChannelDriver;
use App\Domain\Messaging\Channels\Data\InboundMessage;
use App\Domain\Messaging\Channels\Data\OutgoingMessage;
use App\Domain\Messaging\Channels\Data\SendResult;
use App\Domain\Messaging\Channels\Data\StatusUpdate;
use App\Domain\Messaging\Channels\Data\WebhookBatch;
use App\Domain\Messaging\Channels\ImaginaWhats\ImaginaWhatsClient;
use App\Domain\Messaging\Channels\ImaginaWhats\ImaginaWhatsError;
use App\Domain\Messaging\Enums\MessageKind;
use App\Domain\Messaging\Models\ChannelAccount;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Throwable;

/**
 * Imagina Whats (https://whats.imagina.cloud, API REST v1 y webhooks firmados): WhatsApp por WhatsApp Web, no
 * oficial (cumplimiento.md §5.2). Como Zender: conversaciones 1:1 y transaccionales de bajo volumen, topes fijos de la
 * plataforma y nunca marketing (SendPolicy lo niega antes; el driver también). Texto y archivos por URL (el encabezado
 * de una plantilla compuesta, `OutgoingMessage::$media`); sin plantillas de Meta, botones ni listas: llegan compuestas
 * como texto, con las opciones numeradas (ChannelCapabilities). Imagina Whats aplica además su calentamiento y su
 * ritmo humanizado, que no se apagan por API.
 *
 * - Envío: `POST /messages/whatsapp` con el número elegido (`wa_account_id`), `reference` = el ID del mensaje del hub
 *   e `Idempotency-Key` estable (un reintento no lo duplica). 202 no es «entregado»: los estados llegan por webhook.
 * - Webhook (ya verificado por `ImaginaWhatsWebhookController`): `wa.message.received` (solo de personas, no de grupos),
 *   `wa.message.sent|delivered|read|failed|canceled` y `contact.unsubscribed`.
 */
final class ImaginaWhatsDriver implements ChannelDriver
{
    public function send(ChannelAccount $account, OutgoingMessage $message): SendResult
    {
        if ($message->isMarketing) {
            return SendResult::failed(__('La conexión no oficial de WhatsApp no permite marketing.'), 'unofficial_marketing');
        }
        $client = ImaginaWhatsClient::for($account);
        $number = $account->credential('wa_account_id');
        if ($client === null || $number === null || ! ctype_digit($number)) {
            return SendResult::failed(__('Faltan los datos de Imagina Whats en la cuenta.'), 'not_configured');
        }
        $body = trim((string) $message->text);
        if ($body === '' && $message->media === null) {
            return SendResult::failed(__('El mensaje no tiene texto.'), 'empty');
        }
        try {
            // Con archivo (el encabezado de una plantilla compuesta): un solo mensaje con el texto como pie (Imagina Whats no
            // acepta pie en un audio; las plantillas de Meta tampoco llevan audio en el encabezado).
            $data = $message->media !== null
                ? $client->sendMedia((int) $number, $message->to, $message->media['url'], $message->media['type'] === 'audio' ? null : mb_substr($body, 0, 4096), $message->messageId, 'igr-'.$message->messageId)
                : $client->sendText((int) $number, $message->to, mb_substr($body, 0, 4096), $message->messageId, 'igr-'.$message->messageId);
        } catch (ImaginaWhatsError $e) {
            $until = self::blockedUntil($e);
            if ($until !== null) {
                return SendResult::later($until, $e->human(), $e->errorCode);
            }

            return SendResult::failed($e->human(), $e->errorCode, $e->retryable());
        }
        $id = $data['id'] ?? null;

        return is_scalar($id) && (string) $id !== ''
            ? SendResult::sent((string) $id)
            : SendResult::failed(__('Imagina Whats no devolvió el ID del mensaje.'), 'no_id');
    }

    public function handleWebhook(Request $request, ?ChannelAccount $account): WebhookBatch
    {
        $batch = new WebhookBatch;
        $event = $request->input('event');
        $payload = $request->input('payload');
        if (! is_string($event) || ! is_array($payload)) {
            return $batch;
        }
        $id = is_scalar($payload['id'] ?? null) ? (string) $payload['id'] : '';
        $at = fn (string ...$keys): CarbonImmutable => self::time($payload, array_values($keys)) ?? self::time(['sent_at' => $request->input('sent_at')], ['sent_at']) ?? CarbonImmutable::now();

        switch ($event) {
            case 'wa.message.received':
                $from = self::phone($payload['from'] ?? null);
                if ($from === null || $id === '' || ($payload['direction'] ?? 'in') !== 'in') {
                    break; // grupos, usuarios sin número o algo que no es de una persona
                }
                $batch->inbound[] = ['account' => null, 'message' => self::inbound($payload, $from, $id, $at('created_at'))];
                break;
            case 'wa.message.sent':
            case 'wa.message.delivered':
            case 'wa.message.read':
                $status = substr($event, strlen('wa.message.'));
                if ($id !== '') {
                    $batch->statuses[] = ['account' => null, 'update' => new StatusUpdate($id, $status, $at($status.'_at', 'created_at'))];
                }
                break;
            case 'wa.message.failed':
            case 'wa.message.canceled':
                if ($id !== '') {
                    $reason = is_string($payload['failure_reason'] ?? null) ? $payload['failure_reason'] : ($event === 'wa.message.canceled' ? 'canceled' : 'other');
                    $detail = $payload['error_message'] ?? $payload['error_reason'] ?? $payload['error'] ?? null;
                    $batch->statuses[] = ['account' => null, 'update' => new StatusUpdate($id, 'failed', $at('created_at'),
                        is_string($detail) && $detail !== '' ? mb_substr($detail, 0, 500) : __('Imagina Whats no pudo entregarlo (:reason).', ['reason' => $reason]), 'iw_'.$reason)];
                }
                break;
            case 'contact.unsubscribed':
                $phone = self::phone($payload['phone'] ?? null);
                if ($phone !== null) {
                    $batch->optOuts[] = ['account' => null, 'phone' => $phone];
                }
                break;
        }

        return $batch;
    }

    public function syncTemplates(ChannelAccount $account): int
    {
        return 0;
    }

    public function estimateCost(ChannelAccount $account, string $country, MessageKind $kind): float
    {
        return 0.0; // Imagina Whats cobra con sus créditos, no por mensaje de Meta.
    }

    /**
     * WhatsApp Web (API v1 de Imagina Whats, `POST /messages/whatsapp`): texto de hasta 4.096 caracteres y archivos
     * sueltos (imagen, video, audio o PDF por URL pública, con pie salvo el audio). No hay plantillas de Meta, botones ni
     * listas: las opciones salen numeradas en el texto. Ubicación, tarjeta de contacto y reacciones existen en su API,
     * pero el hub no las usa.
     */
    public function capabilities(): ChannelCapabilities
    {
        return new ChannelCapabilities(
            official: false, marketing: false, templates: false, freeText: true, buttons: false, lists: false,
            templateButtons: false, templateMediaHeader: false, media: ['image', 'video', 'document', 'audio'], location: false,
            contactCard: false, reactions: false, window24h: false, maxText: 4096, ratePerSecond: 1,
        );
    }

    /** Hasta cuándo Imagina Whats no recibe (tope diario de calentamiento o de la clave): se aplaza, no falla. */
    private static function blockedUntil(ImaginaWhatsError $e): ?CarbonImmutable
    {
        if ($e->errorCode !== 'rate_limited') {
            return null;
        }
        $reason = $e->details['reason'] ?? null;
        $limit = $e->details['limit'] ?? null;
        if ($reason !== 'wa_warmup_cap_reached' && $limit !== 'api_key_daily_sends') {
            return null; // un límite por minuto: el job reintenta en segundos
        }
        if (is_string($e->details['reset_at'] ?? null)) {
            try {
                return CarbonImmutable::parse($e->details['reset_at'])->utc()->max(CarbonImmutable::now()->addMinute());
            } catch (Throwable) {
            }
        }
        if ($e->retryAfter !== null && $e->retryAfter > 0) {
            return CarbonImmutable::now()->addSeconds(min($e->retryAfter, 2 * 86400));
        }

        return CarbonImmutable::now()->addDay()->startOfDay()->addHours(8);
    }

    /** Un teléfono de persona en E.164 (no un grupo `…@g.us` ni un usuario). */
    private static function phone(mixed $raw): ?string
    {
        if (! is_string($raw) || str_contains($raw, '@')) {
            return null;
        }
        $digits = preg_replace('/\D/', '', $raw) ?? '';

        return strlen($digits) >= 8 && strlen($digits) <= 15 ? '+'.$digits : null;
    }

    /** @param array<string, mixed> $payload */
    private static function inbound(array $payload, string $from, string $id, CarbonImmutable $at): InboundMessage
    {
        $attachment = is_array($payload['attachments'][0] ?? null) ? $payload['attachments'][0] : null;
        $body = is_string($payload['body'] ?? null) && trim($payload['body']) !== '' ? $payload['body'] : null;
        $mime = is_string($attachment['mime'] ?? null) ? $attachment['mime'] : null;
        $type = $attachment === null ? 'text' : match (true) {
            str_starts_with((string) $mime, 'image/') => ($mime === 'image/webp' ? 'sticker' : 'image'),
            str_starts_with((string) $mime, 'audio/') => 'audio',
            str_starts_with((string) $mime, 'video/') => 'video',
            default => 'document',
        };
        // Una nota de voz sin texto: su transcripción (si Imagina Whats la trae) es lo que leen la bandeja y el agente.
        $transcript = is_string($attachment['transcript'] ?? null) && trim($attachment['transcript']) !== '' ? $attachment['transcript'] : null;
        $sender = $payload['sender'] ?? null;
        $name = is_string($sender) ? $sender : (is_array($sender) && is_string($sender['name'] ?? null) ? $sender['name'] : null);
        $replyTo = $payload['reply_to'] ?? null;
        $replyTo = is_array($replyTo) ? ($replyTo['id'] ?? null) : $replyTo;

        return new InboundMessage(
            from: $from,
            providerMessageId: $id,
            type: $type,
            at: $at,
            text: $attachment === null ? $body : ($body ?? $transcript),
            contextMessageId: is_scalar($replyTo) ? (string) $replyTo : null,
            profileName: $name !== null && trim($name) !== '' && ! str_starts_with(trim($name), '+') ? mb_substr(trim($name), 0, 120) : null,
            mediaMime: $mime,
            caption: $attachment !== null ? $body : null,
            mediaUrl: is_string($attachment['url'] ?? null) ? $attachment['url'] : null,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $keys
     */
    private static function time(array $payload, array $keys): ?CarbonImmutable
    {
        foreach ($keys as $key) {
            if (is_string($payload[$key] ?? null) && $payload[$key] !== '') {
                try {
                    return CarbonImmutable::parse($payload[$key])->utc();
                } catch (Throwable) {
                }
            }
        }

        return null;
    }
}
