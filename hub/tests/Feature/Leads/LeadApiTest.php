<?php

require_once __DIR__.'/LeadsHelpers.php';

use App\Domain\Intelligence\Ai\Testing\FakeAiGateway;
use App\Domain\Messaging\Testing\FakeMessageDispatcher;
use App\Domain\Services\Leads\Models\LeadActivity;
use App\Domain\Services\Leads\Models\LeadSeller;
use App\Domain\Workspaces\Enums\WorkspaceRole;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    leadsSetUp($this);
    FakeAiGateway::install()->enabled = false;
    FakeMessageDispatcher::install();
    $this->base = "/api/w/{$this->ws->publicId()}/leads";
    $this->agent = $this->addMember($this->ws, WorkspaceRole::Agent);
});

it('muestra el tablero por etapas con conteo y valor, y la lista con filtros', function () {
    $mine = leadsRow($this, ['owner_id' => $this->agent->id, 'temperature' => 'hot', 'value_ws' => '1000000', 'service' => 'Asesoría laboral']);
    leadsRow($this, ['stage' => 'contacted', 'value_ws' => '500000', 'source' => ['channel' => 'paid_meta']]);
    leadsRow($this, ['stage' => 'won', 'won_at' => now(), 'value_ws' => '2000000']);

    $board = $this->actingAs($this->owner)->getJson($this->base.'/board')->assertOk();
    $cols = collect($board->json('columns'))->keyBy('key');
    expect($cols->keys()->all())->toBe(['new', 'contacted', 'qualified', 'proposal', 'won', 'lost'])
        ->and($cols['new']['count'])->toBe(1)->and($cols['new']['value'])->toBe('1000000.00')
        ->and($cols['won']['value'])->toBe('2000000.00')
        ->and($cols['new']['leads'][0]['contact']['name'])->toBe('Ana Ruiz')
        ->and($board->json('currency'))->toBe('COP');

    $list = fn (string $q) => $this->actingAs($this->owner)->getJson($this->base.'?'.$q)->assertOk()->json('data');
    expect($list('temperature=hot'))->toHaveCount(1)
        ->and($list('source=paid_meta'))->toHaveCount(1)
        ->and($list('service=laboral')[0]['id'])->toBe($mine->publicId())
        ->and($list('owner=none'))->toHaveCount(2)
        ->and($list('owner='.$this->agent->publicId()))->toHaveCount(1)
        ->and($list('status=open'))->toHaveCount(2)
        ->and($list('status=won'))->toHaveCount(1);
});

it('el agente solo ve y mueve sus leads, y no los reasigna; el lector no entra', function () {
    $mine = leadsRow($this, ['owner_id' => $this->agent->id]);
    $other = leadsRow($this);
    $board = $this->actingAs($this->agent)->getJson($this->base.'/board')->assertOk();
    expect(collect($board->json('columns'))->sum('count'))->toBe(1);
    $this->actingAs($this->agent)->getJson($this->base.'/'.$other->publicId())->assertNotFound();
    $this->actingAs($this->agent)->postJson($this->base.'/'.$mine->publicId().'/stage', ['stage' => 'contacted'])->assertOk()->assertJsonPath('stage', 'contacted');
    $this->actingAs($this->agent)->patchJson($this->base.'/'.$mine->publicId(), ['owner_id' => null])->assertForbidden();
    $this->actingAs($this->agent)->patchJson($this->base.'/'.$mine->publicId(), ['next_step' => 'Llamar el lunes', 'value' => 800000])->assertOk()
        ->assertJsonPath('next_step', 'Llamar el lunes')->assertJsonPath('value', '800000.00');
    $this->actingAs($this->agent)->putJson($this->base.'/settings', ['whatsapp_leads' => false])->assertForbidden();

    $viewer = $this->addMember($this->ws, WorkspaceRole::Viewer);
    $this->actingAs($viewer)->getJson($this->base.'/board')->assertForbidden();
});

