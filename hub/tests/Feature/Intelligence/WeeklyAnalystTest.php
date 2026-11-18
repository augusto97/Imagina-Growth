<?php

use App\Analytics\Semantic\MetricQueryRunner;
use App\Domain\Intelligence\Ai\Contracts\AiGateway;
use App\Domain\Intelligence\Ai\Features\Analyst\WeeklyAnalyst;
use App\Domain\Intelligence\Ai\Features\Analyst\WeeklyFacts;
use App\Domain\Intelligence\Ai\Features\AskData\MetricQueryTool;
use App\Domain\Intelligence\Ai\Features\Models\AiReport;
use App\Domain\Intelligence\Ai\Testing\FakeAiGateway;
use App\Domain\Intelligence\Insights\Money;
use App\Domain\Reports\Mail\WeeklyReportMail;
use App\Domain\Reports\SendWeeklyReport;
use App\Domain\Reports\WeeklyReport;
use App\Domain\Workspaces\Enums\WorkspaceRole;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    $this->useIngestInfrastructure();
    $this->artisan('igr:seed-demo', ['--customers' => 30, '--orders' => 150, '--process' => true, '--browsing-months' => 1])->assertSuccessful();
    $this->ws = Workspace::query()->where('name', 'Pinturas Demo')->firstOrFail();
    $this->week = WeeklyReport::lastWeekStart($this->ws);
});

/** Un JSON del analista que cita cifras de los hechos. */
function analystJson(array $facts, array $actions = []): array
{
    $revenue = $facts['indicadores'][0];

    return [
        'resumen' => 'Vendiste '.Money::format($revenue['semana'], 'COP').' esta semana.',
        'por_que' => 'No hay datos suficientes para saber por qué.',
        'acciones' => $actions !== [] ? $actions : [
            ['titulo' => 'Activa el carrito abandonado', 'detalle' => 'Recupera ventas.', 'tipo' => 'flujo', 'destino' => 'abandoned_cart'],
            ['titulo' => 'Mira a tus clientes en riesgo', 'detalle' => 'Prepara una campaña.', 'tipo' => 'ruta', 'destino' => 'analitica/clientes'],
            ['titulo' => 'Algo raro', 'detalle' => 'Ruta inventada.', 'tipo' => 'ruta', 'destino' => 'https://malo.example/x'],
        ],
    ];
}

it('arma los hechos: la semana frente a la anterior y al promedio de 4 semanas, con los cambios ya calculados', function () {
    $facts = app(WeeklyFacts::class)->build($this->ws, $this->week);
    expect($facts['indicadores'])->toHaveCount(5)
        ->and(array_column($facts['indicadores'], 'metrica'))->toBe(WeeklyReport::METRICS)
        ->and($facts['indicadores'][0])->toHaveKeys(['semana', 'semana_anterior', 'cambio_vs_anterior_pct', 'promedio_4_semanas', 'cambio_vs_promedio_pct'])
        ->and($facts['semana']['desde'])->toBe($this->week->toDateString())
        ->and(json_encode($facts))->not->toContain('@');
});

it('escribe el informe con IA: cifras validadas, 3 acciones con botón seguro y uno por semana', function () {
    $facts = app(WeeklyFacts::class)->build($this->ws, $this->week);
    $ai = FakeAiGateway::install()->replyJson(analystJson($facts));
    $report = app(WeeklyAnalyst::class)->handle($this->ws, $this->week);

    expect($report->source)->toBe('ai')
        ->and($report->body_markdown)->toContain(Money::format($facts['indicadores'][0]['semana'], 'COP'))
        ->and($report->actions)->toHaveCount(3)
        ->and($report->actions[0])->toMatchArray(['tipo' => 'flujo', 'ruta' => 'flujos?biblioteca=abandoned_cart'])
        ->and($report->actions[1]['ruta'])->toBe('analitica/clientes')
        ->and($report->actions[2]['ruta'])->toBe('hallazgos')
        ->and($report->validation['words'])->toBeLessThanOrEqual(WeeklyAnalyst::MAX_WORDS)
        ->and($ai->requests[0]['request']->feature)->toBe('weekly_analyst')
        ->and($ai->requests[0]['request']->outputSchema)->not->toBeNull();

    // Idempotente por semana: no vuelve a llamar al modelo.
    expect(app(WeeklyAnalyst::class)->handle($this->ws, $this->week)->id)->toBe($report->id)->and($ai->requests)->toHaveCount(1)
        ->and(AiReport::withoutWorkspaceScope()->count())->toBe(1)
        ->and(DB::table('ai_usage')->where('workspace_id', $this->ws->id)->where('feature', 'weekly_analyst')->value('requests'))->toBe(1);
});

