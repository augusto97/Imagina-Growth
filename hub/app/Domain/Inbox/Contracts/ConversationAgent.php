<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Contracts;

use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\Message;

/**
 * El agente de ventas con IA de la bandeja (M11, inteligencia.md §6.7). La bandeja
 * lo llama con cada mensaje entrante de una conversación con `ai_mode` distinto de
 * `off`, y cuando un agente humano pide una sugerencia.
 *
 * - `auto`: responde él (mensaje de servicio dentro de la ventana de 24 h) o pasa
 *   la conversación a un humano.
 * - `assist`: deja una sugerencia que el humano revisa y envía.
 *
 * Por defecto `NullConversationAgent` (no hace nada). Lo implementa `SalesAgent`.
 */
interface ConversationAgent
{
    public function onInbound(Conversation $conversation, Message $inbound): void;

    /** Una respuesta sugerida para el último mensaje de la persona, o null si no hay qué sugerir. */
    public function suggest(Conversation $conversation): ?string;
}
