<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Ai\Copilot\Support;

use App\Domain\Campaigns\Actions\ReviewCampaign;
use App\Domain\Campaigns\Models\Campaign;
use App\Domain\Segments\Compiler\CompileContext;
use App\Domain\Segments\Compiler\SegmentCompiler;
use App\Domain\Segments\Models\Segment;
use App\Domain\Workspaces\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Costo y revisión de cumplimiento de una campaña propuesta **sin crear nada**:
 * crea el segmento (si es nuevo, con sus miembros ya calculados) y la campaña dentro
 * de una transacción, corre la
 * misma revisión del paso 5 del asistente (`ReviewCampaign`: SendPolicy sin
 * reservar cupos, tarifas de WhatsApp, ventana legal por contacto) y deshace todo.
 */
final class CampaignDryRun
{
    /** @var array<string, array{cost: array<string, mixed>, compliance: array<string, mixed>}> */
    private array $memo = [];

    public function __construct(
        private readonly ReviewCampaign $review,
        private readonly SegmentCompiler $compiler,
    ) {}

    /**
     * @param  array<string, mixed>  $definition
     * @return array<int, string>
     */
    private function members(Workspace $workspace, array $definition): array
    {
        $ctx = new CompileContext($workspace, timeoutSeconds: 10);
        try {
            [$table] = $ctx->materializeMembers($this->compiler->query($definition, $ctx));

            return DB::table($table)->limit(200000)->pluck('contact_id')->map(fn ($id) => (string) $id)->values()->all();
        } finally {
            $ctx->cleanup();
        }
    }

    /**
     * @param  array<string, mixed>|null  $definition  AST validado (segmento nuevo) o null si es uno existente
     * @return array{cost: array<string, mixed>, compliance: array<string, mixed>}
     */
    public function run(Workspace $workspace, string $channel, ?string $segmentId, ?array $definition, string $sendMode, ?CarbonImmutable $sendAt, int $holdoutPct = 0): array
    {
        $key = md5((string) json_encode([$channel, $segmentId, $definition, $sendMode, $sendAt?->toIso8601String(), $holdoutPct]));
        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }
        // Los miembros de un segmento nuevo se calculan antes y fuera de la transacción: el
        // compilador usa tablas temporales y borrarlas confirma la transacción en MariaDB.
        $members = $definition !== null ? $this->members($workspace, $definition) : [];
        DB::beginTransaction();
        try {
            if ($definition !== null) {
                $segment = Segment::query()->create(['workspace_id' => $workspace->id, 'name' => 'copiloto', 'kind' => Segment::KIND_STATIC, 'is_system' => false, 'member_count' => count($members)]);
                foreach (array_chunk($members, 1000) as $chunk) {
                    DB::table('segment_memberships')->insert(array_map(fn (string $id) => ['segment_id' => $segment->id, 'contact_id' => $id, 'workspace_id' => $workspace->id, 'entered_at' => now()], $chunk));
                }
                $segmentId = $segment->publicId();
            }
            $campaign = Campaign::query()->create([
                'workspace_id' => $workspace->id, 'name' => 'copiloto', 'channel' => $channel,
                'audience' => ['segments_include' => array_values(array_filter([$segmentId])), 'segments_exclude' => [], 'all_contacts' => false, 'rules_include' => [], 'rules_exclude' => []],
                'send_mode' => $sendMode, 'send_at' => $sendAt, 'holdout_pct' => $holdoutPct,
            ]);
            $result = $this->review->handle($workspace, $campaign);
        } finally {
            DB::rollBack();
        }
        /** @var array{cost: array<string, mixed>, compliance: array<string, mixed>} $result */

        return $this->memo[$key] = $result;
    }

    /**
     * Resumen sin datos personales para el modelo y la propuesta.
     *
     * @param  array{cost: array<string, mixed>, compliance: array<string, mixed>}  $review
     * @return array<string, mixed>
     */
    public static function summary(array $review): array
    {
        $c = $review['compliance'];
        $cost = $review['cost'];

        return [
            'audience' => (int) ($c['audience'] ?? 0),
            'will_send' => (int) ($c['will_send'] ?? 0),
            'excluded' => (int) ($c['excluded_total'] ?? 0),
            'excluded_reasons' => array_map(fn ($r) => ['reason' => $r['label'] ?? $r['reason'], 'count' => (int) $r['count']], (array) ($c['excluded'] ?? [])),
            'deferred_reasons' => array_map(fn ($r) => ['reason' => $r['label'] ?? $r['reason'], 'count' => (int) $r['count']], (array) ($c['deferred'] ?? [])),
            'channel_available' => (bool) ($c['channel_available'] ?? false),
            'first_send_at' => $c['first_send_at'] ?? null,
            'cost_usd' => (float) ($cost['total_usd'] ?? 0),
            'cost_local' => $cost['total_local'] ?? null,
            'currency' => $cost['currency'] ?? null,
            'estimated' => (bool) ($c['estimated'] ?? false),
        ];
    }

    /** Para las pruebas: vuelve a medir. */
    public function forget(): void
    {
        $this->memo = [];
    }

    public static function safe(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (Throwable $e) {
            return ['error' => mb_substr($e->getMessage(), 0, 300)];
        }
    }
}
