<?php

declare(strict_types=1);

namespace App\Domain\Segments\Actions;

use App\Domain\Segments\Models\Segment;
use App\Domain\Workspaces\Actions\RecordAuditLog;
use App\Domain\Workspaces\Models\User;
use App\Support\Phone\Phone;
use App\Support\Pii\BlindIndex;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Support\Facades\DB;

/**
 * Segmentos estáticos (lista fija): desde correos o teléfonos de un CSV,
 * buscados por índice ciego, o como foto de un segmento dinámico.
 */
final class CreateStaticSegment
{
    public function __construct(
        private readonly CurrentWorkspace $current,
        private readonly RecomputeSegment $stats,
        private readonly RecordAuditLog $audit,
    ) {}

    /**
     * @param  list<string>  $values  correos o teléfonos
     * @return array{segment: Segment, matched: int, unmatched: int}
     */
    public function fromValues(string $name, ?string $description, ?string $folder, array $values, User $user): array
    {
        $workspace = $this->current->get();
        $country = (string) $workspace->setting('default_phone_country', $workspace->country);
        $emailIdx = [];
        $phoneIdx = [];
        $valid = 0;
        foreach ($values as $raw) {
            $raw = trim($raw);
            if ($raw === '') {
                continue;
            }
            if (str_contains($raw, '@')) {
                if (filter_var($raw, FILTER_VALIDATE_EMAIL)) {
                    $emailIdx[] = BlindIndex::email($workspace->id, $raw);
                    $valid++;
                }

                continue;
            }
            $e164 = Phone::toE164($raw, $country);
            if ($e164 !== null) {
                $phoneIdx[] = BlindIndex::phone($workspace->id, $e164);
                $valid++;
            }
        }

        $ids = [];
        foreach (array_chunk(array_unique($emailIdx), 1000) as $chunk) {
            array_push($ids, ...DB::table('contacts')->where('workspace_id', $workspace->id)->whereNull('merged_into_id')->whereNull('deleted_at')->whereIn('email_bidx', $chunk)->pluck('id')->all());
        }
        foreach (array_chunk(array_unique($phoneIdx), 1000) as $chunk) {
            array_push($ids, ...DB::table('contacts')->where('workspace_id', $workspace->id)->whereNull('merged_into_id')->whereNull('deleted_at')->whereIn('phone_bidx', $chunk)->pluck('id')->all());
        }
        $ids = array_values(array_unique(array_map('strval', $ids)));

        $segment = $this->create($name, $description, $folder, $ids, $user, ['source' => 'csv', 'rows' => count($values), 'matched' => count($ids)]);

        return ['segment' => $segment, 'matched' => count($ids), 'unmatched' => max(0, count(array_filter($values, fn ($v) => trim($v) !== '')) - count($ids))];
    }

    public function snapshot(Segment $source, string $name, User $user): Segment
    {
        $ids = array_values(array_map('strval', DB::table('segment_memberships')->where('workspace_id', $this->current->id())->where('segment_id', $source->id)
            ->pluck('contact_id')->all()));

        return $this->create($name, __('Foto de «:name» del :date.', ['name' => $source->name, 'date' => now()->format('Y-m-d')]), $source->folder, $ids, $user, ['source' => 'snapshot', 'from' => $source->publicId()]);
    }

    /**
     * @param  list<string>  $ids
     * @param  array<string, mixed>  $auditChanges
     */
    private function create(string $name, ?string $description, ?string $folder, array $ids, User $user, array $auditChanges): Segment
    {
        $workspaceId = $this->current->id();
        $segment = DB::transaction(function () use ($name, $description, $folder, $ids, $user, $workspaceId): Segment {
            $segment = Segment::query()->create([
                'workspace_id' => $workspaceId,
                'name' => $name,
                'description' => $description,
                'folder' => $folder,
                'definition' => null,
                'kind' => Segment::KIND_STATIC,
                'is_system' => false,
                'created_by' => $user->id,
            ]);
            $at = now();
            foreach (array_chunk($ids, 1000) as $chunk) {
                DB::table('segment_memberships')->insertOrIgnore(array_map(fn (string $id) => [
                    'segment_id' => $segment->id, 'contact_id' => $id, 'workspace_id' => $workspaceId, 'entered_at' => $at,
                ], $chunk));
            }

            return $segment;
        });
        $this->stats->refreshStats($segment);
        $this->audit->handle('segment.created', $segment, ['name' => $name, 'kind' => 'static'] + $auditChanges, $workspaceId, null, $user->id);

        return $segment;
    }
}
