<?php

use App\Domain\Api\Support\ApiContext;
use App\Domain\Campaigns\Models\Campaign;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Intelligence\Ai\Copilot\Models\AiDraft;
use App\Domain\Intelligence\Models\Insight;
use App\Domain\Mcp\GrowthServer;
use App\Domain\Mcp\Tools\DraftCampaignTool;
use App\Domain\Mcp\Tools\DraftSegmentTool;
use App\Domain\Mcp\Tools\GetContactSummaryTool;
use App\Domain\Mcp\Tools\GetKpisTool;
use App\Domain\Mcp\Tools\GetSetupStatusTool;
use App\Domain\Mcp\Tools\ListDeliveryIssuesTool;
use App\Domain\Mcp\Tools\ListInsightsTool;
use App\Domain\Mcp\Tools\ListSegmentsTool;
use App\Domain\Mcp\Tools\RunMetricQueryTool;
use App\Domain\Messaging\Models\Message;
use App\Domain\Segments\Models\Segment;
use App\Support\Tenancy\CurrentWorkspace;

require_once dirname(__DIR__).'/Api/ApiHelpers.php';

// Servidor MCP (M19): herramientas de lectura con el alcance del token y escrituras solo en borrador.
beforeEach(function () {
    apiEntitlements();
    $this->owner = $this->userWith2fa();
    $this->ws = $this->workspaceFor($this->owner);
    [$this->site] = $this->connectedSite($this->ws);
    $this->in = fn (callable $fn) => app(CurrentWorkspace::class)->run($this->ws, $fn);
    // Simula lo que hace AuthenticateApiClient antes de atender la llamada.
    $this->as = function (array $scopes): void {
        [, $client] = apiToken($this->ws, $scopes, 'mcp', 'Claude');
        request()->attributes->set(ApiContext::ATTRIBUTE, $client);
        app(CurrentWorkspace::class)->set($this->ws);
    };
});

it('lee KPI, métricas, segmentos y hallazgos con el alcance correcto', function () {
    $this->useIngestInfrastructure();
    ($this->as)(['analytics:read', 'segments:read']);
    ($this->in)(fn () => Segment::query()->create(['workspace_id' => $this->ws->id, 'name' => 'VIP', 'kind' => 'dynamic', 'definition' => ['op' => 'and', 'conditions' => []], 'member_count' => 9]));
    ($this->in)(fn () => Insight::query()->create(['workspace_id' => $this->ws->id, 'detector_key' => 'cod_pending', 'severity' => 'warning', 'title' => 'Contraentregas sin cobrar', 'facts' => ['n' => 3], 'status' => 'new', 'estimated_impact_ws' => '450000.00']));

    GrowthServer::tool(GetKpisTool::class, ['month' => '2026-10'])->assertOk()->assertSee(['"from":"2026-10-01"', '"revenue"']);
    GrowthServer::tool(RunMetricQueryTool::class, ['metrics' => ['orders'], 'from' => '2026-10-01', 'to' => '2026-10-31'])->assertOk()->assertSee('"totals"');
    GrowthServer::tool(RunMetricQueryTool::class, ['metrics' => ['inventada'], 'from' => '2026-10-01', 'to' => '2026-10-31'])->assertHasErrors();
    GrowthServer::tool(ListSegmentsTool::class, [])->assertOk()->assertSee(['VIP', '"member_count":9']);
    GrowthServer::tool(ListInsightsTool::class, [])->assertOk()->assertSee(['Contraentregas sin cobrar', '450000.00']);
});

it('sin el alcance la herramienta responde con un error claro', function () {
    ($this->as)(['segments:read']);
    GrowthServer::tool(GetKpisTool::class, [])->assertHasErrors(['analytics:read']);
    GrowthServer::tool(DraftCampaignTool::class, ['name' => 'X', 'channel' => 'email'])->assertHasErrors(['campaigns:write']);
    expect(($this->in)(fn () => Campaign::query()->count()))->toBe(0);
});

it('el resumen de contacto va enmascarado salvo con contacts:pii', function () {
    $contact = ($this->in)(fn () => Contact::query()->create(['workspace_id' => $this->ws->id, 'email' => 'ana@ejemplo.com', 'first_name' => 'Ana', 'orders_count' => 3]));
    ($this->as)(['contacts:read']);
    GrowthServer::tool(GetContactSummaryTool::class, ['contact_id' => $contact->publicId()])->assertOk()->assertSee('"orders_count":3')->assertDontSee('ana@ejemplo.com');
    ($this->as)(['contacts:read', 'contacts:pii']);
    GrowthServer::tool(GetContactSummaryTool::class, ['contact_id' => $contact->publicId()])->assertOk()->assertSee('ana@ejemplo.com');
    GrowthServer::tool(GetContactSummaryTool::class, ['email' => 'nadie@ejemplo.com'])->assertHasErrors();
});

