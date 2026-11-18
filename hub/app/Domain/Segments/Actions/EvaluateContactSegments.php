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
 * Recálculo en tiempo real para un solo contacto (hub/CLAUDE.md §6): evalúa
 * los segmentos dinámicos ya calculados con la consulta restringida a ese
 * contacto y emite segment.entered / segment.exited si cambió.
 */
final class EvaluateContactSegments
{
    public function __construct(
        private readonly SegmentCompiler $compiler,
        private readonly HubEvents $events,
        private readonly CurrentWorkspace $current,
    ) {}

    /**
     * @param  list<string>|null  $segmentIds  solo estos (null = todos los dinámicos)
     * @return array{entered: list<string>, exited: list<string>}
     */
    public function handle(string $contactId, ?array $segmentIds = null): array
    {
        $workspace = $this->current->get();
        $segments = Segment::query()
            ->where('kind', Segment::KIND_DYNAMIC)
            ->whereNotNull('definition')
            ->whereNotNull('last_computed_at')
            ->when($segmentIds !== null, fn ($q) => $q->whereKey($segmentIds))
            ->get();
        if ($segments->isEmpty()) {
            return ['entered' => [], 'exited' => []];
        }
        $memberOf = DB::table('segment_memberships')->where('workspace_id', $workspace->id)->where('contact_id', $contactId)
            ->pluck('segment_id')->flip();
        $ctx = CompileContext::forContact($workspace, $contactId);
        $entered = [];
        $exited = [];
        $emit = [];
        try {
            foreach ($segments as $segment) {
                $in = $this->compiler->query((array) $segment->definition, $ctx)->exists();
                $was = $memberOf->has($segment->id);
                if ($in === $was) {
                    continue;
                }
                if ($in) {
                    DB::table('segment_memberships')->insertOrIgnore([
                        'segment_id' => $segment->id, 'contact_id' => $contactId, 'workspace_id' => $workspace->id, 'entered_at' => now(),
                    ]);
                    $segment->increment('member_count');
                    $entered[] = $segment->publicId();
                } else {
                    DB::table('segment_memberships')->where('workspace_id', $workspace->id)->where('segment_id', $segment->id)->where('contact_id', $contactId)->delete();
                    $segment->decrement('member_count');
                    $exited[] = $segment->publicId();
                }
                $emit[] = [
                    'event' => $in ? 'segment.entered' : 'segment.exited',
                    'properties' => ['segment_id' => $segment->publicId(), 'segment_name' => mb_substr($segment->name, 0, 200), 'is_system' => $segment->is_system],
                    'contact_id' => $contactId,
                ];
            }
        } finally {
            $ctx->cleanup();
        }
        if ($emit !== []) {
            $this->events->emitMany($workspace, $emit);
        }

        return ['entered' => $entered, 'exited' => $exited];
    }
}
