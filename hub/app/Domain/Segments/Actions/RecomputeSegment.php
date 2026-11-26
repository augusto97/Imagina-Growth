<?php

declare(strict_types=1);

namespace App\Domain\Segments\Actions;

use App\Domain\Segments\Compiler\CompileContext;
use App\Domain\Segments\Compiler\SegmentCompiler;
use App\Domain\Segments\Models\Segment;
use App\Ingest\HubEvents;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Support\Facades\DB;

/**
 * Recalcula la membresía completa de un segmento dinámico (cada noche, al
 * guardarlo o a pedido): las diferencias producen segment.entered y
 * segment.exited. La primera vez que se calcula no se emiten eventos, para
 * no disparar flujos por todos los que ya cumplían la condición.
 */
final class RecomputeSegment
{
    public function __construct(
        private readonly SegmentCompiler $compiler,
        private readonly HubEvents $events,
        private readonly CurrentWorkspace $current,
    ) {}

    /** Contactos por evento emitido y por página al recorrer las diferencias. */
    private const EMIT_CHUNK = 500;

    /** @return array{members: int, entered: int, exited: int} */
    public function handle(Segment $segment, bool $emit = true): array
    {
        $workspace = $this->current->get();
        if (! $segment->isDynamic()) {
            $this->refreshStats($segment);

            return ['members' => $segment->member_count, 'entered' => 0, 'exited' => 0];
        }

        // R15: miembros y diferencias en tablas temporales de la sesión de MariaDB; PHP solo ve
        // páginas de 500 IDs al emitir los eventos (antes cargaba toda la membresía, dos veces).
        $ctx = new CompileContext($workspace, timeoutSeconds: 120);
        try {
            [$now, $members] = $ctx->materializeIds($this->compiler->query((array) $segment->definition, $ctx));
            $current = fn () => DB::table('segment_memberships as m')->where('m.workspace_id', $workspace->id)->where('m.segment_id', $segment->id);

            $entered = $ctx->idTable();
            $enteredCount = DB::table($entered)->insertUsing(['contact_id'], DB::table($now.' as n')->select('n.contact_id')
                ->whereNotExists($current()->selectRaw('1')->whereColumn('m.contact_id', 'n.contact_id')));
            $exited = $ctx->idTable();
            $exitedCount = DB::table($exited)->insertUsing(['contact_id'], $current()->select('m.contact_id')
                ->whereNotExists(DB::table($now.' as n')->selectRaw('1')->whereColumn('n.contact_id', 'm.contact_id')));
            $firstTime = $segment->last_computed_at === null;

            DB::transaction(function () use ($segment, $workspace, $entered, $exited, $enteredCount, $exitedCount): void {
                if ($enteredCount > 0) {
                    DB::table('segment_memberships')->insertOrIgnoreUsing(
                        ['segment_id', 'contact_id', 'workspace_id', 'entered_at'],
                        DB::table($entered)->selectRaw('?, contact_id, ?, ?', [$segment->id, $workspace->id, now()]),
                    );
                }
                if ($exitedCount > 0) {
                    DB::table('segment_memberships as m')->join($exited.' as x', 'x.contact_id', '=', 'm.contact_id')
                        ->where('m.workspace_id', $workspace->id)->where('m.segment_id', $segment->id)->delete();
                }
            });
            $this->refreshStats($segment);

            if ($emit && ! $firstTime) {
                $this->emit($segment, $entered, 'segment.entered');
                $this->emit($segment, $exited, 'segment.exited');
            }
        } finally {
            $ctx->cleanup();
        }

        return ['members' => $members, 'entered' => $enteredCount, 'exited' => $exitedCount];
    }

    /** Conteo, valor y contactos alcanzables por canal a partir de la membresía guardada. */
    public function refreshStats(Segment $segment): void
    {
        $workspaceId = $segment->workspace_id;
        $members = fn () => DB::table('contacts as c')
            ->join('segment_memberships as m', function ($j) use ($segment): void {
                $j->on('m.contact_id', '=', 'c.id')->where('m.segment_id', '=', $segment->id);
            })
            ->where('c.workspace_id', $workspaceId)
            ->where('m.workspace_id', $workspaceId)
            ->whereNull('c.merged_into_id')
            ->whereNull('c.deleted_at');

        $totals = $members()->selectRaw('COUNT(*) AS n, COALESCE(SUM(c.revenue_total_ws), 0) AS revenue')->first();
        $channels = [];
        foreach (['email', 'whatsapp'] as $channel) {
            $q = $members();
            $this->compiler->applyMarketingConsent($q, $channel);
            $channels[$channel] = $q->count();
        }

        $segment->forceFill([
            'member_count' => (int) ($totals->n ?? 0),
            'revenue_ws' => number_format((float) ($totals->revenue ?? 0), 2, '.', ''),
            'channel_counts' => $channels,
            'last_computed_at' => now(),
        ])->save();

        // El tamaño del día para la tendencia de la lista de grupos (SegmentTrends); el último cálculo del día gana.
        DB::table('segment_daily_counts')->upsert([[
            'segment_id' => $segment->id, 'day' => now()->toDateString(), 'workspace_id' => $workspaceId,
            'member_count' => (int) ($totals->n ?? 0), 'whatsapp_count' => $channels['whatsapp'], 'email_count' => $channels['email'], 'updated_at' => now(),
        ]], ['segment_id', 'day'], ['member_count', 'whatsapp_count', 'email_count', 'updated_at']);
    }

    /** Recorre la tabla temporal por páginas (clave, no OFFSET) y emite un lote por página. */
    private function emit(Segment $segment, string $table, string $event): void
    {
        $workspace = $this->current->get();
        $properties = ['segment_id' => $segment->publicId(), 'segment_name' => mb_substr($segment->name, 0, 200), 'is_system' => $segment->is_system];
        $after = '';
        do {
            /** @var list<string> $chunk */
            $chunk = DB::table($table)->where('contact_id', '>', $after)->orderBy('contact_id')->limit(self::EMIT_CHUNK)
                ->pluck('contact_id')->map(fn ($id) => (string) $id)->all();
            if ($chunk === []) {
                break;
            }
            $this->events->emitMany($workspace, array_map(fn (string $id) => ['event' => $event, 'properties' => $properties, 'contact_id' => $id], $chunk));
            $after = (string) end($chunk);
        } while (count($chunk) === self::EMIT_CHUNK);
    }
}