it('draft_campaign y draft_segment solo crean borradores que se aprueban en el panel', function () {
    ($this->as)(['campaigns:write']);
    $vip = ($this->in)(fn () => Segment::query()->create(['workspace_id' => $this->ws->id, 'name' => 'VIP', 'kind' => 'dynamic', 'definition' => ['op' => 'and', 'conditions' => []]]));
    GrowthServer::tool(DraftCampaignTool::class, ['name' => 'Recompra noviembre', 'channel' => 'whatsapp', 'segment_ids' => [$vip->publicId()]])
        ->assertOk()->assertSee(['"status":"draft"', '/campanas/']);
    expect(($this->in)(fn () => Campaign::query()->sole()->status))->toBe(Campaign::DRAFT);

    $definition = ['op' => 'and', 'conditions' => [['type' => 'purchase', 'field' => 'orders_count', 'operator' => 'gte', 'value' => 2]]];
    GrowthServer::tool(DraftSegmentTool::class, ['name' => 'Recurrentes', 'definition' => ['op' => 'and', 'conditions' => [['type' => 'inventado']]]])->assertHasErrors();
    GrowthServer::tool(DraftSegmentTool::class, ['name' => 'Recurrentes', 'description' => 'Dos o más pedidos', 'definition' => $definition])
        ->assertOk()->assertSee(['"status":"draft"', 'integraciones?borrador=aid_']);
    $segments = fn () => ($this->in)(fn () => Segment::query()->where('name', 'Recurrentes')->count());
    expect($segments())->toBe(0);

    $draft = ($this->in)(fn () => AiDraft::query()->where('kind', 'segment')->sole());
    $api = '/api/w/'.$this->ws->publicId().'/integrations/mcp-drafts';
    $this->actingAs($this->owner)->getJson($api)->assertOk()->assertJsonPath('data.0.name', 'Recurrentes');
    $this->actingAs($this->owner)->postJson($api.'/'.$draft->publicId().'/approve')->assertOk()->assertJsonPath('data.status', 'applied');
    expect($segments())->toBe(1);
    $this->actingAs($this->owner)->postJson($api.'/'.$draft->publicId().'/approve')->assertUnprocessable();
});

it('el endpoint HTTP exige un token con alcance mcp y el plan con MCP', function () {
    $init = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => (object) [], 'clientInfo' => ['name' => 'prueba', 'version' => '1']]];
    $this->postJson('/api/mcp', $init)->assertUnauthorized();
    [$noMcp] = apiToken($this->ws, ['analytics:read']);
    $this->postJson('/api/mcp', $init, apiHeaders($noMcp))->assertForbidden();
    [$token] = apiToken($this->ws, ['analytics:read'], 'mcp', 'Claude');
    $this->postJson('/api/mcp', $init, apiHeaders($token))->assertOk()->assertJsonPath('result.serverInfo.name', 'Imagina Growth');
    $list = $this->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'], apiHeaders($token))->assertOk();
    expect(collect($list->json('result.tools'))->pluck('name')->all())->toContain('get_kpis', 'run_metric_query', 'draft_segment');
    apiEntitlements(deny: ['mcp']);
    $this->postJson('/api/mcp', $init, apiHeaders($token))->assertStatus(402);
});

it('dice cómo está configurado el negocio y qué mensajes no salieron, sin datos de contactos', function () {
    ($this->as)(['mcp']);
    GrowthServer::tool(GetSetupStatusTool::class, [])->assertOk()->assertSee(['"site"', '"channels"', '"missing"', '"operations"', '"panel_url"']);

    $contact = ($this->in)(fn () => Contact::query()->create(['workspace_id' => $this->ws->id, 'email' => 'luz@ejemplo.com', 'first_name' => 'Luz']));
    $make = fn (string $status, ?string $policy, ?string $error, ?string $detail) => ($this->in)(fn () => Message::query()->create([
        'workspace_id' => $this->ws->id, 'contact_id' => $contact->id, 'channel' => 'email', 'direction' => 'out', 'kind' => 'transactional',
        'source_type' => 'system', 'status' => $status, 'policy_reason' => $policy, 'error_code' => $error, 'failed_reason' => $detail,
    ]));
    $make('denied', 'no_account', null, null);
    $make('denied', 'no_account', null, null);
    $make('failed', null, 'smtp_auth', 'Expected response code 235 for luz@ejemplo.com but got 535');
    $make('sent', null, null, null);

    GrowthServer::tool(ListDeliveryIssuesTool::class, ['days' => 3])->assertOk()
        ->assertSee(['"reason":"no_account"', '"count":2', '"reason":"smtp_auth"', '"sent":1'])
        ->assertDontSee('luz@ejemplo.com');
});
