<?php

require_once __DIR__.'/LeadsHelpers.php';

use App\Domain\Billing\Contracts\Entitlements;
use App\Domain\Intelligence\Ai\Testing\FakeAiGateway;
use App\Domain\Messaging\Testing\FakeMessageDispatcher;
use App\Domain\Services\Contracts\LeadIntake;
use App\Domain\Services\Data\LeadIntakeInput;
use App\Domain\Services\Leads\Actions\CaptureLead;
use App\Domain\Services\Leads\Actions\ChangeLeadStage;
use App\Domain\Services\Leads\Models\Lead;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Tenancy\CurrentWorkspace;

beforeEach(function () {
    leadsSetUp($this);
    $this->ai = FakeAiGateway::install();
    $this->ai->enabled = false;
    $this->messages = FakeMessageDispatcher::install();
});

it('LeadIntake es CaptureLead (reemplaza al NullLeadIntake)', function () {
    expect(app(LeadIntake::class))->toBeInstanceOf(CaptureLead::class);
});

it('un formulario del sitio crea el lead con su origen, servicio, fuente y puntaje', function () {
    $contact = leadsContact($this);
    $eventId = leadsForm($this, $contact, ['budget' => '$2.500.000']);

    $lead = leadsOne($this);
    expect($lead->origin)->toBe('form')
        ->and($lead->origin_ref)->toBe($eventId)
        ->and($lead->stage)->toBe('new')
        ->and($lead->service)->toBe('Asesoría laboral')
        ->and($lead->value_ws)->toBe('2500000.00')
        ->and($lead->source['channel'])->toBe('paid_google')
        ->and($lead->source['gclid'])->toBe('Cj0KCQ-test')
        ->and($lead->source['form_id'])->toBe('cf7:12')
        ->and($lead->site_id)->toBe($this->site->id);
    // +30 por el formulario y +10 por el correo corporativo.
    $signals = collect($lead->score_reasons)->pluck('points', 'signal')->all();
    expect($signals)->toBe(['form' => 30, 'corporate_email' => 10])
        ->and($lead->score)->toBe(40)
        ->and($lead->temperature)->toBe('warm');
    // El mensaje nunca se guarda en el lead ni en su historia.
    expect(json_encode([$lead->toArray(), leadsActivities($this, $lead)->toArray()]))->not->toContain('despido');
    // Puntaje en el perfil y lead.created en ClickHouse.
    expect((int) $contact->fresh()->lead_score)->toBe(40);
    $events = leadsHubEvents($this, 'lead.created');
    expect($events)->toHaveCount(1)->and(json_decode($events[0], true))->toMatchArray(['lead_id' => $lead->publicId(), 'origin' => 'form', 'score' => 40, 'temperature' => 'warm', 'channel' => 'paid_google', 'form_id' => 'cf7:12']);
});

it('no duplica: otro formulario del mismo contacto actualiza el lead abierto y el reintento no hace nada', function () {
    $contact = leadsContact($this);
    $first = leadsForm($this, $contact);
    leadsForm($this, $contact, ['service' => 'Otro servicio']);
    leadsForm($this, $contact, [], [], $first); // reintento del mismo evento

    $leads = leadsAll($this);
    expect($leads)->toHaveCount(1);
    $lead = $leads->first();
    expect($lead->service)->toBe('Asesoría laboral')
        ->and($lead->score)->toBe(40) // el formulario suma una sola vez
        ->and(leadsActivities($this, $lead)->where('kind', 'intake'))->toHaveCount(1)
        ->and(leadsHubEvents($this, 'lead.created'))->toHaveCount(1);
});

it('reabre un lead perdido hace menos de 30 días y crea uno nuevo si se perdió antes o se ganó', function () {
    $contact = leadsContact($this);
    leadsForm($this, $contact);
    $lead = leadsOne($this);
    leadsRun($this, fn () => app(ChangeLeadStage::class)->handle($this->ws, $lead, 'lost', 'user', null, null, 'Precio'));

    $this->travel(10)->days();
    leadsForm($this, $contact);
    $leads = leadsAll($this);
    expect($leads)->toHaveCount(1)
        ->and($leads->first()->stage)->toBe('new')
        ->and($leads->first()->lost_at)->toBeNull()
        ->and(leadsActivities($this, $leads->first())->where('kind', 'stage')->last()->data)->toMatchArray(['from' => 'lost', 'to' => 'new', 'reopened' => true]);

    leadsRun($this, fn () => app(ChangeLeadStage::class)->handle($this->ws, $leads->first()->fresh(), 'lost', 'user'));
    $this->travel(31)->days();
    leadsForm($this, $contact);
    expect(leadsAll($this))->toHaveCount(2);

    leadsRun($this, fn () => app(ChangeLeadStage::class)->handle($this->ws, leadsOne($this), 'won', 'user', null, '1000000'));
    leadsForm($this, $contact);
    expect(leadsAll($this))->toHaveCount(3);
});

it('es idempotente por origen y referencia para el cotizador, la agenda y la API', function () {
    $contact = leadsContact($this);
    $input = new LeadIntakeInput($this->ws->id, $contact->id, 'quote', 'qt_123', 'Pintura de fachada', '3500000.00', ['channel' => 'organic_search']);
    $a = app(LeadIntake::class)->capture($input);
    $b = app(LeadIntake::class)->capture($input);
    expect($a)->not->toBeNull()->and($b)->toBe($a)->and(leadsAll($this))->toHaveCount(1)
        ->and(leadsOne($this)->value_ws)->toBe('3500000.00');
});

it('no crea leads si el plan no incluye servicios', function () {
    app()->instance(Entitlements::class, new class implements Entitlements
    {
        public function allows(Workspace $workspace, string $module): bool
        {
            return $module !== 'services';
        }

        public function limit(Workspace $workspace, string $key): ?int
        {
            return null;
        }

        public function planFor(Workspace $workspace): ?string
        {
            return 'basico';
        }
    });
    leadsForm($this, leadsContact($this));
    expect(leadsAll($this))->toHaveCount(0);
});

it('aísla los leads por espacio de trabajo', function () {
    leadsForm($this, leadsContact($this));
    $other = $this->workspaceFor($this->userWith2fa(), 'Otro negocio');
    expect(app(CurrentWorkspace::class)->run($other, fn () => Lead::query()->count()))->toBe(0)
        ->and(leadsAll($this))->toHaveCount(1);
});
