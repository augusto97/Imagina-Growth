<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Channels;

use App\Domain\Messaging\Channels\Data\OutgoingMessage;
use App\Domain\Messaging\Channels\Data\SendResult;
use App\Domain\Messaging\Channels\Data\WebhookBatch;
use App\Domain\Messaging\Enums\MessageKind;
use App\Domain\Messaging\Models\ChannelAccount;
use Illuminate\Http\Request;

/**
 * Un proveedor de mensajería (hub/CLAUDE.md §8). Los drivers no deciden si se
 * puede enviar (eso es SendPolicy) ni guardan nada: solo hablan con el proveedor.
 */
interface ChannelDriver
{
    public function send(ChannelAccount $account, OutgoingMessage $message): SendResult;

    /** Traduce un webhook ya verificado a estados, mensajes entrantes y avisos de la cuenta. */
    public function handleWebhook(Request $request, ?ChannelAccount $account): WebhookBatch;

    /** Trae las plantillas del proveedor (WhatsApp); devuelve cuántas sincronizó. */
    public function syncTemplates(ChannelAccount $account): int;

    /** Costo estimado en USD de un mensaje al país del destino, o null si no se sabe. */
    public function estimateCost(ChannelAccount $account, string $country, MessageKind $kind): ?float;

    /** Qué puede enviar el hub por este proveedor (botones, listas, plantillas, archivos…): ChannelCapabilities. */
    public function capabilities(): ChannelCapabilities;
}
