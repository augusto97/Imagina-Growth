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

    /** @return array{members: int, entered: int, exited: int} */
    public function handle(Segment $segment, bool $emit = true): array
    {
        $workspace = $this->current->get();
        if (! $segment->isDynamic()) {
            $this->refreshStats($segment);

            return ['members' => $segment->member_count, 'entered' => 0, 'exited' => 0];
        }

        $ctx = new CompileContext($workspace, timeoutSeconds: 120);
        try {
            /** @var list<string> $ids */
            $ids = $this->compiler->query((array) $segment->definition, $ctx)->pluck('c.id')->map(fn ($id) => (string) $id)->all();
        } finally {
            $ctx->cleanup();
        }

        $current = DB::table('segment_memberships')->where('workspace_id', $workspace->id)->where('segment_id', $segment->id)
            ->pluck('contact_id')->map(fn ($id) => (string) $id)->all();
        $now = array_flip($ids);
        $before = array_flip($current);
        $entered = array_values(array_filter($ids, fn (string $id) => ! isset($before[$id])));
        $exited = array_values(array_filter($current, fn (string $id) => ! isset($now[$id])));
        $firstTime = $segment->last_computed_at === null;

        DB::transaction(function () use ($segment, $workspace, $entered, $exited): void {
            $at = now();
            foreach (array_chunk($entered, 1000) as $chunk) {
                DB::table('segment_memberships')->insertOrIgnore(array_map(fn (string $id) => [
                    'segment_id' => $segment->id, 'contact_id' => $id, 'workspace_id' => $workspace->id, 'entered_at' => $at,
                ], $chunk));
            }
            foreach (array_chunk($exited, 1000) as $chunk) {
                DB::table('segment_memberships')->where('workspace_id', $workspace->id)->where('segment_id', $segment->id)->whereIn('contact_id', $chunk)->delete();
            }
        });
        $this->refreshStats($segment);

        if ($emit && ! $firstTime) {
            $this->emit($segment, $entered, 'segment.entered');
            $this->emit($segment, $exited, 'segment.exited');
        }

        return ['members' => count($ids), 'entered' => count($entered), 'exited' => count($exited)];
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
    }

    /** @param list<string> $contactIds */
    private function emit(Segment $segment, array $contactIds, string $event): void
    {
        $workspace = $this->current->get();
        foreach (array_chunk($contactIds, 500) as $chunk) {
            $this->events->emitMany($workspace, array_map(fn (string $id) => [
                'event' => $event,
                'properties' => ['segment_id' => $segment->publicId(), 'segment_name' => mb_substr($segment->name, 0, 200), 'is_system' => $segment->is_system],
                'contact_id' => $id,
            ], $chunk));
        }
    }
}
