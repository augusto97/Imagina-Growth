<?php

use App\Domain\Services\Leads\Actions\ChangeLeadStage;
use App\Domain\Services\Leads\Models\Lead;
use App\Domain\Services\Leads\Support\Pipelines;
use App\Domain\Workspaces\Enums\WorkspaceRole;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Resultados de un negocio sin tienda (rediseño v2 §11.2, modulos.md M18): embudo, fuentes, primera respuesta, citas y
 * asistencia, cotizaciones, cierre, valor ganado y lo que trajo cada lead ganado, acotado por espacio de trabajo.
 */

beforeEach(function () {
    $this->useIngestInfrastructure();
    $this->owner = $this->userWith2fa();
    $this->ws = $this->workspaceFor($this->owner, 'Clínica Sonrisa');
    $this->ws->forceFill(['country' => 'CO', 'timezone' => 'America/Bogota', 'vertical' => 'health'])->save();
    $this->travelTo(CarbonImmutable::parse('2026-11-20 12:00:00', 'America/Bogota'));
});

function resultsContact($test): string
{
    $id = (string) Str::ulid();
    DB::table('contacts')->insert(['id' => $id, 'workspace_id' => $test->ws->id, 'created_at' => now(), 'updated_at' => now()]);

    return $id;
}

function resultsLead($test, string $origin, array $attrs = []): Lead
{
    return app(CurrentWorkspace::class)->run($test->ws, function () use ($test, $origin, $attrs) {
        $lead = new Lead;
        $lead->forceFill($attrs + [
            'workspace_id' => $test->ws->id, 'pipeline_id' => Pipelines::default($test->ws->id)->id, 'contact_id' => resultsContact($test),
            'stage' => 'new', 'origin' => $origin, 'source' => ['channel' => $origin === 'form' ? 'paid_social' : 'whatsapp'], 'score' => 10, 'temperature' => 'cold', 'score_reasons' => [],
        ])->save();

        return $lead;
    });
}

function resultsWin($test, Lead $lead, string $value): void
{
    app(CurrentWorkspace::class)->run($test->ws, fn () => app(ChangeLeadStage::class)->handle($test->ws, $lead->refresh(), 'won', 'user', null, $value));
}

