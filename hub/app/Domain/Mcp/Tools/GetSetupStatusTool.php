<?php

declare(strict_types=1);

namespace App\Domain\Mcp\Tools;

use App\Domain\Mcp\Support\McpContext;
use App\Domain\Workspaces\Queries\SetupStatus;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Cómo está configurado el negocio: tipo de negocio y plan, la web conectada, cada canal (WhatsApp oficial o no oficial, correo, SMS, Messenger, Instagram) con su estado, anuncios, agenda, el asistente de configuración, las estrategias prendidas y recomendadas con lo que les falta, la lista «missing» de lo que falta en orden de importancia y señales de operación (último trabajo de cola, latido del programador, correo de la plataforma configurado) que explican por qué no salen avisos. Solo hechos: nunca claves ni datos de contactos.')]
#[IsReadOnly]
final class GetSetupStatusTool extends Tool
{
    protected string $name = 'get_setup_status';

    public function handle(Request $request): Response
    {
        if (($denied = McpContext::deny('mcp')) !== null) {
            return $denied;
        }

        return Response::json(SetupStatus::for(McpContext::workspace()) + ['panel_url' => McpContext::panelUrl('')]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
