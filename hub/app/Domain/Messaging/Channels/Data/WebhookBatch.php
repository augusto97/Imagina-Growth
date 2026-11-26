<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Channels\Data;

/**
 * Todo lo que trae un webhook. `account` ayuda cuando el proveedor manda un solo
 * webhook para varias cuentas (Meta: phone_number_id en cada cambio).
 *
 * Coexistencia de WhatsApp (lote 4): `echoes` (lo que el negocio escribe desde la app del celular), `history` (el
 * historial copiado al conectar, por partes), `historyMedia` (los adjuntos de las dos últimas semanas del historial),
 * `contacts` (la libreta de la app) y `accountUpdates` (Meta quitó el número o la cuenta).
 *
 * `optOuts`: personas que se dieron de baja en el proveedor (Imagina Whats `contact.unsubscribed`): se revoca su
 * marketing de WhatsApp en el hub.
 */
final class WebhookBatch
{
    /**
     * @param  list<array{account: string|null, update: StatusUpdate}>  $statuses
     * @param  list<array{account: string|null, message: InboundMessage}>  $inbound
     * @param  list<array{account: string|null, meta_template_id: string|null, name: string|null, language: string|null, status: string, reason: string|null}>  $templates
     * @param  list<array{account: string|null, quality: string|null, tier: string|null, display_phone_number?: string|null}>  $quality
     * @param  list<array{account: string|null, message: BusinessAppMessage}>  $echoes
     * @param  list<array{account: string|null, phase: int|null, chunk: int|null, progress: int|null, declined: bool, messages: list<BusinessAppMessage>}>  $history
     * @param  list<array{account: string|null, message: BusinessAppMessage}>  $historyMedia
     * @param  list<array{account: string|null, contacts: list<array{phone: string|null, user_id: string|null, full_name: string|null, first_name: string|null, action: string}>}>  $contacts
     * @param  list<array{waba: string, event: string, reason: string|null}>  $accountUpdates
     * @param  list<array{account: string|null, phone: string}>  $optOuts
     */
    public function __construct(
        public array $statuses = [],
        public array $inbound = [],
        public array $templates = [],
        public array $quality = [],
        public ?string $challenge = null,
        public array $echoes = [],
        public array $history = [],
        public array $historyMedia = [],
        public array $contacts = [],
        public array $accountUpdates = [],
        public array $optOuts = [],
    ) {}
}
