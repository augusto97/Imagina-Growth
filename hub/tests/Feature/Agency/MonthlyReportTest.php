<?php

use App\Domain\Agency\Console\MonthlyReportsCommand;
use App\Domain\Agency\Mail\MonthlyReportMail;
use App\Domain\Agency\Models\AccountBranding;
use App\Domain\Agency\Models\MonthlyReport;
use App\Domain\Agency\Reports\MonthlyReports;
use App\Domain\Intelligence\Ai\Testing\FakeAiGateway;
use App\Domain\Workspaces\Enums\WorkspaceRole;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\Agency\AgencyWorld;

function agencyReportData(AgencyWorld $w): void
{
    $nov = CarbonImmutable::parse('2026-11-10 12:00:00', 'America/Bogota');
    AgencyWorld::order($w->a, 300000, ['attributed_flow_id' => strtoupper((string) Str::ulid()), 'paid_at' => $nov, 'created_at' => $nov]);
    AgencyWorld::order($w->a, 200000, ['paid_at' => $nov, 'created_at' => $nov]);
    AgencyWorld::order($w->a, 100000, ['attributed_flow_id' => strtoupper((string) Str::ulid()), 'paid_at' => $nov->subMonth(), 'created_at' => $nov->subMonth()]);
    DB::table('insights')->insert(['id' => strtoupper((string) Str::ulid()), 'workspace_id' => $w->a->id, 'detector_key' => 'x', 'severity' => 'warning',
        'title' => 'Clientes en riesgo sin campaña', 'facts' => '{}', 'estimated_impact_ws' => 450000, 'status' => 'new', 'created_at' => now(), 'updated_at' => now()]);
}

beforeEach(function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-12-01 08:30:00', 'America/Bogota'));
    Storage::fake('local');
    $this->w = AgencyWorld::make($this);
    agencyReportData($this->w);
});

afterEach(fn () => CarbonImmutable::setTestNow());

it('arma los hechos del mes: dinero por módulo, KPI frente al mes anterior, hallazgos y predicciones', function () {
    FakeAiGateway::install()->failWith('provider');
    $report = app(MonthlyReports::class)->generate($this->w->a, MonthlyReports::lastMonth($this->w->a));
    $f = $report->facts;
    $kpis = collect($f['kpis'])->keyBy('clave');

    expect($report->period->format('Y-m'))->toBe('2026-11')
        ->and($f['mes'])->toBe('noviembre de 2026')
        ->and($f['dinero_generado']['total'])->toEqual(300000)
        ->and($f['dinero_generado']['ventas_tienda'])->toEqual(500000)
        ->and($f['dinero_generado']['participacion_pct'])->toEqual(60.0)
        ->and($f['dinero_generado']['mes_anterior'])->toEqual(100000)
        ->and($f['dinero_generado']['cambio_vs_anterior_pct'])->toEqual(200.0)
        ->and($f['dinero_generado']['por_modulo'][0])->toMatchArray(['clave' => 'flows', 'modulo' => 'Flujos automáticos', 'pedidos' => 1])
        ->and($kpis['ventas']['valor'])->toEqual(500000)->and($kpis['ventas']['anterior'])->toEqual(100000)->and($kpis['ventas']['cambio_pct'])->toEqual(400.0)
        ->and($kpis['pedidos']['valor'])->toBe(2)
        ->and($kpis->has('leads_nuevos'))->toBeFalse()
        ->and($f['hallazgos'][0]['titulo'])->toBe('Clientes en riesgo sin campaña')
        ->and($f['predicciones'])->toHaveKeys(['clientes_en_riesgo_alto', 'valor_futuro_12m', 'compras_esperadas_30_dias'])
        ->and(json_encode($f))->not->toContain('@');
});

it('en un sitio corporativo sin tienda el reporte habla de negocios cerrados con los leads ganados', function () {
    FakeAiGateway::install()->failWith('provider');
    $ws = $this->w->b;
    DB::table('orders')->where('workspace_id', $ws->id)->delete();
    $nov = CarbonImmutable::parse('2026-11-12 10:00:00', 'America/Bogota');
    foreach ([['form', '3500000', 'won'], ['booking', '1500000', 'won'], ['quote', null, 'new']] as [$origin, $value, $stage]) {
        DB::table('leads')->insert(['id' => strtoupper((string) Str::ulid()), 'workspace_id' => $ws->id, 'contact_id' => strtoupper((string) Str::ulid()),
            'stage' => $stage, 'origin' => $origin, 'value_ws' => $value, 'won_at' => $stage === 'won' ? $nov : null, 'created_at' => $nov, 'updated_at' => $nov]);
    }
    $report = app(MonthlyReports::class)->generate($ws, MonthlyReports::lastMonth($ws));
    $f = $report->facts;
    $kpis = collect($f['kpis'])->keyBy('clave');

    expect($f['modo'])->toBe('servicios')
        ->and($f['dinero_generado']['total'])->toEqual(5000000)
        ->and($kpis->keys()->all())->toBe(['ventas', 'leads_nuevos', 'leads_ganados', 'ticket_promedio'])
        ->and($kpis['ventas']['valor'])->toEqual(5000000)->and($kpis['leads_ganados']['valor'])->toBe(2)->and($kpis['leads_nuevos']['valor'])->toBe(3)
        ->and($report->summary)->toContain('2 negocios cerrados')->not->toContain('pedidos');
});

