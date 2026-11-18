<?php

use App\Analytics\Semantic\MetricQueryRunner;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Intelligence\Ai\Features\AskData\MetricQueryTool;
use App\Domain\Intelligence\Ai\Features\Models\AskDataQuery;
use App\Domain\Intelligence\Ai\Features\Summaries\ProfileSummary;
use App\Domain\Intelligence\Ai\Testing\FakeAiGateway;
use App\Domain\Intelligence\Insights\Money;
use App\Domain\Reports\WeeklyReport;
use App\Domain\Segments\Models\Segment;
use App\Domain\Workspaces\Enums\WorkspaceRole;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Tenancy\CurrentWorkspace;

beforeEach(function () {
    $this->useIngestInfrastructure();
    $this->artisan('igr:seed-demo', ['--customers' => 30, '--orders' => 150, '--process' => true, '--browsing-months' => 1])->assertSuccessful();
    $this->ws = Workspace::query()->where('name', 'Pinturas Demo')->firstOrFail();
    $this->owner = $this->addMember($this->ws, WorkspaceRole::Owner, true);
    $this->api = "/api/w/{$this->ws->publicId()}";
    $week = WeeklyReport::lastWeekStart($this->ws);
    $this->input = ['metrics' => ['revenue', 'orders'], 'dimensions' => ['date'], 'from' => $week->subWeeks(3)->toDateString(), 'to' => $week->addDays(6)->toDateString(),
        'compare' => 'previous', 'granularity' => 'week', 'channel' => null, 'device' => null, 'city' => null, 'limit' => 50];
});

/** Lo que devolvería la consulta, para que la respuesta simulada cite cifras reales. */
function analystTotals($test, array $input): array
{
    $tool = new MetricQueryTool($test->ws, app(MetricQueryRunner::class));

    return app(CurrentWorkspace::class)->run($test->ws, fn () => $tool->run($input))['totales'];
}

it('responde con la consulta que armó el modelo: cifras de los resultados, tabla y gráfico de línea', function () {
    $totals = analystTotals($this, $this->input);
    $answer = 'En esas 4 semanas vendiste '.Money::format($totals['revenue'], 'COP').' en '.$totals['orders'].' pedidos.';
    $ai = FakeAiGateway::install()->callTool('run_metric_query', $this->input)->reply($answer);

    $res = $this->actingAs($this->owner)->postJson("{$this->api}/ai/ask", ['question' => '¿Cuánto vendí en las últimas 4 semanas, por semana?'])->assertCreated();
    expect($res->json('status'))->toBe('answered')->and($res->json('answer'))->toBe($answer)->and($res->json('source'))->toBe('ai')
        ->and($res->json('result.visual'))->toBe('line')
        ->and($res->json('result.columns.0.key'))->toBe('date')
        ->and($res->json('result.totals.orders'))->toBe($totals['orders'])
        ->and($res->json('query.metrics'))->toBe(['revenue', 'orders'])
        ->and($ai->requests[0]['tools'])->toBe(['run_metric_query'])
        ->and($ai->requests[0]['request']->messages[0]['content'])->toContain('Hoy es');
    $this->actingAs($this->owner)->getJson("{$this->api}/ai/ask")->assertOk()->assertJsonCount(1, 'data');
});

it('una métrica que no existe vuelve al modelo como error y la pregunta queda sin respuesta inventada', function () {
    FakeAiGateway::install()->callTool('run_metric_query', ['metrics' => ['ganancias']] + $this->input)
        ->reply('No tengo una métrica de ganancias; puedo mostrarte ventas netas.');
    $res = $this->actingAs($this->owner)->postJson("{$this->api}/ai/ask", ['question' => '¿Cuál fue mi ganancia?'])->assertCreated();
    expect($res->json('status'))->toBe('unsupported')->and($res->json('result'))->toBeNull()->and($res->json('answer'))->toContain('ventas netas');
    $q = app(CurrentWorkspace::class)->run($this->ws, fn () => AskDataQuery::query()->firstOrFail());
    expect($q->query)->toBeNull();
});

it('si la respuesta inventa cifras se corrige una vez y luego responde la plantilla con los totales reales', function () {
    $totals = analystTotals($this, $this->input);
    $ai = FakeAiGateway::install()->callTool('run_metric_query', $this->input)->reply('Vendiste $999.999.999.')->reply('Fueron 98.765 pedidos.');
    $res = $this->actingAs($this->owner)->postJson("{$this->api}/ai/ask", ['question' => '¿Cuánto vendí?'])->assertCreated();
    expect($res->json('source'))->toBe('fallback')->and($res->json('status'))->toBe('answered')
        ->and($res->json('answer'))->toContain(Money::format($totals['revenue'], 'COP'))->not->toContain('999.999.999')
        ->and($ai->requests)->toHaveCount(2)
        ->and(end($ai->requests)['request']->messages)->toHaveCount(3);
});

