<?php

use App\Domain\Campaigns\Models\Campaign;
use App\Domain\Commerce\Models\Order;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Segments\Models\Segment;
use App\Domain\Services\Contracts\LeadIntake;
use App\Domain\Services\Data\LeadIntakeInput;
use App\Domain\Services\Leads\Models\Lead;
use App\Domain\Services\Support\NullLeadIntake;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/ApiHelpers.php';

// Recursos de la API pública (M19): eventos, pedidos, segmentos, campañas en borrador, leads y analítica.
beforeEach(function () {
    apiEntitlements();
    $this->ws = $this->workspaceFor($this->userWith2fa());
    [$this->site] = $this->connectedSite($this->ws);
    $this->in = fn (callable $fn) => app(CurrentWorkspace::class)->run($this->ws, $fn);
});

it('acepta eventos del servidor validados contra el contrato y los lleva a ClickHouse', function () {
    $this->useIngestInfrastructure();
    [$token] = apiToken($this->ws, ['events:write']);
    $res = $this->postJson('/api/v1/events', ['events' => [
        ['event' => 'custom.cotizacion_enviada', 'event_id' => '0193a7b2-6c1e-7f3a-9a51-2f6d1c0e4b70', 'identity' => ['email' => 'ana@ejemplo.com'], 'properties' => ['valor' => 120000]],
        ['event' => 'order.paid', 'properties' => []],
        ['event' => 'form.submitted', 'properties' => ['form_plugin' => 'externo']],
    ]], apiHeaders($token))->assertStatus(202);
    expect($res->json('accepted'))->toBe(1)
        ->and(collect($res->json('rejected'))->pluck('reason')->all())->toBe(['event_not_allowed', 'properties']);

    $this->runWorker();
    $rows = $this->chRows($this->ws, 'SELECT event, source_type, toString(event_id) AS id FROM events WHERE workspace_id = {workspace_id:UInt32}');
    expect($rows)->toHaveCount(1)->and($rows[0]['event'])->toBe('custom.cotizacion_enviada')->and($rows[0]['source_type'])->toBe('server')
        ->and($rows[0]['id'])->toBe('0193a7b2-6c1e-7f3a-9a51-2f6d1c0e4b70');

    $this->postJson('/api/v1/events', ['events' => [['event' => 'cart.updated']]], apiHeaders($token))->assertUnprocessable()->assertJsonPath('error.code', 'events_rejected');
});

it('lista y muestra pedidos con sus líneas, filtrados por contacto', function () {
    $contact = ($this->in)(fn () => Contact::query()->create(['workspace_id' => $this->ws->id, 'first_name' => 'Ana']));
    ($this->in)(function () use ($contact) {
        foreach ([['1001', $contact->id], ['1002', null]] as [$number, $cid]) {
            Order::query()->create(['workspace_id' => $this->ws->id, 'site_id' => $this->site->id, 'external_id' => $number, 'number' => $number, 'contact_id' => $cid,
                'status' => 'processing', 'is_paid' => true, 'currency' => 'COP', 'subtotal' => '100000.00', 'total' => '100000.00', 'total_ws' => '100000.00', 'refunded' => '0.00']);
        }
    });
    [$token] = apiToken($this->ws, ['orders:read']);
    expect($this->getJson('/api/v1/orders', apiHeaders($token))->assertOk()->json('data'))->toHaveCount(2);
    $mine = $this->getJson('/api/v1/orders?contact_id='.$contact->publicId(), apiHeaders($token))->assertOk()->json('data');
    expect($mine)->toHaveCount(1)->and($mine[0]['number'])->toBe('1001')->and($mine[0]['total'])->toBe('100000.00')->and($mine[0]['contact_id'])->toBe($contact->publicId());
    $this->getJson('/api/v1/orders/'.$mine[0]['id'], apiHeaders($token))->assertOk()->assertJsonPath('data.items', []);
});

it('lista segmentos con su número de miembros', function () {
    ($this->in)(fn () => Segment::query()->create(['workspace_id' => $this->ws->id, 'name' => 'VIP', 'kind' => 'dynamic', 'definition' => ['op' => 'and', 'conditions' => []], 'member_count' => 42]));
    [$token] = apiToken($this->ws, ['segments:read']);
    $this->getJson('/api/v1/segments', apiHeaders($token))->assertOk()->assertJsonPath('data.0.name', 'VIP')->assertJsonPath('data.0.member_count', 42);
});

it('crea campañas solo en borrador, con su audiencia y el enlace para aprobarlas', function () {
    $vip = ($this->in)(fn () => Segment::query()->create(['workspace_id' => $this->ws->id, 'name' => 'VIP', 'kind' => 'dynamic', 'definition' => ['op' => 'and', 'conditions' => []]]));
    [$token] = apiToken($this->ws, ['campaigns:write']);
    $res = $this->postJson('/api/v1/campaigns', ['name' => 'Regreso a clases', 'channel' => 'whatsapp', 'segment_ids' => [$vip->publicId()], 'status' => 'sending'], apiHeaders($token))
        ->assertCreated()->assertJsonPath('data.status', 'draft');
    expect($res->json('panel_url') ?? $res->json('data.panel_url'))->toContain('/campanas/');
    $campaign = ($this->in)(fn () => Campaign::query()->sole());
    expect($campaign->status)->toBe(Campaign::DRAFT)->and($campaign->audience['segments_include'])->toBe([$vip->id]);
    $this->postJson('/api/v1/campaigns', ['name' => 'X', 'channel' => 'email', 'segment_ids' => ['sg_01JD0000000000000000000000']], apiHeaders($token))
        ->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['segment_ids']]]);
});