it('el resumen con IA solo pasa si cada cifra está en los hechos; si no, va la plantilla', function () {
    $ai = FakeAiGateway::install()->replyJson([
        'resumen' => 'En noviembre de 2026 la plataforma generó $300.000, el 60 % de tus ventas, 200 % más que en octubre.',
        'destacados' => ['Los flujos automáticos aportaron $300.000.'],
        'siguiente_paso' => 'Lanza una campaña para los clientes en riesgo.',
    ]);
    $report = app(MonthlyReports::class)->generate($this->w->a, MonthlyReports::lastMonth($this->w->a));
    expect($report->summary_source)->toBe('ai')
        ->and($report->summary)->toContain('$300.000')->toContain('Para el próximo mes')
        ->and($ai->requests[0]['request']->feature)->toBe('monthly_report');

    FakeAiGateway::install()
        ->replyJson(['resumen' => 'Generaste $9.999.999 este mes.', 'destacados' => [], 'siguiente_paso' => 'Sigue así.'])
        ->replyJson(['resumen' => 'Fueron 77 pedidos.', 'destacados' => [], 'siguiente_paso' => 'Sigue así.']);
    $again = app(MonthlyReports::class)->generate($this->w->a, MonthlyReports::lastMonth($this->w->a), force: true);
    expect($again->summary_source)->toBe('fallback')
        ->and($again->summary)->toContain('$300.000')->toContain('60,0 %')->toContain('200,0 % más')
        ->and($again->summary)->not->toContain('9.999.999')
        ->and(MonthlyReport::withoutWorkspaceScope()->count())->toBe(1);
});

