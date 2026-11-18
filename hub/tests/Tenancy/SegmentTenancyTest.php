<?php

use App\Analytics\Ch;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Segments\Models\Segment;
use App\Support\Pii\BlindIndex;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * hub/CLAUDE.md §4 aplicado a la segmentación: ni MariaDB ni ClickHouse ni
 * los endpoints dejan ver contactos o segmentos de otro espacio de trabajo.
 */

beforeEach(function () {
    $this->useIngestInfrastructure();
    foreach (['a', 'b'] as $k) {
        $owner = $this->userWith2fa();
        $ws = $this->workspaceFor($owner, "Tienda {$k}");
        $contact = app(CurrentWorkspace::class)->run($ws, fn () => Contact::query()->create([
            'workspace_id' => $ws->id, 'city' => 'Manizales', 'lifecycle_stage' => 'customer', 'orders_count' => 2,
        ]));
        $this->{$k} = compact('owner', 'ws', 'contact');
    }
    // Mismos anonymous_id en los dos espacios: el mapa de identidad no debe cruzarlos.
    $now = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.v');
    foreach (['a', 'b'] as $k) {
        app(Ch::class)->insert('events', [[
            'workspace_id' => $this->{$k}['ws']->ch_id, 'site_id' => 1, 'event_id' => (string) Str::uuid(), 'event' => 'page.viewed',
            'occurred_at' => $now, 'received_at' => $now, 'source_type' => 'browser', 'anonymous_id' => 'anon-compartido', 'contact_id' => '',
        ]]);
        app(Ch::class)->insert('identity_map', [['workspace_id' => $this->{$k}['ws']->ch_id, 'anonymous_id' => 'anon-compartido', 'contact_id' => $this->{$k}['contact']->id, 'linked_at' => $now]]);
    }
});

it('la vista previa y el cálculo solo ven contactos y eventos del propio espacio de trabajo', function () {
    $a = $this->a;
    $url = "/api/w/{$a['ws']->publicId()}/segments";
    $city = ['op' => 'and', 'conditions' => [['type' => 'attribute', 'field' => 'city', 'operator' => 'eq', 'value' => 'Manizales']]];
    $event = ['op' => 'and', 'conditions' => [['type' => 'event', 'event' => 'page.viewed', 'operator' => 'ever']]];

    $this->actingAs($a['owner'])->postJson($url.'/preview', ['definition' => $city])->assertJsonPath('count', 1)->assertJsonPath('sample.0.id', $a['contact']->publicId());
    $this->actingAs($a['owner'])->postJson($url.'/preview', ['definition' => $event])->assertJsonPath('count', 1)->assertJsonPath('sample.0.id', $a['contact']->publicId());

    $id = $this->actingAs($a['owner'])->postJson($url, ['name' => 'Vieron páginas', 'definition' => $event])->json('id');
    expect(DB::table('segment_memberships')->where('segment_id', substr($id, 3))->pluck('contact_id')->all())->toBe([$a['contact']->id])
        ->and(DB::table('segment_memberships')->where('segment_id', substr($id, 3))->pluck('workspace_id')->unique()->all())->toBe([$a['ws']->id]);
});

it('los segmentos de otro espacio de trabajo no existen por la API ni como referencia', function () {
    $a = $this->a;
    $b = $this->b;
    $segB = Segment::withoutWorkspaceScope()->where('workspace_id', $b['ws']->id)->where('system_key', 'rfm_champions')->firstOrFail();
    $urlA = "/api/w/{$a['ws']->publicId()}/segments";

    foreach (['', '/members', '/export'] as $suffix) {
        $this->actingAs($a['owner'])->get("{$urlA}/{$segB->publicId()}{$suffix}")->assertNotFound();
    }
    $this->actingAs($a['owner'])->patchJson("{$urlA}/{$segB->publicId()}", ['name' => 'robado'])->assertNotFound();
    $this->actingAs($a['owner'])->deleteJson("{$urlA}/{$segB->publicId()}")->assertNotFound();
    $this->actingAs($a['owner'])->postJson($urlA.'/preview', ['definition' => ['op' => 'and', 'conditions' => [['type' => 'segment', 'segment_id' => $segB->publicId(), 'operator' => 'in']]]])
        ->assertUnprocessable()->assertJsonValidationErrors('definition.conditions.0.segment_id');
    $this->actingAs($a['owner'])->getJson("/api/w/{$b['ws']->publicId()}/segments")->assertNotFound();

    $list = $this->actingAs($a['owner'])->getJson($urlA)->assertOk()->json('data');
    expect(collect($list)->pluck('id'))->not->toContain($segB->publicId());
});

it('un estático desde correos solo encuentra contactos del propio espacio de trabajo', function () {
    $a = $this->a;
    $b = $this->b;
    $email = 'misma@persona.co';
    foreach ([$a, $b] as $x) {
        DB::table('contacts')->where('id', $x['contact']->id)->update(['email_bidx' => BlindIndex::email($x['ws']->id, $email)]);
    }
    $res = $this->actingAs($a['owner'])->postJson("/api/w/{$a['ws']->publicId()}/segments/static", ['name' => 'Lista', 'values' => [$email]])->assertCreated();
    expect($res->json('matched'))->toBe(1);
    expect(DB::table('segment_memberships')->where('segment_id', substr($res->json('segment.id'), 3))->value('contact_id'))->toBe($a['contact']->id);
});