it('si el modelo inventa una cifra se le corrige una vez y, si insiste, va la plantilla sin IA', function () {
    $facts = app(WeeklyFacts::class)->build($this->ws, $this->week);
    $bad = analystJson($facts);
    $bad['resumen'] = 'Vendiste $987.654.321 y ganaste 4.321 clientes.';
    $ai = FakeAiGateway::install()->replyJson($bad)->replyJson($bad);
    $report = app(WeeklyAnalyst::class)->handle($this->ws, $this->week);

    expect($report->source)->toBe('fallback')
        ->and($report->body_markdown)->toContain('Así le fue a tu negocio esta semana')->not->toContain('987.654.321')
        ->and($report->actions)->toHaveCount(3)
        ->and($report->validation['fallback_reason'])->toBe('numbers')
        ->and($report->validation['attempts'][0]['unknown'])->toContain('$987.654.321')
        ->and($ai->requests)->toHaveCount(2);

    // Sin clave de IA, la plantilla sale de una vez.
    config(['igr-ai.api_key' => null]);
    app()->forgetInstance(AiGateway::class);
    $again = app(WeeklyAnalyst::class)->handle($this->ws, $this->week, true);
    expect($again->source)->toBe('fallback')->and($again->validation['fallback_reason'])->toBe('disabled');
});

it('el correo semanal lleva el análisis con sus 3 acciones y marca el informe como entregado', function () {
    Mail::fake();
    $facts = app(WeeklyFacts::class)->build($this->ws, $this->week);
    FakeAiGateway::install()->replyJson(analystJson($facts));
    app(SendWeeklyReport::class)->handle($this->ws, $this->week);

    Mail::assertQueued(WeeklyReportMail::class, function (WeeklyReportMail $m) {
        $html = $m->render();

        return $m->facts['analysis']['source'] === 'ai' && count($m->facts['analysis']['actions']) === 3
            && str_contains($html, 'El análisis de la semana') && str_contains($html, 'Escrito con IA') && str_contains($html, 'flujos?biblioteca=abandoned_cart');
    });
    expect(AiReport::withoutWorkspaceScope()->first()?->delivered_at)->not->toBeNull();
});

it('la API lista los informes, genera uno con permiso de IA y no deja verlos desde otro espacio de trabajo', function () {
    $owner = $this->addMember($this->ws, WorkspaceRole::Owner, true);
    $viewer = $this->addMember($this->ws, WorkspaceRole::Viewer, true);
    $api = "/api/w/{$this->ws->publicId()}";
    $facts = app(WeeklyFacts::class)->build($this->ws, $this->week);
    FakeAiGateway::install()->replyJson(analystJson($facts));

    $this->actingAs($viewer)->postJson("{$api}/ai/reports/generate")->assertForbidden();
    $created = $this->actingAs($owner)->postJson("{$api}/ai/reports/generate")->assertCreated();
    expect($created->json('source'))->toBe('ai')->and($created->json('actions'))->toHaveCount(3);
    $this->actingAs($viewer)->getJson("{$api}/ai/reports")->assertOk()->assertJsonCount(1, 'data');
    $this->actingAs($viewer)->getJson("{$api}/ai/reports/{$created->json('id')}")->assertOk()->assertJsonPath('facts.semana.desde', $this->week->toDateString());

    $other = $this->workspaceFor($stranger = $this->userWith2fa(), 'Otra tienda');
    $this->actingAs($stranger)->getJson("/api/w/{$other->publicId()}/ai/reports/{$created->json('id')}")->assertNotFound();
});

it('el comando del lunes también escribe el informe del analista', function () {
    Mail::fake();
    $this->artisan('igr:report:weekly', ['--workspace' => $this->ws->publicId()])->assertSuccessful();
    expect(app(CurrentWorkspace::class)->run($this->ws, fn () => AiReport::query()->whereDate('period_start', $this->week->toDateString())->value('source')))->toBe('fallback');
});

it('la herramienta de consultas solo acepta el catálogo y corre en el espacio de trabajo activo', function () {
    $tool = new MetricQueryTool($this->ws, app(MetricQueryRunner::class));
    $input = ['metrics' => ['revenue', 'orders'], 'dimensions' => [], 'from' => $this->week->toDateString(), 'to' => $this->week->addDays(6)->toDateString(),
        'compare' => 'previous', 'granularity' => 'auto', 'channel' => null, 'device' => null, 'city' => null, 'limit' => 20];
    $out = app(CurrentWorkspace::class)->run($this->ws, fn () => $tool->run($input));
    expect($out['totales'])->toHaveKeys(['revenue', 'orders'])->and($out['moneda'])->toBe('COP');

    expect(fn () => $tool->run(['metrics' => ['ganancias']] + $input))->toThrow(InvalidArgumentException::class, 'Métrica desconocida');
    expect(fn () => $tool->run(['dimensions' => ['date', 'city', 'channel']] + $input))->toThrow(InvalidArgumentException::class);
    expect(fn () => $tool->run(['from' => 'la semana pasada'] + $input))->toThrow(InvalidArgumentException::class);
    expect(MetricQueryTool::schema()['properties']['metrics']['items']['enum'])->toContain('revenue', 'conversion_rate');
});

it('una respuesta vacía del modelo no deja un informe en blanco: va la plantilla', function () {
    FakeAiGateway::install()->replyJson(['resumen' => '', 'por_que' => '', 'acciones' => []]);
    $report = app(WeeklyAnalyst::class)->handle($this->ws, $this->week);
    expect($report->source)->toBe('fallback')->and($report->validation['fallback_reason'])->toBe('invalid')
        ->and($report->body_markdown)->toContain('Así le fue a tu negocio esta semana')->and($report->actions)->toHaveCount(3);
});