it('guarda como segmento lo que habla de clientes y fija consultas en Inteligencia; el visor no pregunta', function () {
    $input = ['metrics' => ['orders'], 'dimensions' => [], 'city' => 'Manizales'] + $this->input;
    $totals = analystTotals($this, $input);
    FakeAiGateway::install()->callTool('run_metric_query', $input)->reply('En Manizales hubo '.$totals['orders'].' pedidos.');
    $res = $this->actingAs($this->owner)->postJson("{$this->api}/ai/ask", ['question' => 'Pedidos de Manizales'])->assertCreated();
    expect($res->json('result.segment_available'))->toBeTrue();
    $id = $res->json('id');

    $saved = $this->actingAs($this->owner)->postJson("{$this->api}/ai/ask/{$id}/save", ['as' => 'segment', 'name' => 'Compradores de Manizales'])->assertOk();
    $segment = app(CurrentWorkspace::class)->run($this->ws, fn () => Segment::query()->where('name', 'Compradores de Manizales')->firstOrFail());
    expect($saved->json('segment_id'))->toBe($segment->publicId())
        ->and(json_encode($segment->definition))->toContain('Manizales')->toContain('orders_count');

    $this->actingAs($this->owner)->postJson("{$this->api}/ai/ask/{$id}/save", ['as' => 'dashboard'])->assertOk();
    $this->actingAs($this->owner)->getJson("{$this->api}/ai/saved")->assertOk()->assertJsonCount(1, 'data');
    $this->actingAs($this->owner)->getJson("{$this->api}/ai/ask/{$id}/run")->assertOk()->assertJsonPath('result.totals.orders', $totals['orders']);

    $viewer = $this->addMember($this->ws, WorkspaceRole::Viewer, true);
    $this->actingAs($viewer)->postJson("{$this->api}/ai/ask", ['question' => 'Hola'])->assertForbidden();
    $this->actingAs($viewer)->getJson("{$this->api}/ai/saved")->assertOk();
    $other = $this->workspaceFor($stranger = $this->userWith2fa(), 'Otra tienda');
    $this->actingAs($stranger)->getJson("/api/w/{$other->publicId()}/ai/ask/{$id}/run")->assertNotFound();
});

it('sin IA disponible responde que no está y no llama a nada', function () {
    $ai = FakeAiGateway::install();
    $ai->enabled = false;
    $res = $this->actingAs($this->owner)->postJson("{$this->api}/ai/ask", ['question' => '¿Cuánto vendí?'])->assertCreated();
    expect($res->json('status'))->toBe('failed')->and($res->json('answer'))->toContain('no está disponible')->and($ai->requests)->toBe([]);
});

it('resume el perfil con agregados, sin correo ni teléfono, y lo guarda hasta que cambien los datos', function () {
    $contact = app(CurrentWorkspace::class)->run($this->ws, fn () => Contact::query()->where('orders_count', '>=', 2)->firstOrFail());
    $facts = app(CurrentWorkspace::class)->run($this->ws, fn () => ProfileSummary::facts($this->ws, $contact));
    $text = $facts['nombre'].' ha hecho '.$facts['pedidos'].' pedidos por '.Money::format($facts['gasto_total'], 'COP').'.';
    $ai = FakeAiGateway::install()->reply($text);

    $url = "{$this->api}/contacts/{$contact->publicId()}/ai-summary";
    $this->actingAs($this->owner)->getJson($url)->assertOk()->assertJsonPath('text', $text)->assertJsonPath('source', 'ai');
    $this->actingAs($this->owner)->getJson($url)->assertOk()->assertJsonPath('text', $text);
    $prompt = (string) json_encode($ai->requests[0]['request']->messages);
    expect($ai->requests)->toHaveCount(1)->and($prompt)->not->toContain((string) $contact->email)
        ->and($prompt)->not->toContain((string) $contact->last_name)
        ->and($ai->requests[0]['request']->feature)->toBe('profile_summary');

    // Una cifra inventada → plantilla sin IA.
    $contact->forceFill(['orders_count' => $contact->orders_count + 1])->save();
    FakeAiGateway::install()->reply('Gastó $123.456.789.')->reply('Gastó $123.456.789.');
    $this->actingAs($this->owner)->getJson($url)->assertOk()->assertJsonPath('source', 'fallback');
});
