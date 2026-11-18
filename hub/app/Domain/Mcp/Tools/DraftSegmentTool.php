<?php

declare(strict_types=1);

namespace App\Domain\Mcp\Tools;

use App\Domain\Intelligence\Ai\Copilot\Models\AiDraft;
use App\Domain\Mcp\Support\McpContext;
use App\Domain\Segments\Definition\DefinitionValidator;
use App\Domain\Segments\Definition\InvalidDefinition;
use App\Domain\Workspaces\Actions\RecordAuditLog;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Propone un segmento nuevo como BORRADOR: nombre, descripción y la definición (grupos de condiciones del constructor de segmentos de Imagina Growth). Se valida pero no se crea: alguien del equipo lo aprueba en el panel en el enlace que devuelve.')]
final class DraftSegmentTool extends Tool
{
    protected string $name = 'draft_segment';

    public function handle(Request $request, DefinitionValidator $validator, RecordAuditLog $audit): Response
    {
        if (($denied = McpContext::deny('campaigns:write')) !== null) {
            return $denied;
        }
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:160'],
            'description' => ['nullable', 'string', 'max:1000'],
            'definition' => ['required', 'array'],
        ]);
        try {
            $definition = $validator->validate((array) $data['definition']);
        } catch (InvalidDefinition $e) {
            return Response::error(__('La definición no es válida: :error', ['error' => implode(' · ', array_map(fn ($k, $v) => ($k !== '' ? $k.': ' : '').$v, array_keys($e->errors), $e->errors))]));
        }
        $workspace = McpContext::workspace();
        $client = McpContext::client();
        $draft = AiDraft::query()->create([
            'workspace_id' => $workspace->id,
            'user_id' => null,
            'kind' => 'segment',
            'prompt' => mb_substr((string) ($data['description'] ?? $data['name']), 0, 1000),
            'proposal' => ['name' => (string) $data['name'], 'description' => $data['description'] ?? null, 'definition' => $definition, 'source' => 'mcp', 'client_id' => $client?->publicId()],
            'status' => AiDraft::DRAFT,
            'model' => 'mcp',
        ]);
        $audit->handle('segment.drafted_by_mcp', $draft, ['name' => (string) $data['name'], 'client' => $client?->publicId()], $workspace->id);

        return Response::json([
            'draft_id' => $draft->publicId(),
            'status' => 'draft',
            'approval_url' => McpContext::panelUrl('integraciones?borrador='.$draft->publicId()),
            'note' => __('Es un borrador: el segmento no existe hasta que alguien lo apruebe en el panel.'),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->max(160)->description('Nombre del segmento.')->required(),
            'description' => $schema->string()->max(1000)->description('Para qué sirve.'),
            'definition' => $schema->object()->description('Definición del constructor de segmentos (AST): {"op":"and","conditions":[{"type":"purchase","field":"orders_count","operator":"gte","value":2},{"type":"attribute","field":"city","operator":"eq","value":"Medellín"}]}. Tipos: attribute, purchase, event, cart, prediction, consent, segment, messaging, loyalty; grupos anidados con op and/or.')->required(),
        ];
    }
}