it('crea leads con LeadIntake y mueve la etapa con su historia y eventos', function () {
    app()->instance(LeadIntake::class, new class implements LeadIntake
    {
        public function capture(LeadIntakeInput $input): ?string
        {
            $lead = Lead::query()->firstOrCreate(['workspace_id' => $input->workspaceId, 'origin' => $input->origin, 'origin_ref' => $input->originRef],
                ['contact_id' => $input->contactId, 'stage' => 'new', 'service' => $input->service, 'value_ws' => $input->value, 'source' => $input->source]);

            return $lead->publicId();
        }
    });
    [$token] = apiToken($this->ws, ['leads:read', 'leads:write']);
    $res = $this->postJson('/api/v1/leads', ['email' => 'ana@ejemplo.com', 'service' => 'Asesoría laboral', 'value' => 2500000, 'external_id' => 'crm-77', 'source' => ['channel' => 'referral']], apiHeaders($token))
        ->assertCreated()->assertJsonPath('data.stage', 'new')->assertJsonPath('data.service', 'Asesoría laboral')->assertJsonPath('data.external_crm_id', 'crm-77');
    $id = $res->json('data.id');
    $this->postJson('/api/v1/leads', ['email' => 'ana@ejemplo.com', 'external_id' => 'crm-77'], apiHeaders($token))->assertOk()->assertJsonPath('data.id', $id);

    $this->patchJson('/api/v1/leads/'.$id, ['stage' => 'inventada'], apiHeaders($token))->assertUnprocessable();
    $this->patchJson('/api/v1/leads/'.$id, ['stage' => 'won'], apiHeaders($token))->assertOk()->assertJsonPath('data.stage', 'won');
    expect($this->getJson('/api/v1/leads/'.$id, apiHeaders($token))->json('data.won_at'))->not->toBeNull();
    expect(DB::table('lead_activities')->where('kind', 'stage')->value('actor'))->toBe('api');
    expect($this->getJson('/api/v1/leads?stage=won', apiHeaders($token))->json('data'))->toHaveCount(1);
});

it('responde 409 si el módulo de leads no está activo', function () {
    app()->instance(LeadIntake::class, new NullLeadIntake);
    [$token] = apiToken($this->ws, ['leads:write']);
    $this->postJson('/api/v1/leads', ['email' => 'ana@ejemplo.com'], apiHeaders($token))->assertStatus(409)->assertJsonPath('error.code', 'leads_disabled');
});

it('consulta métricas de la capa semántica y los KPI del mes', function () {
    $this->useIngestInfrastructure();
    [$token] = apiToken($this->ws, ['analytics:read']);
    $this->getJson('/api/v1/analytics/catalog', apiHeaders($token))->assertOk()->assertJsonPath('metrics.0.key', 'revenue');
    $this->postJson('/api/v1/analytics/query', ['metrics' => ['revenue', 'orders'], 'dimensions' => ['channel'], 'from' => '2026-10-01', 'to' => '2026-10-31'], apiHeaders($token))
        ->assertOk()->assertJsonStructure(['query', 'currency', 'units', 'totals' => ['revenue', 'orders'], 'rows']);
    $this->postJson('/api/v1/analytics/query', ['metrics' => ['inventada'], 'from' => '2026-10-01', 'to' => '2026-10-31'], apiHeaders($token))
        ->assertUnprocessable()->assertJsonPath('error.code', 'invalid_query');
    $this->getJson('/api/v1/analytics/kpis?month=2026-10', apiHeaders($token))->assertOk()
        ->assertJsonPath('period.from', '2026-10-01')->assertJsonPath('period.to', '2026-10-31')->assertJsonStructure(['kpis' => ['revenue', 'orders', 'aov'], 'compare']);
});

it('las métricas web de Imagina Turbo entran como page.vitals (contrato v5) a ClickHouse', function () {
    $this->useIngestInfrastructure();
    [$token] = apiToken($this->ws, ['events:write']);
    $this->postJson('/api/v1/vitals', ['page' => 'https://tienda.co/', 'lcp_ms' => 2100, 'inp_ms' => 180, 'cls' => 0.05, 'device' => 'mobile', 'samples' => 300], apiHeaders($token))
        ->assertStatus(202)->assertJsonPath('accepted', 1);
    $this->postJson('/api/v1/vitals', ['page' => 'https://tienda.co/', 'lcp_ms' => 2100, 'inp_ms' => 180, 'cls' => 0.05, 'device' => 'tv'], apiHeaders($token))
        ->assertUnprocessable();

    $this->runWorker();
    $rows = $this->chRows($this->ws, 'SELECT event, source_type, toInt64(properties.lcp_ms) AS lcp FROM events WHERE workspace_id = {workspace_id:UInt32}');
    expect($rows)->toHaveCount(1)->and($rows[0]['event'])->toBe('page.vitals')->and($rows[0]['source_type'])->toBe('server')->and((int) $rows[0]['lcp'])->toBe(2100);
});
