<?php

use App\Domain\Contacts\Models\Contact;
use App\Domain\Segments\Actions\RecomputeSegment;
use App\Domain\Segments\Models\Segment;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Support\Facades\DB;

/*
 * Tendencia de los grupos de clientes (modulos.md M04): cada cálculo de las cifras deja el tamaño del día y la lista
 * muestra el cambio de los últimos 30 días; sin dos días medidos no hay tendencia.
 */

beforeEach(function () {
    $this->useIngestInfrastructure();
    $this->owner = $this->userWith2fa();
    $this->ws = $this->workspaceFor($this->owner);
    $this->base = "/api/w/{$this->ws->publicId()}/segments";
});

it('guarda el tamaño del día al calcular y devuelve la tendencia de 30 días', function () {
    $ws = $this->ws;
    $run = fn (Closure $fn) => app(CurrentWorkspace::class)->run($ws, $fn);
    $segment = $run(fn () => Segment::query()->create(['workspace_id' => $ws->id, 'name' => 'Manizales', 'kind' => Segment::KIND_STATIC, 'is_system' => false]));
    foreach (['Ana', 'Luis'] as $name) {
        $c = $run(fn () => Contact::query()->create(['workspace_id' => $ws->id, 'first_name' => $name]));
        DB::table('segment_memberships')->insert(['segment_id' => $segment->id, 'contact_id' => $c->id, 'workspace_id' => $ws->id, 'entered_at' => now()]);
    }

    $run(fn () => app(RecomputeSegment::class)->refreshStats($segment));
    expect(DB::table('segment_daily_counts')->where('segment_id', $segment->id)->value('member_count'))->toBe(2);
    $this->actingAs($this->owner)->getJson($this->base.'/'.$segment->publicId())->assertOk()
        ->assertJsonPath('trend.points', [2])->assertJsonPath('trend.change', null);

    // Hace 20 días tenía 5 (y hace 40 no cuenta): bajó 3 en el periodo.
    DB::table('segment_daily_counts')->insert([
        ['segment_id' => $segment->id, 'day' => now()->subDays(20)->toDateString(), 'workspace_id' => $ws->id, 'member_count' => 5, 'whatsapp_count' => 0, 'email_count' => 0, 'updated_at' => now()],
        ['segment_id' => $segment->id, 'day' => now()->subDays(40)->toDateString(), 'workspace_id' => $ws->id, 'member_count' => 90, 'whatsapp_count' => 0, 'email_count' => 0, 'updated_at' => now()],
    ]);
    $row = collect($this->actingAs($this->owner)->getJson($this->base)->assertOk()->json('data'))->firstWhere('id', $segment->publicId());
    expect($row['trend'])->toBe(['points' => [5, 2], 'change' => -3, 'days' => 30]);

    // Al borrar el grupo se van sus filas diarias.
    $this->actingAs($this->owner)->deleteJson($this->base.'/'.$segment->publicId())->assertOk();
    expect(DB::table('segment_daily_counts')->where('segment_id', $segment->id)->count())->toBe(0);
});