it('detalle con razones del puntaje, historia, notas cifradas y reasignación con historia', function () {
    $contact = leadsContact($this);
    leadsForm($this, $contact);
    $lead = leadsOne($this);
    $url = $this->base.'/'.$lead->publicId();

    $this->actingAs($this->owner)->postJson($url.'/notes', ['body' => 'Llamó su abogado: quiere propuesta el martes'])->assertCreated();
    $raw = DB::table('lead_activities')->where('lead_id', $lead->id)->where('kind', 'note')->value('data');
    expect($raw)->not->toContain('abogado');

    $this->actingAs($this->owner)->patchJson($url, ['owner_id' => $this->agent->publicId()])->assertOk()->assertJsonPath('owner.id', $this->agent->publicId());
    $this->actingAs($this->owner)->patchJson($url, ['owner_id' => 'usr_01ABCDEFGHJKMNPQRSTVWXYZ00'])->assertUnprocessable();

    $res = $this->actingAs($this->owner)->getJson($url)->assertOk();
    expect(collect($res->json('score_reasons'))->pluck('signal')->all())->toBe(['form', 'corporate_email'])
        ->and($res->json('email'))->toBe('ana@ruizabogados.co')
        ->and($res->json('source.has_gclid'))->toBeTrue()
        ->and($res->json('source'))->not->toHaveKey('gclid')
        ->and(collect($res->json('activities'))->firstWhere('kind', 'note')['data']['body'])->toBe('Llamó su abogado: quiere propuesta el martes')
        ->and(collect($res->json('activities'))->pluck('kind')->all())->toContain('created', 'owner', 'note');
});

it('crea un lead a mano desde un contacto', function () {
    $contact = leadsContact($this);
    $this->actingAs($this->owner)->postJson($this->base, ['contact_id' => $contact->publicId(), 'service' => 'Consultoría', 'value' => 1200000])
        ->assertCreated()->assertJsonPath('origin', 'manual')->assertJsonPath('value', '1200000.00');
    $this->actingAs($this->owner)->postJson($this->base, ['contact_id' => 'ct_01ABCDEFGHJKMNPQRSTVWXYZ00'])->assertUnprocessable();
});

it('ajustes: reglas, umbrales, avisos, vendedores con WhatsApp cifrado y pipelines', function () {
    $this->actingAs($this->owner)->putJson($this->base.'/settings', [
        'scoring' => ['points' => ['form' => 40], 'thresholds' => ['warm' => 35, 'hot' => 70], 'service_cities' => ['Bogotá', ' Chía '], 'pricing_paths' => ['/tarifas']],
        'alerts' => ['on_new' => true, 'channel' => 'email'], 'assignment' => ['mode' => 'rules'], 'whatsapp_leads' => false,
    ])->assertOk()->assertJsonPath('scoring.points.form', 40)->assertJsonPath('scoring.points.corporate_email', 10)
        ->assertJsonPath('scoring.service_cities', ['Bogotá', 'Chía'])->assertJsonPath('alerts.on_new', true)->assertJsonPath('whatsapp_leads', false);
    $this->actingAs($this->owner)->putJson($this->base.'/settings', ['scoring' => ['thresholds' => ['warm' => 80, 'hot' => 70]]])->assertUnprocessable();
    $this->actingAs($this->owner)->putJson($this->base.'/settings', ['scoring' => ['pricing_paths' => ["/x'); DROP"]]])->assertUnprocessable();

    $res = $this->actingAs($this->owner)->postJson($this->base.'/sellers', ['user_id' => $this->agent->publicId(), 'phone' => '300 111 2233', 'services' => ['laboral']])->assertCreated();
    expect($res->json('sellers.0.phone'))->not->toContain('1112233');
    $raw = DB::table('lead_sellers')->value('phone');
    expect($raw)->not->toContain('3001112233');
    expect(leadsRun($this, fn () => LeadSeller::query()->first()->phone))->toBe('+573001112233');
    $this->actingAs($this->owner)->postJson($this->base.'/sellers', ['user_id' => $this->agent->publicId()])->assertUnprocessable();
    $seller = $res->json('sellers.0.id');
    $this->actingAs($this->owner)->putJson($this->base.'/sellers/'.$seller, ['active' => false, 'phone' => 'abc'])->assertUnprocessable();
    $this->actingAs($this->owner)->putJson($this->base.'/sellers/'.$seller, ['active' => false])->assertOk()->assertJsonPath('sellers.0.active', false);

    $stages = [['key' => 'new', 'label' => 'Nuevo', 'kind' => 'open'], ['key' => 'demo', 'label' => 'Demo', 'kind' => 'open'], ['key' => 'won', 'label' => 'Ganado', 'kind' => 'won'], ['key' => 'lost', 'label' => 'Perdido', 'kind' => 'lost']];
    $p = $this->actingAs($this->owner)->postJson($this->base.'/pipelines', ['name' => 'Empresas', 'stages' => $stages])->assertCreated()->json('id');
    $this->actingAs($this->owner)->postJson($this->base.'/pipelines', ['name' => 'Malo', 'stages' => [$stages[0], $stages[1], $stages[2]]])->assertUnprocessable();
    $this->actingAs($this->owner)->getJson($this->base.'/board?pipeline='.$p)->assertOk()->assertJsonCount(4, 'columns');
    $this->actingAs($this->owner)->deleteJson($this->base.'/pipelines/'.$p)->assertOk();
    $default = $this->actingAs($this->owner)->getJson($this->base.'/settings')->assertOk()->json('pipelines.0.id');
    $this->actingAs($this->owner)->deleteJson($this->base.'/pipelines/'.$default)->assertUnprocessable();
});