it('arma el embudo y las métricas del periodo con lo que trajo cada lead ganado', function () {
    // Cuatro leads: dos de formulario (uno ganado por un flujo), uno de WhatsApp ganado y uno de la agenda perdido.
    $flow = (string) Str::ulid();
    DB::table('flows')->insert(['id' => $flow, 'workspace_id' => $this->ws->id, 'name' => 'Cotización sin respuesta', 'status' => 'active', 'trigger' => '{}', 'created_at' => now(), 'updated_at' => now()]);
    $byFlow = resultsLead($this, 'form', ['created_at' => now()->subDays(5), 'first_response_at' => now()->subDays(5)->addMinutes(4)]);
    $plain = resultsLead($this, 'form', ['created_at' => now()->subDays(3)]);
    $chat = resultsLead($this, 'whatsapp', ['created_at' => now()->subDays(2), 'first_response_at' => now()->subDays(2)->addMinutes(20)]);
    $lost = resultsLead($this, 'booking', ['created_at' => now()->subDays(1)]);
    app(CurrentWorkspace::class)->run($this->ws, fn () => app(ChangeLeadStage::class)->handle($this->ws, $byFlow, 'contacted', 'user'));
    app(CurrentWorkspace::class)->run($this->ws, fn () => app(ChangeLeadStage::class)->handle($this->ws, $lost, 'lost', 'user', null, null, 'Muy caro'));
    // Un mensaje del flujo con clic dos días antes de ganarse.
    DB::table('messages')->insert(['id' => (string) Str::ulid(), 'workspace_id' => $this->ws->id, 'contact_id' => $byFlow->contact_id, 'channel' => 'whatsapp',
        'direction' => 'out', 'kind' => 'marketing', 'source_type' => 'flow', 'source_id' => $flow, 'flow_id' => $flow, 'status' => 'read',
        'sent_at' => now()->subDays(2), 'clicked_at' => now()->subDays(2), 'created_at' => now()->subDays(2), 'updated_at' => now()]);
    resultsWin($this, $byFlow, '2400000');
    resultsWin($this, $chat, '600000');
    // Citas: una cumplida, una no asistida y una próxima.
    $service = (string) Str::ulid();
    DB::table('booking_services')->insert(['id' => $service, 'workspace_id' => $this->ws->id, 'name' => 'Limpieza', 'slug' => 'limpieza', 'duration_min' => 30, 'created_at' => now(), 'updated_at' => now()]);
    foreach ([['completed', -3], ['no_show', -2], ['booked', 2]] as [$status, $days]) {
        DB::table('appointments')->insert(['id' => (string) Str::ulid(), 'workspace_id' => $this->ws->id, 'contact_id' => $plain->contact_id, 'service_id' => $service,
            'starts_at' => now()->addDays($days), 'ends_at' => now()->addDays($days)->addMinutes(30), 'status' => $status, 'channel' => 'page', 'created_at' => now()->subDays(4), 'updated_at' => now()]);
    }
    // Cotizaciones: una del cotizador (sin ganar) y una propuesta al lead de WhatsApp (que se ganó).
    DB::table('quotes')->insert(['id' => (string) Str::ulid(), 'workspace_id' => $this->ws->id, 'calculator_id' => (string) Str::ulid(), 'contact_id' => $plain->contact_id,
        'lead_id' => $plain->id, 'answers' => '{}', 'estimate_min' => '100000', 'estimate_max' => '200000', 'currency' => 'COP', 'created_at' => now()->subDays(3), 'updated_at' => now()]);
    DB::table('lead_activities')->insert(['workspace_id' => $this->ws->id, 'lead_id' => $chat->id, 'kind' => 'stage', 'actor' => 'user', 'data' => json_encode(['from' => 'contacted', 'to' => 'proposal']), 'created_at' => now()->subDay()]);

    $res = $this->actingAs($this->owner)->getJson("/api/w/{$this->ws->publicId()}/services/results?days=30")->assertOk()->json();
    expect($res['kpis']['leads']['value'])->toEqual(4.0)
        ->and($res['kpis']['won_value']['value'])->toEqual(3000000.0)
        ->and($res['kpis']['close_rate']['value'])->toEqual(66.7)
        ->and($res['kpis']['first_response_minutes']['value'])->toEqual(12.0)
        ->and($res['leads']['total'])->toBe(4)
        ->and(collect($res['leads']['by_origin'])->pluck('count', 'origin')->all())->toMatchArray(['form' => 2, 'whatsapp' => 1, 'booking' => 1])
        ->and(collect($res['leads']['by_source'])->firstWhere('channel', 'paid_social'))->toMatchArray(['leads' => 2, 'won' => 1, 'won_value' => '2400000.00'])
        ->and($res['leads']['funnel']['pipeline']['name'])->toBe('Pacientes')
        ->and(collect($res['leads']['funnel']['stages'])->pluck('leads', 'stage')->all())->toMatchArray(['new' => 4, 'contacted' => 2, 'won' => 2, 'lost' => 1])
        ->and(array_sum(array_column($res['leads']['series'], 'leads')))->toBe(4)
        ->and($res['appointments'])->toMatchArray(['booked' => 3, 'completed' => 1, 'no_show' => 1, 'pending' => 0, 'upcoming' => 1, 'attendance_rate' => 0.5])
        ->and($res['quotes'])->toMatchArray(['sent' => 2, 'from_calculator' => 1, 'proposals' => 1, 'accepted' => 1, 'accepted_value' => '600000.00', 'acceptance_rate' => 0.5])
        ->and($res['won'])->toMatchArray(['count' => 2, 'value' => '3000000.00']);
    $groups = collect($res['attribution']['groups'])->keyBy('key');
    expect($groups['flows'])->toMatchArray(['count' => 1, 'value' => '2400000.00'])
        ->and($groups['chats'])->toMatchArray(['count' => 1, 'value' => '600000.00'])
        ->and($groups['forms']['count'])->toBe(0)
        ->and($res['attribution']['platform_value'])->toBe('3000000.00')
        ->and($res['attribution']['flows'][0])->toMatchArray(['name' => 'Cotización sin respuesta', 'count' => 1]);

    // Un periodo propio; el anterior para comparar.
    $custom = $this->actingAs($this->owner)->getJson("/api/w/{$this->ws->publicId()}/services/results?from=2026-11-01&to=2026-11-20")->assertOk()->json();
    expect($custom['period'])->toMatchArray(['from' => '2026-11-01', 'to' => '2026-11-20'])->and($custom['kpis']['leads']['previous'])->toEqual(0.0);
    $this->actingAs($this->owner)->getJson("/api/w/{$this->ws->publicId()}/services/results?from=2025-01-01&to=2026-11-20")->assertUnprocessable();
    $this->actingAs($this->owner)->getJson("/api/w/{$this->ws->publicId()}/services/results?days=12")->assertUnprocessable();
    // 90 días van por semana (lunes) y un año por mes, con los mismos totales.
    foreach (['90' => 'week', '365' => 'month'] as $days => $granularity) {
        $long = $this->actingAs($this->owner)->getJson("/api/w/{$this->ws->publicId()}/services/results?days={$days}")->assertOk()->json();
        expect($long['period']['granularity'])->toBe($granularity)
            ->and(array_sum(array_column($long['leads']['series'], 'leads')))->toBe(4)
            ->and(array_sum(array_column($long['leads']['series'], 'won')))->toBe(2);
    }
});

