<?php

use App\Domain\Reports\Mail\WeeklyReportMail;
use App\Domain\Reports\SendWeeklyReport;
use App\Domain\Reports\WeeklyReport;
use App\Domain\Workspaces\Enums\WorkspaceRole;
use App\Domain\Workspaces\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    $this->useIngestInfrastructure();
    $this->artisan('igr:seed-demo', ['--customers' => 40, '--orders' => 200, '--process' => true, '--browsing-months' => 2])->assertSuccessful();
    $this->ws = Workspace::query()->where('name', 'Pinturas Demo')->firstOrFail();
});

it('envía el resumen semanal a los dueños: 5 cifras, hallazgos y 1 acción, una vez por semana', function () {
    Mail::fake();
    $optedOut = $this->addMember($this->ws, WorkspaceRole::Owner);
    $this->ws->users()->updateExistingPivot($optedOut->id, ['notifications' => json_encode(['weekly_report' => false])]);
    $this->addMember($this->ws, WorkspaceRole::Marketing);

    $result = app(SendWeeklyReport::class)->handle($this->ws);
    expect($result['status'])->toBe('sent')->and($result['recipients'])->toBe(['demo@growth.test']);
    Mail::assertQueued(WeeklyReportMail::class, fn (WeeklyReportMail $m) => $m->hasTo('demo@growth.test') && count($m->facts['numbers']) === 5 && isset($m->facts['action']['label']));
    Mail::assertQueued(WeeklyReportMail::class, 1);

    $delivery = DB::table('report_deliveries')->where('workspace_id', $this->ws->id)->first();
    expect($delivery->kind)->toBe('weekly')->and($delivery->period_start)->toBe(WeeklyReport::lastWeekStart($this->ws)->toDateString());

    expect(app(SendWeeklyReport::class)->handle($this->ws)['status'])->toBe('already_sent');
    Mail::assertQueued(WeeklyReportMail::class, 1);
});

it('el correo se renderiza en español con las cifras de la semana', function () {
    $facts = app(WeeklyReport::class)->facts($this->ws, WeeklyReport::lastWeekStart($this->ws));
    $html = (new WeeklyReportMail($facts, 'Dueño demo'))->render();
    expect($html)->toContain('Hola, Dueño')->toContain('Ventas')->toContain('Ticket promedio')->toContain('Tu acción de esta semana');
    $dir = '/tmp/claude-0/-home-user-Imagina-Growth/a9bf6664-b5fa-539b-a7c5-48becaac4935/scratchpad';
    if (is_dir($dir)) {
        file_put_contents($dir.'/informe-semanal.html', $html);
    }
});

it('le toca los lunes desde las 7 de la mañana en la zona del espacio de trabajo', function () {
    $monday7 = CarbonImmutable::parse('2026-11-23 07:15', 'America/Bogota');
    expect(SendWeeklyReport::isDue($this->ws, $monday7))->toBeTrue()
        ->and(SendWeeklyReport::isDue($this->ws, $monday7->addHours(3)))->toBeTrue()   // el programador se atrasó
        ->and(SendWeeklyReport::isDue($this->ws, $monday7->subHour()))->toBeFalse()
        ->and(SendWeeklyReport::isDue($this->ws, $monday7->addDay()))->toBeFalse()
        ->and(WeeklyReport::lastWeekStart($this->ws, $monday7)->toDateString())->toBe('2026-11-16');
});
