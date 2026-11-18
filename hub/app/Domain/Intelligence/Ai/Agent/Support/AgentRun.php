<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Ai\Agent\Support;

use App\Domain\Contacts\Models\Contact;
use App\Domain\Intelligence\Ai\Agent\Models\AgentSettings;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Sites\Models\Site;
use App\Domain\Workspaces\Models\Workspace;

/**
 * Estado de un turno del agente: con quién habla (el contacto verificado del chat,
 * nunca un dato que la persona escriba), en qué sitio consulta y lo que las
 * herramientas dejaron hecho (traspaso pedido, carrito armado).
 */
final class AgentRun
{
    public ?string $handoffReason = null;

    public ?string $cartId = null;

    public function __construct(
        public readonly Workspace $workspace,
        public readonly AgentSettings $settings,
        public readonly ?Site $site,
        public readonly ?Conversation $conversation,
        public readonly ?Contact $contact,
        public readonly bool $isTest = false,
    ) {}
}
