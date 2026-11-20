<?php

use App\Domain\Messaging\Testing\FakeMessageDispatcher;
use App\Domain\Services\Leads\Models\Lead;
use App\Domain\Services\Leads\Models\LeadSeller;
use App\Domain\Services\Leads\Models\LeadSettings;
use App\Domain\Workspaces\Enums\WorkspaceRole;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Tests\Feature\Flows\Support\FlowFixtures;

/*
 * Resumen matutino de «Hoy» al vendedor (M18, opcional): por WhatsApp con la plantilla de
 * utilidad de avisos al equipo o por correo, una vez al día y sin datos del cliente.
 */
uses(FlowFixtures::class);

beforeEach(function () {
    $this->flowWorld();
    $this->in = fn (callable $fn) => app(CurrentWorkspace::class)->run($this->ws, $fn);
    $this->seller = $this->addMember($this->ws, WorkspaceRole::Agent);
    $this->fake = FakeMessageDispatcher::install();
    $this->travelTo(CarbonImmutable::parse('2026-11-17 06:30:00', 'America/Bogota'));
    ($this->in)(function () {
        LeadSeller::query()->create(['workspace_id' => $this->ws->id, 'user_id' => $this->seller->id, 'phone' => '+573009998877', 'active' => true]);
        Lead::query()->create(['workspace_id' => $this->ws->id, 'contact_id' => $this->contact->id, 'owner_id' => $this->seller->id, 'stage' => 'new', 'stage_changed_at' => now(),
            'origin' => 'form', 'service' => 'Pintura de fachada', 'score' => 70, 'temperature' => 'hot', 'score_reasons' => [], 'last_activity_at' => now()]);
    });
});

it('a las 7:00 le manda al vendedor su lista de hoy por WhatsApp, una sola vez y sin datos del cliente', function () {
    ($this->in)(fn () => LeadSettings::query()->create(['workspace_id' => $this->ws->id, 'assistant' => ['digest' => true]]));

    $this->artisan('igr:leads:morning-digest')->assertSuccessful();
    expect($this->fake->dispatched)->toBe([]); // todavía no son las 7

    $this->travelTo(CarbonImmutable::parse('2026-11-17 07:10:00', 'America/Bogota'));
    $this->artisan('igr:leads:morning-digest')->assertSuccessful();
    $this->artisan('igr:leads:morning-digest')->assertSuccessful();
    expect($this->fake->dispatched)->toHaveCount(1);
    $m = $this->fake->dispatched[0];
    expect($m->channel->value)->toBe('whatsapp')->and($m->kind->value)->toBe('utility')->and($m->to)->toBe('+573009998877')->and($m->contactId)->toBeNull()
        ->and($m->context['aviso']['resumen'])->toContain('1 lead caliente sin contactar')
        ->and($m->context['aviso']['url'])->toEndWith('/hoy')
        ->and(json_encode($m->context))->not->toContain('Ana')->not->toContain('Pintura');

    // El domingo no sale.
    $this->travelTo(CarbonImmutable::parse('2026-11-22 08:00:00', 'America/Bogota'));
    $this->artisan('igr:leads:morning-digest')->assertSuccessful();
    expect($this->fake->dispatched)->toHaveCount(1);
});

it('apagado en Ajustes no sale nada', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-17 07:10:00', 'America/Bogota'));
    $this->artisan('igr:leads:morning-digest')->assertSuccessful();
    expect($this->fake->dispatched)->toBe([]);
});
