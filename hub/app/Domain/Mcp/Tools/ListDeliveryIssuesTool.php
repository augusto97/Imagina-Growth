<?php

declare(strict_types=1);

namespace App\Domain\Mcp\Tools;

use App\Domain\Intelligence\Ai\Support\PiiScrubber;
use App\Domain\Mcp\Support\McpContext;
use App\Domain\Messaging\Models\Message;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Carbon;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Los mensajes salientes que no llegaron en los últimos días (fallidos, rebotados, negados por la política de envío o aplazados), agrupados por canal, origen (flujo, campaña, bandeja, sistema) y motivo, con cuántos y cuándo fue el último, más el total por estado para comparar. Sirve para responder «¿por qué no le llegó el aviso al cliente?». Sin destinatarios ni datos de contactos; el detalle del proveedor va sin correos ni teléfonos.')]
#[IsReadOnly]
final class ListDeliveryIssuesTool extends Tool
{
    protected string $name = 'list_delivery_issues';

    private const PROBLEMS = ['failed', 'bounced', 'denied', 'deferred'];

    public function handle(Request $request): Response
    {
        // Cualquier token del servidor MCP: son metadatos de envío, sin destinatarios ni contenido.
        if (($denied = McpContext::deny('mcp')) !== null) {
            return $denied;
        }
        $days = max(1, min(30, (int) ($request->get('days') ?? 7)));
        $since = now()->subDays($days);
        $out = Message::query()->where('direction', 'out')->where('created_at', '>=', $since);

        $totals = (clone $out)->toBase()->selectRaw('status, COUNT(*) AS n')->groupBy('status')->pluck('n', 'status')->map(fn ($n): int => (int) $n)->all();
        $groups = (clone $out)->whereIn('status', self::PROBLEMS)->toBase()
            ->selectRaw('status, channel, source_type, COALESCE(policy_reason, error_code) AS reason, COUNT(*) AS n, MAX(created_at) AS last_at, MAX(id) AS last_id')
            ->groupBy('status', 'channel', 'source_type', 'reason')
            ->orderByDesc('n')->limit(50)->get();
        $details = Message::query()->whereIn('id', $groups->pluck('last_id')->all())->pluck('failed_reason', 'id');

        $issues = $groups->map(fn (\stdClass $g): array => [
            'status' => $g->status,
            'channel' => $g->channel,
            'source' => $g->source_type,
            'reason' => $g->reason,
            'count' => (int) $g->n,
            'last_at' => $g->last_at !== null ? Carbon::parse($g->last_at)->toIso8601String() : null,
            'last_detail' => is_string($detail = $details[$g->last_id] ?? null) && $detail !== '' ? mb_substr((string) PiiScrubber::scrub($detail), 0, 300) : null,
        ])->values()->all();

        return Response::json(['days' => $days, 'since' => $since->toIso8601String(), 'totals_by_status' => $totals, 'issues' => $issues]);
    }

    public function schema(JsonSchema $schema): array
    {
        return ['days' => $schema->integer()->description('Cuántos días hacia atrás (1 a 30; 7 por defecto).')];
    }
}