it('indicadores: leads por fuente, embudo, primera respuesta y valor ganado', function () {
    leadsRow($this, ['source' => ['channel' => 'paid_google'], 'stage' => 'won', 'won_at' => now(), 'value_ws' => '2000000', 'first_response_at' => now()->addMinutes(5)]);
    leadsRow($this, ['source' => ['channel' => 'paid_google'], 'stage' => 'contacted', 'first_response_at' => now()->addMinutes(25)]);
    $lost = leadsRow($this, ['source' => ['channel' => 'organic_search'], 'stage' => 'lost', 'lost_at' => now()]);
    leadsRun($this, fn () => LeadActivity::log($lost, 'stage', 'system', ['from' => 'new', 'to' => 'lost']));

    $k = $this->actingAs($this->owner)->getJson($this->base.'/kpis?days=30')->assertOk();
    expect($k->json('leads'))->toBe(3)
        ->and($k->json('conversion_rate'))->toEqual(0.3333)
        ->and($k->json('won'))->toBe(['count' => 1, 'value' => '2000000.00'])
        ->and($k->json('first_response'))->toMatchArray(['answered' => 2, 'unanswered' => 1, 'median_seconds' => 900, 'within_15m' => 1])
        ->and(collect($k->json('by_source'))->firstWhere('channel', 'paid_google'))->toMatchArray(['leads' => 2, 'won' => 1, 'won_value' => '2000000.00', 'conversion_rate' => 0.5, 'label' => 'Google Ads'])
        ->and(collect($k->json('funnel'))->pluck('leads', 'stage')->all())->toBe(['new' => 3, 'contacted' => 2, 'qualified' => 1, 'proposal' => 1, 'won' => 1, 'lost' => 1]);
});

it('no deja ver leads de otro espacio de trabajo', function () {
    $lead = leadsRow($this);
    $other = $this->userWith2fa();
    $ws2 = $this->workspaceFor($other, 'Otro');
    $this->actingAs($other)->getJson("/api/w/{$ws2->publicId()}/leads/{$lead->publicId()}")->assertNotFound();
    $this->actingAs($other)->postJson("/api/w/{$ws2->publicId()}/leads/{$lead->publicId()}/stage", ['stage' => 'won'])->assertNotFound();
    $this->actingAs($other)->getJson("/api/w/{$this->ws->publicId()}/leads/board")->assertNotFound();
});
