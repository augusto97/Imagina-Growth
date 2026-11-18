<?php

declare(strict_types=1);

namespace App\Domain\Mcp\Tools;

use App\Domain\Api\Http\V1\Presenters;
use App\Domain\Mcp\Support\McpContext;
use App\Domain\Segments\Models\Segment;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Lista los segmentos de contactos con su número de miembros y la venta de sus miembros. Sirve para elegir la audiencia de una campaña.')]
#[IsReadOnly]
final class ListSegmentsTool extends Tool
{
    protected string $name = 'list_segments';

    public function handle(Request $request): Response
    {
        if (($denied = McpContext::deny('segments:read')) !== null) {
            return $denied;
        }
        $limit = max(1, min(200, (int) ($request->get('limit') ?? 100)));
        $search = is_string($request->get('search')) ? trim((string) $request->get('search')) : '';
        $query = Segment::query()->orderByDesc('member_count')->limit($limit);
        if ($search !== '') {
            $query->where('name', 'like', '%'.addcslashes($search, '%_\\').'%');
        }

        return Response::json(['segments' => $query->get()->map(fn (Segment $s) => Presenters::segment($s))->values()->all()]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'search' => $schema->string()->description('Parte del nombre del segmento.'),
            'limit' => $schema->integer()->description('Máximo de segmentos (1 a 200).'),
        ];
    }
}
