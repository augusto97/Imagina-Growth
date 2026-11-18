<?php

use App\Domain\Flows\Actions\CreateFlowFromLibrary;
use App\Domain\Flows\Library\FlowLibrary;
use App\Domain\Flows\Models\FlowRun;
use App\Domain\Messaging\Testing\FakeMessageDispatcher;
use App\Ingest\HubEvents;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Tests\Feature\Flows\Support\FlowFixtures;

/*
 * Flujos de la biblioteca de la Fase 5 (modulos.md M09): 18 «Lead de servicios»,
 * 19 «Recordatorio de cita» (lo envía la agenda) y 20 «Renovación».
 */
uses(FlowFixtures::class);

beforeEach(function () {
    $this->flowWorld();
});

function p5Bogota(string $at): CarbonImmutable
{
    return CarbonImmutable::parse($at, 'America/Bogota');
}

function p5Runs($test): mixed
{
    return app(CurrentWorkspace::class)->run($test->ws, fn () => FlowRun::query()->orderBy('created_at')->get());
}

it('el 19 lleva a la página de reservas de la agenda y el 18 y el 20 son flujos de la Fase 5', function () {
    expect(FlowLibrary::find('appointment_reminder'))->toMatchArray(['number' => 19, 'phase' => 5, 'graph' => null, 'module' => 'agenda?tab=pagina'])
        ->and(FlowLibrary::find('service_lead')['phase'])->toBe(5)
        ->and(FlowLibrary::find('service_lead')['trigger']['event'])->toBe('lead.created')
        ->and(FlowLibrary::find('renewal')['trigger'])->toBe(['type' => 'date', 'attribute' => 'renewal_at', 'offset_days' => -15, 'hour' => 10]);

    $library = collect($this->actingAs($this->owner)->getJson($this->api('/library'))->assertOk()->json('data'));
    expect($library->firstWhere('key', 'service_lead')['available'])->toBeTrue()
        ->and($library->firstWhere('key', 'service_lead')['missing_templates'])->toBe([])
        ->and($library->firstWhere('key', 'renewal')['available'])->toBeTrue()
        ->and($library->firstWhere('key', 'appointment_reminder')['module'])->toBe('agenda?tab=pagina');
});

it('flujo 18: un lead de formulario recibe respuesta al instante y seguimiento; sale cuando un vendedor lo mueve', function () {
    $this->travelTo(p5Bogota('2026-11-17 09:00:00'));
    $fake = FakeMessageDispatcher::install();
    $created = app(CurrentWorkspace::class)->run($this->ws, fn () => app(CreateFlowFromLibrary::class)->handle('service_lead', true));
    expect($created['activated'])->toBeTrue()->and($created['issues'])->toBe([]);

    // Un lead de la agenda o del cotizador no entra: ya tiene su propio camino.
    app(HubEvents::class)->emit($this->ws, 'lead.created', ['lead_id' => 'ld_01JRESERVA', 'origin' => 'booking', 'score' => 40, 'temperature' => 'warm'], $this->contact->id, $this->site);
    expect(p5Runs($this))->toHaveCount(0);

    app(HubEvents::class)->emit($this->ws, 'lead.created', ['lead_id' => 'ld_01JFORMULARIO', 'origin' => 'form', 'score' => 55, 'temperature' => 'warm', 'service' => 'Ortodoncia'], $this->contact->id, $this->site);
    flowTick($this);
    $run = p5Runs($this)->sole();
    expect($fake->dispatched)->toHaveCount(1)
        ->and($fake->dispatched[0]->channel->value)->toBe('whatsapp')
        ->and($fake->dispatched[0]->kind->value)->toBe('marketing');

    $this->travel(1)->days();
    $this->travel(5)->minutes();
    flowTick($this);
    expect($fake->dispatched)->toHaveCount(2)->and($fake->dispatched[1]->channel->value)->toBe('email');

    // El vendedor lo pasa a «Contactado»: sale con la meta y no hay tercer toque.
    app(HubEvents::class)->emit($this->ws, 'lead.stage_changed', ['lead_id' => 'ld_01JFORMULARIO', 'from' => 'new', 'to' => 'contacted', 'by' => 'user'], $this->contact->id, $this->site);
    $run = app(CurrentWorkspace::class)->run($this->ws, fn () => $run->refresh());
    expect($run->status)->toBe('completed')->and($run->exit_reason)->toBe('goal_reached');
    $this->travel(3)->days();
    flowTick($this);
    expect($fake->dispatched)->toHaveCount(2);
});

it('flujo 20: entra 15 días antes de la renovación y avisa al equipo si un día antes no ha renovado', function () {
    $fake = FakeMessageDispatcher::install();
    app(CurrentWorkspace::class)->run($this->ws, fn () => $this->contact->forceFill(['renewal_at' => '2026-12-02'])->save());
    $created = app(CurrentWorkspace::class)->run($this->ws, fn () => app(CreateFlowFromLibrary::class)->handle('renewal', true));
    expect($created['activated'])->toBeTrue()->and($created['issues'])->toBe([]);

    $this->travelTo(p5Bogota('2026-11-16 10:05:00'));
    $this->artisan('igr:flows:date-triggers', ['--now' => now()->toIso8601String()])->assertSuccessful();
    expect(p5Runs($this))->toHaveCount(0); // 16 días antes

    $this->travelTo(p5Bogota('2026-11-17 10:05:00'));
    $this->artisan('igr:flows:date-triggers', ['--now' => now()->toIso8601String()])->assertSuccessful();
    flowTick($this);
    expect(p5Runs($this))->toHaveCount(1)
        ->and($fake->dispatched)->toHaveCount(1)->and($fake->dispatched[0]->channel->value)->toBe('whatsapp');

    $this->travel(14)->days();
    $this->travel(5)->minutes();
    flowTick($this);
    expect($fake->dispatched)->toHaveCount(2)
        ->and($fake->dispatched[1]->channel->value)->toBe('email')
        ->and($fake->dispatched[1]->to)->toBe($this->owner->email);
    expect(app(CurrentWorkspace::class)->run($this->ws, fn () => p5Runs($this)->sole()->status))->toBe('completed');
});

it('flujo 20: renovar (lead ganado) lo saca antes del aviso al equipo', function () {
    $fake = FakeMessageDispatcher::install();
    app(CurrentWorkspace::class)->run($this->ws, fn () => $this->contact->forceFill(['renewal_at' => '2026-12-02'])->save());
    app(CurrentWorkspace::class)->run($this->ws, fn () => app(CreateFlowFromLibrary::class)->handle('renewal', true));
    $this->travelTo(p5Bogota('2026-11-17 10:05:00'));
    $this->artisan('igr:flows:date-triggers', ['--now' => now()->toIso8601String()])->assertSuccessful();
    flowTick($this);

    app(HubEvents::class)->emit($this->ws, 'lead.won', ['lead_id' => 'ld_01JRENUEVA', 'value' => '1200000.00', 'currency' => 'COP'], $this->contact->id, $this->site);
    $this->travel(15)->days();
    flowTick($this);
    $run = p5Runs($this)->sole();
    expect($run->exit_reason)->toBe('goal_reached')->and($fake->dispatched)->toHaveCount(1);
});
