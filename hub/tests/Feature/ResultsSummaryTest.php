<?php

use App\Domain\Agency\Reports\MonthlyReports;
use App\Domain\Workspaces\Enums\WorkspaceRole;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Agency\AgencyWorld;

/*
 * Resultados → Resumen (GET analytics/summary) e Informes (GET reports con a quién llegan y GET reports/preview):
 * rediseño v2, frente de Resultados.
 */

beforeEach(function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-18 15:00:00', 'America/Bogota'));
    $this->w = AgencyWorld::make($this);
});

afterEach(fn () => CarbonImmutable::setTestNow());

function summaryMessage(string $workspaceId, string $channel): string
{
    $id = strtoupper((string) Str::ulid());
    DB::table('messages')->insert(['id' => $id, 'workspace_id' => $workspaceId, 'channel' => $channel, 'kind' => 'marketing', 'source_type' => 'campaign', 'created_at' => now(), 'updated_at' => now()]);

    return $id;
}

it('el resumen da las ventas, el dinero generado por estrategia y por canal, y lo compara con el periodo anterior', function () {
    $ws = $this->w->a;
    AgencyWorld::order($ws, 300000, ['attributed_campaign_id' => strtoupper((string) Str::ulid()), 'attributed_message_id' => summaryMessage($ws->id, 'whatsapp')]);
    AgencyWorld::order($ws, 100000, ['attributed_campaign_id' => strtoupper((string) Str::ulid()), 'attributed_message_id' => summaryMessage($ws->id, 'email')]);
    AgencyWorld::order($ws, 600000);                                                                     // la tienda sola
    AgencyWorld::order($ws, 50000, ['attributed_campaign_id' => strtoupper((string) Str::ulid()), 'paid_at' => now()->subDays(40), 'created_at' => now()->subDays(40)]);
    AgencyWorld::order($this->w->b, 999999, ['attributed_campaign_id' => strtoupper((string) Str::ulid())]); // otro espacio de trabajo

    $res = $this->actingAs($this->w->clientOwnerA)->getJson($this->w->api($ws, 'analytics/summary?from=2026-10-20&to=2026-11-18&compare=previous'))->assertOk();
    $data = $res->json('data');

    expect($res->json('dashboard'))->toBe('summary')
        ->and($data['generated']['total'])->toEqual(400000)
        ->and($data['generated']['orders'])->toBe(2)
        ->and($data['generated']['store_total'])->toEqual(1000000)
        ->and($data['generated']['share_pct'])->toEqual(40)
        ->and($data['generated']['previous']['total'])->toEqual(50000)
        ->and($data['generated']['change_pct'])->toEqual(700)
        ->and(collect($data['by_strategy'])->firstWhere('bucket', 'campaigns'))->toMatchArray(['strategy' => null, 'name' => 'Campañas', 'revenue' => 400000, 'orders' => 2, 'previous' => 50000])
        ->and(collect($data['by_channel'])->pluck('revenue', 'channel')->all())->toEqual(['whatsapp' => 300000, 'email' => 100000])
        ->and(collect($data['by_channel'])->firstWhere('channel', 'email')['previous'])->toEqual(0)
        ->and($data)->toHaveKeys(['business_type', 'sales', 'series', 'insights']);
});

it('sin comparación no inventa el periodo anterior y el resumen pide poder ver tableros', function () {
    $ws = $this->w->a;
    $res = $this->actingAs($this->w->clientOwnerA)->getJson($this->w->api($ws, 'analytics/summary?from=2026-11-01&to=2026-11-18&compare=none'))->assertOk();
    expect($res->json('data.generated.previous'))->toBeNull()->and($res->json('data.generated.change_pct'))->toBeNull()
        ->and($res->json('data.by_channel'))->toBe([]);

    $agent = $this->addMember($ws, WorkspaceRole::Agent);
    $this->actingAs($agent)->getJson($this->w->api($ws, 'analytics/summary'))->assertForbidden();
});

it('Informes dice a quién llega cada informe y da la vista previa del mes sin guardarlo', function () {
    $ws = $this->w->a;
    AgencyWorld::order($ws, 250000, ['paid_at' => now()->subMonth(), 'created_at' => now()->subMonth()]);
    $ws->users()->updateExistingPivot($this->w->clientOwnerA->id, ['notifications' => json_encode(['weekly_report' => false])]);

    $index = $this->actingAs($this->w->clientOwnerA)->getJson($this->w->api($ws, 'reports'))->assertOk();
    expect($index->json('recipients.monthly.count'))->toBe(MonthlyReports::recipients($ws)->count())
        ->and($index->json('recipients.monthly.count'))->toBeGreaterThan(0)
        ->and($index->json('recipients.weekly.count'))->toBe($index->json('recipients.monthly.count') - 1)
        ->and(implode(' ', $index->json('recipients.monthly.masked')))->not->toContain((string) $this->w->clientOwnerA->email);

    $preview = $this->actingAs($this->w->clientOwnerA)->getJson($this->w->api($ws, 'reports/preview'))->assertOk();
    expect($preview->json('period'))->toBe('2026-10')
        ->and($preview->json('facts.periodo.mes'))->toBe('2026-10')
        ->and($preview->json('facts.kpis.0.valor'))->toEqual(250000)
        ->and(DB::table('monthly_reports')->where('workspace_id', $ws->id)->count())->toBe(0);
    $this->actingAs($this->w->clientOwnerA)->getJson($this->w->api($ws, 'reports/preview?month=2026-13'))->assertUnprocessable();
});