it('genera el PDF con la marca de la agencia y lo envía a los dueños con el remitente verificado', function () {
    Mail::fake();
    FakeAiGateway::install()->failWith('provider');
    AccountBranding::query()->create(['account_id' => $this->w->agency->id, 'brand_name' => 'Imagina La Web', 'primary_color' => '#0F766E',
        'sender_name' => 'Reportes Imagina', 'sender_email' => 'reportes@imaginalaweb.co', 'support_email' => 'soporte@imaginalaweb.co', 'white_label' => true]);
    DB::table('channel_accounts')->insert(['id' => strtoupper((string) Str::ulid()), 'workspace_id' => $this->w->a->id, 'channel' => 'email', 'provider' => 'ses',
        'display_name' => 'Correo', 'identity' => 'imaginalaweb.co', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    $viewer = $this->addMember($this->w->a, WorkspaceRole::Viewer);

    $reports = app(MonthlyReports::class);
    $report = $reports->generate($this->w->a, MonthlyReports::lastMonth($this->w->a));
    $pdf = Storage::disk('local')->get((string) $report->pdf_path);
    expect($report->pdf_path)->toBe('agency/reports/'.$this->w->a->id.'/2026-11.pdf')
        ->and(substr((string) $pdf, 0, 4))->toBe('%PDF');

    $result = $reports->send($this->w->a, $report->refresh());
    expect($result)->toBe(['status' => 'sent', 'count' => 1]);
    Mail::assertQueued(MonthlyReportMail::class, fn (MonthlyReportMail $m) => $m->hasTo($this->w->clientOwnerA->email));
    Mail::assertQueued(MonthlyReportMail::class, 1); // solo los dueños: ni el viewer ni la agencia

    $mail = new MonthlyReportMail($this->w->a->id, $report->id, 'Carla Cliente');
    $envelope = $mail->envelope();
    expect($envelope->from->address)->toBe('reportes@imaginalaweb.co')
        ->and($envelope->subject)->toContain('$300.000')
        ->and($mail->attachments())->toHaveCount(1)
        ->and($mail->render())->toContain('Imagina La Web')->not->toContain('con Imagina Growth');

    $report->refresh();
    expect($report->sent_at)->not->toBeNull()
        ->and($report->recipients['count'])->toBe(1)
        ->and($report->recipients['masked'][0])->not->toBe($this->w->clientOwnerA->email)
        ->and(json_encode($report->recipients))->not->toContain($this->w->clientOwnerA->email)
        ->and($reports->send($this->w->a, $report)['status'])->toBe('already_sent');
    expect(DB::table('audit_logs')->where('action', 'agency.report_sent')->count())->toBe(1);
});

it('sin dominio verificado el correo sale con el remitente de la plataforma', function () {
    FakeAiGateway::install()->failWith('provider');
    AccountBranding::query()->create(['account_id' => $this->w->agency->id, 'brand_name' => 'Imagina La Web', 'sender_email' => 'reportes@imaginalaweb.co']);
    $report = app(MonthlyReports::class)->generate($this->w->a, MonthlyReports::lastMonth($this->w->a));
    $envelope = (new MonthlyReportMail($this->w->a->id, $report->id, 'Carla'))->envelope();
    expect($envelope->from->address)->not->toBe('reportes@imaginalaweb.co');
});

it('el comando genera y envía el día 1 a las 08:00 locales, una sola vez', function () {
    Mail::fake();
    FakeAiGateway::install()->failWith('provider');
    expect(MonthlyReportsCommand::isDue('America/Bogota'))->toBeTrue()
        ->and(MonthlyReportsCommand::isDue('America/Mexico_City'))->toBeFalse()   // allá son las 07:30
        ->and(MonthlyReportsCommand::isDue('America/Bogota', CarbonImmutable::parse('2026-12-02 08:30', 'America/Bogota')))->toBeFalse();

    $this->artisan('igr:agency:monthly-reports', ['--due' => true, '--sync' => true])->assertSuccessful();
    $this->artisan('igr:agency:monthly-reports', ['--due' => true, '--sync' => true])->assertSuccessful();
    expect(MonthlyReport::withoutWorkspaceScope()->where('workspace_id', $this->w->a->id)->count())->toBe(1);
    Mail::assertQueued(MonthlyReportMail::class, fn (MonthlyReportMail $m) => $m->hasTo($this->w->clientOwnerA->email));
    Mail::assertQueuedCount(3); // un dueño por cliente (A, B y C), una vez
});

it('el cliente ve y descarga sus reportes; generar y reenviar es del dueño o de la agencia', function () {
    Mail::fake();
    FakeAiGateway::install()->failWith('provider');
    $w = $this->w;
    $viewer = $this->addMember($w->a, WorkspaceRole::Viewer);

    $this->actingAs($viewer)->postJson($w->api($w->a, 'reports/generate'), ['month' => '2026-11'])->assertForbidden();
    $gen = $this->actingAs($w->clientOwnerA)->postJson($w->api($w->a, 'reports/generate'), ['month' => '2026-11', 'send' => true])->assertCreated();
    $id = $gen->json('report.id');
    expect($gen->json('report.generated'))->toEqual(300000)->and($gen->json('sent.status'))->toBe('sent');

    $this->actingAs($viewer)->getJson($w->api($w->a, 'reports'))->assertOk()->assertJsonPath('reports.0.id', $id)->assertJsonPath('can_manage', false);
    $pdf = $this->actingAs($viewer)->get($w->api($w->a, "reports/{$id}/pdf"))->assertOk();
    expect($pdf->headers->get('content-type'))->toBe('application/pdf');
    $this->actingAs($viewer)->postJson($w->api($w->a, "reports/{$id}/send"))->assertForbidden();

    // La agencia: todos sus clientes del mes; no los de otra agencia.
    $agency = $this->actingAs($w->member)->getJson($w->api($w->b, 'agency/reports?month=2026-11'))->assertOk();
    $rows = collect($agency->json('clients'))->keyBy('workspace_id');
    expect($rows)->toHaveCount(2)->and($rows[$w->a->publicId()]['report']['id'])->toBe($id)->and($rows[$w->b->publicId()]['report'])->toBeNull();
    $this->actingAs($w->member)->get($w->api($w->b, 'agency/reports/'.$w->a->publicId()."/{$id}/pdf"))->assertOk();
    $this->actingAs($w->member)->postJson($w->api($w->b, 'agency/reports/'.$w->a->publicId()."/{$id}/send"))->assertOk()->assertJsonPath('sent.status', 'sent');
    $this->actingAs($w->member)->get($w->api($w->b, 'agency/reports/'.$w->b->publicId()."/{$id}/pdf"))->assertNotFound(); // el reporte es de otro cliente
    $this->actingAs($w->foreignOwner)->get($w->api($w->c, 'agency/reports/'.$w->a->publicId()."/{$id}/pdf"))->assertNotFound();
});
