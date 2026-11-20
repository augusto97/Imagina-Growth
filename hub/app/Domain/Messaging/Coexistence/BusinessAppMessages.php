<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Coexistence;

use App\Domain\Contacts\Models\Contact;
use App\Domain\Inbox\Models\MessageAttachment;
use App\Domain\Messaging\Channels\Data\BusinessAppMessage;
use App\Domain\Messaging\Enums\MessageKind;
use App\Domain\Messaging\Models\ChannelAccount;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\Message;
use App\Domain\Workspaces\Models\Workspace;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Cómo se guardan los mensajes de la app de WhatsApp Business de un número en coexistencia (lote 4):
 *
 * - `source_type = business_app`: lo que el negocio escribe desde el celular (`smb_message_echoes`), en el momento.
 * - `source_type = history`: el historial copiado al conectar (entrantes y salientes).
 *
 * Son mensajes de servicio que **no pasaron por la plataforma**: no van por `MessageDispatcher` ni `SendPolicy`
 * (no gastan cupos de frecuencia), Meta no los cobra y no abren ni alargan la ventana de 24 h de la Cloud API
 * (https://developers.facebook.com/documentation/business-messaging/whatsapp/embedded-signup/onboarding-business-app-users).
 * Por eso quedan sin `sent_at` (no cuentan en el uso del plan ni en la interacción), no emiten eventos `message.*`
 * (no disparan flujos) y se excluyen de la atribución y del tablero de mensajería. El texto va cifrado en
 * `payload` como el de cualquier mensaje. La clave `wa:{id de WhatsApp}` los hace idempotentes.
 */
final class BusinessAppMessages
{
    public const ECHO = 'business_app';

    public const HISTORY = 'history';

    /** @var list<string> */
    public const SOURCES = [self::ECHO, self::HISTORY];

    /** Quién firma el envío en la historia de la conversación: «Desde el celular». */
    public const ACTOR = 'business_app';

    public static function key(string $providerMessageId): string
    {
        return 'wa:'.$providerMessageId;
    }

    /** ¿Ya está guardado (reintento de Meta, o el mismo mensaje en el historial y en el eco)? */
    public static function known(string $providerMessageId): bool
    {
        return Message::query()->where('provider_message_id', $providerMessageId)->exists();
    }

    /** Guarda el mensaje en la conversación, o devuelve null si ya existía. Corre con el espacio de trabajo activo. */
    public static function store(Workspace $workspace, ChannelAccount $account, Contact $contact, Conversation $conversation, BusinessAppMessage $m, string $source): ?Message
    {
        $media = $m->mediaId !== null ? array_filter(['id' => $m->mediaId, 'mime' => $m->mediaMime]) : null;
        $message = new Message([
            'workspace_id' => $workspace->id,
            'contact_id' => $contact->id,
            'channel_account_id' => $account->id,
            'channel' => $account->channel,
            'provider' => $account->provider,
            'provider_message_id' => $m->providerMessageId,
            'direction' => $m->outbound ? 'out' : 'in',
            'kind' => MessageKind::Service,
            'source_type' => $source,
            'idempotency_key' => self::key($m->providerMessageId),
            'status' => $m->outbound ? 'sent' : 'received',
            'payload' => array_filter(['type' => $m->type, 'text' => $m->text, 'media' => $media, 'via' => BusinessAppContacts::ORIGIN], fn ($v) => $v !== null),
            'queued_at' => $m->at,
            'conversation_id' => $conversation->id,
        ]);
        $message->setCreatedAt($m->at);
        try {
            $message->save();
        } catch (UniqueConstraintViolationException) {
            return null;  // otro proceso guardó el mismo mensaje a la vez
        }
        if ($media !== null) {
            self::attach($workspace, $message, $m);
        }

        return $message;
    }

    /** Un mensaje editado o eliminado desde la app: cambia el texto guardado (cifrado) o lo borra. */
    public static function amend(BusinessAppMessage $m): void
    {
        if ($m->targetMessageId === null) {
            return;
        }
        $original = Message::query()->where('provider_message_id', $m->targetMessageId)->first();
        if ($original === null) {
            return;
        }
        $payload = (array) $original->payload;
        if ($m->type === 'revoke') {
            unset($payload['text'], $payload['media']);
            $payload['revoked'] = true;
        } elseif ($m->text !== null) {
            $payload['text'] = $m->text;
            $payload['edited'] = true;
        }
        $original->forceFill(['payload' => $payload])->save();
    }

    /** El adjunto del historial que llega después del marcador `media_placeholder` (solo de las dos últimas semanas). */
    public static function attachLater(Workspace $workspace, BusinessAppMessage $m): void
    {
        $message = Message::query()->where('provider_message_id', $m->providerMessageId)->first();
        if ($message === null || $m->mediaId === null) {
            return;
        }
        $payload = (array) $message->payload;
        $payload['type'] = $m->type;
        $payload['media'] = array_filter(['id' => $m->mediaId, 'mime' => $m->mediaMime]);
        if ($m->text !== null) {
            $payload['text'] = $m->text;
        }
        $message->forceFill(['payload' => $payload])->save();
        if (! MessageAttachment::query()->where('message_id', $message->id)->exists()) {
            self::attach($workspace, $message, $m);
        }
    }

    /**
     * Guarda el avance de la copia (historial o contactos) en la configuración cifrada de la cuenta, bajo candado:
     * las partes del historial pueden llegar a la vez.
     *
     * @param  callable(array<string, mixed>): array<string, mixed>  $update
     */
    public static function state(ChannelAccount $account, string $key, callable $update): void
    {
        DB::transaction(function () use ($account, $key, $update): void {
            $fresh = ChannelAccount::query()->whereKey($account->id)->lockForUpdate()->first();
            if ($fresh === null) {
                return;
            }
            $config = $fresh->config ?? [];
            $config[$key] = $update(is_array($config[$key] ?? null) ? $config[$key] : []);
            $fresh->forceFill(['config' => $config])->save();
        });
    }

    private static function attach(Workspace $workspace, Message $message, BusinessAppMessage $m): void
    {
        MessageAttachment::query()->create([
            'workspace_id' => $workspace->id, 'message_id' => $message->id, 'kind' => $m->type, 'mime' => $m->mediaMime,
            'provider_media_id' => $m->mediaId, 'caption' => $m->text, 'created_at' => now(),
        ]);
    }
}
