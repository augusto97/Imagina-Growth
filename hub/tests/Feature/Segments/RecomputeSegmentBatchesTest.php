<?php

use App\Domain\Segments\Actions\RecomputeSegment;
use App\Domain\Segments\Models\Segment;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Support\Facades\DB;

/*
 * R15 de la auditoría: RecomputeSegment calcula la membresía y sus diferencias en tablas temporales de MariaDB y
 * emite segment.entered / segment.exited por páginas de 500, sin cargar la membresía completa en PHP.
 */

beforeEach(function () {
    $this->useIngestInfrastructure();
    $this->ws = $this->workspaceFor($this->userWith2fa());
    $this->other = $this->workspaceFor($this->userWith2fa(['email' => 'otra@agencia.co']), 'Otra');
});

function cityContacts(string $workspaceId, string $prefix, int $count, string $city): void
{
    DB::statement("INSERT INTO contacts (id, workspace_id, lifecycle_stage, city, created_at, updated_at)
        SELECT CONCAT(?, LPAD(seq, 20, '0')), ?, 'customer', ?, NOW(), NOW() FROM seq_1_to_{$count}", [$prefix, $workspaceId, $city]);
}

it('calcula, emite las diferencias por páginas y no toca otro espacio de trabajo', function () {
    cityContacts($this->ws->id, '01SEGA', 700, 'Pereira');
    cityContacts($this->other->id, '01SEGO', 300, 'Pereira');
    $segment = app(CurrentWorkspace::class)->run($this->ws, fn () => Segment::query()->create(['workspace_id' => $this->ws->id, 'name' => 'Pereira', 'kind' => Segment::KIND_DYNAMIC,
        'definition' => ['op' => 'and', 'conditions' => [['type' => 'attribute', 'field' => 'city', 'operator' => 'eq', 'value' => 'Pereira']]]]));
    $recompute = fn () => app(CurrentWorkspace::class)->run($this->ws, fn () => app(RecomputeSegment::class)->handle($segment->fresh()));

    // Primer cálculo: todos entran, pero no se emite nada (no dispara flujos con toda la base).
    expect($recompute())->toBe(['members' => 700, 'entered' => 700, 'exited' => 0]);
    $events = fn (string $e) => (int) $this->chRows($this->ws, 'SELECT count() AS n FROM events WHERE workspace_id = {workspace_id:UInt32} AND event = {e:String}', ['e' => $e])[0]['n'];
    expect($events('segment.entered'))->toBe(0);

    // 1.234 nuevos (tres páginas de 500) y 250 que se van.
    cityContacts($this->ws->id, '01SEGB', 1234, 'Pereira');
    DB::table('contacts')->where('workspace_id', $this->ws->id)->where('id', 'like', '01SEGA%')->orderBy('id')->limit(250)->update(['city' => 'Cali']);
    expect($recompute())->toBe(['members' => 1684, 'entered' => 1234, 'exited' => 250])
        ->and($events('segment.entered'))->toBe(1234)
        ->and($events('segment.exited'))->toBe(250)
        ->and(DB::table('segment_memberships')->where('segment_id', $segment->id)->count())->toBe(1684)
        ->and(DB::table('segment_memberships')->where('segment_id', $segment->id)->where('workspace_id', '!=', $this->ws->id)->count())->toBe(0)
        ->and($segment->fresh()->member_count)->toBe(1684);

    // Sin cambios: nada que emitir.
    expect($recompute())->toBe(['members' => 1684, 'entered' => 0, 'exited' => 0])->and($events('segment.entered'))->toBe(1234);
    $this->assertSame(0, (int) $this->chRows($this->other, 'SELECT count() AS n FROM events WHERE workspace_id = {workspace_id:UInt32}')[0]['n']);
});
