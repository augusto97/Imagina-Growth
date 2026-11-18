<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Data;

use App\Domain\Messaging\Enums\Channel;
use App\Domain\Messaging\Enums\MessageKind;
use Carbon\CarbonImmutable;

/**
 * Un mensaje por enviar (hub/CLAUDE.md §8). Lo arman los flujos, las campañas y
 * los avisos del sistema; lo procesa MessageDispatcher.
 *
 * - `idempotencyKey` es obligatorio para flujos y campañas: `run:{run_id}:{node_id}:{attempt}`
 *   o `campaign:{campaign_id}:{contact_id}`. Con la misma clave nunca se envía dos veces.
 * - `context` son los datos de la plantilla además del contacto: `carrito`, `cupon`,
 *   `pedido`, `enlaces`… (los nombres de RenderContext::VARIABLES).
 * - `to`: destino explícito (correo o E.164) para avisos al equipo y el modo prueba;
 *   con `to` no se mira el consentimiento del contacto, pero sí la ventana legal.
 */
final class OutboundMessage
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly string $workspaceId,
        public readonly ?string $contactId,
        public readonly Channel $channel,
        public readonly MessageKind $kind,
        public readonly string $sourceType,             // flow | campaign | inbox | api | system
        public readonly ?string $sourceId = null,
        public readonly ?string $templateId = null,
        public readonly array $context = [],
        public readonly ?string $idempotencyKey = null,
        public readonly ?string $channelAccountId = null, // null: la cuenta por defecto del canal
        public readonly ?string $flowId = null,
        public readonly ?string $campaignId = null,
        public readonly ?string $to = null,
        public readonly bool $isTest = false,
        public readonly ?CarbonImmutable $notBefore = null,
        public readonly bool $humanAgent = false, // Fase 5: respuesta escrita por una persona de la bandeja (HUMAN_AGENT de Messenger)
    ) {}
}