it('va en caché corta y responde rápido', function () {
    resultsLead($this, 'form');
    $url = "/api/w/{$this->ws->publicId()}/services/results";
    $this->actingAs($this->owner)->getJson($url)->assertOk()->assertJsonPath('kpis.leads.value', 1);
    resultsLead($this, 'form');
    // En caché: el segundo lead aparece al vencer la caché (2 minutos).
    $this->actingAs($this->owner)->getJson($url)->assertOk()->assertJsonPath('kpis.leads.value', 1);
    $this->travel(3)->minutes();
    $this->actingAs($this->owner)->getJson($url)->assertOk()->assertJsonPath('kpis.leads.value', 2);
});

it('solo cuenta lo del espacio de trabajo y respeta los permisos', function () {
    resultsLead($this, 'form');
    $other = $this->workspaceFor($this->userWith2fa(), 'Otra clínica');
    $theirs = (string) Str::ulid();
    DB::table('contacts')->insert(['id' => $theirs, 'workspace_id' => $other->id, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('leads')->insert(['id' => (string) Str::ulid(), 'workspace_id' => $other->id, 'contact_id' => $theirs, 'stage' => 'won', 'origin' => 'form',
        'value_ws' => '9999999', 'won_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    $res = $this->actingAs($this->owner)->getJson("/api/w/{$this->ws->publicId()}/services/results")->assertOk();
    expect($res->json('kpis.leads.value'))->toEqual(1)->and($res->json('won.count'))->toBe(0)->and($res->json('kpis.won_value.value'))->toEqual(0);

    $this->actingAs($this->owner)->getJson("/api/w/{$other->publicId()}/services/results")->assertNotFound();
    $viewer = $this->addMember($this->ws, WorkspaceRole::Viewer);
    $this->actingAs($viewer)->getJson("/api/w/{$this->ws->publicId()}/services/results")->assertOk();
    $agent = $this->addMember($this->ws, WorkspaceRole::Agent);
    $this->actingAs($agent)->getJson("/api/w/{$this->ws->publicId()}/services/results")->assertForbidden();
});
