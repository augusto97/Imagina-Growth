<?php

use App\Domain\Bookings\Actions\ChangeAppointment;
use App\Domain\Bookings\Models\Appointment;
use App\Domain\Bookings\Models\BookingService;
use App\Domain\Flows\Actions\CreateFlowFromLibrary;
use App\Domain\Flows\Library\FlowLibrary;
use App\Domain\Flows\Library\LibraryAdapter;
use App\Domain\Flows\Models\Flow;
use App\Domain\Flows\Models\FlowRun;
use App\Domain\Messaging\Models\ChannelAccount;
use App\Domain\Messaging\Testing\FakeMessageDispatcher;
use App\Domain\Services\Leads\Actions\ChangeLeadStage;
use App\Domain\Services\Leads\Models\Lead;
use App\Domain\Services\Leads\Models\LeadTask;
use App\Domain\Services\Leads\Support\Pipelines;
use App\Ingest\HubEvents;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Flows\Support\FlowFixtures;

/*
 * Biblioteca ampliada (rediseño v2 §11.4, modulos.md M09): flujos 21 a 35. Todos se instalan y se activan en un
 * espacio de trabajo de servicios y en uno de tienda (con y sin WhatsApp), y los caminos principales funcionan de
 * punta a punta respetando el tipo de mensaje (marketing o utilidad), las salidas y las metas.
 */
uses(FlowFixtures::class);

const SERVICES_LIBRARY_KEYS = [
    'lead_nurture', 'quote_followup', 'appointment_confirmation', 'no_show_recovery', 'post_service', 'periodic_service',
    'event_registration', 'enrollment_followup', 'property_interest', 'lost_lead_revival', 'new_client_welcome',
    'services_reactivation', 'cross_sell', 'delivery_check', 'first_purchase_anniversary',
];

beforeEach(function () {
    $this->flowWorld();
});

function svcAt(string $at): CarbonImmutable
{
    return CarbonImmutable::parse($at, 'America/Bogota');
}

function svcTick($test): void
{
    $test->artisan('igr:flows:tick')->assertSuccessful();
}

function svcRuns($test): mixed
{
    return app(CurrentWorkspace::class)->run($test->ws, fn () => FlowRun::query()->orderBy('created_at')->get());
}

function svcCreate($test, string $key): array
{
    return app(CurrentWorkspace::class)->run($test->ws, fn () => app(CreateFlowFromLibrary::class)->handle($key, true));
}

function svcAppointment($test, CarbonImmutable $start, string $status = 'booked'): Appointment
{
    return app(CurrentWorkspace::class)->run($test->ws, function () use ($test, $start, $status) {
        $service = BookingService::query()->first() ?? BookingService::query()->create([
            'workspace_id' => $test->ws->id, 'name' => 'Limpieza dental', 'slug' => 'limpieza', 'duration_min' => 30, 'buffer_min' => 0,
            'price' => '120000', 'location' => 'onsite', 'min_notice_hours' => 1, 'max_days_ahead' => 60, 'active' => true,
        ]);

        return Appointment::query()->create([
            'workspace_id' => $test->ws->id, 'contact_id' => $test->contact->id, 'service_id' => $service->id,
            'starts_at' => $start->utc(), 'ends_at' => $start->addMinutes(30)->utc(), 'status' => $status, 'channel' => 'page',
            'manage_token' => Str::random(40),
        ]);
    });
}

function svcBooked(Appointment $a): array
{
    return ['appointment_id' => $a->publicId(), 'service_id' => 'bs_'.$a->service_id, 'starts_at' => $a->starts_at->toIso8601ZuluString(), 'duration_min' => 30];
}

function svcLead($test, string $stage = 'new', array $attrs = []): Lead
{
    return app(CurrentWorkspace::class)->run($test->ws, function () use ($test, $stage, $attrs) {
        $pipeline = Pipelines::default($test->ws->id);

        return Lead::query()->create($attrs + [
            'workspace_id' => $test->ws->id, 'pipeline_id' => $pipeline->id, 'contact_id' => $test->contact->id, 'stage' => $stage,
            'origin' => 'form', 'service' => 'Diseño de sonrisa', 'value_ws' => '2400000.00', 'score' => 20, 'temperature' => 'cold', 'score_reasons' => [],
        ]);
    });
}

it('los flujos 21 a 35 están en la biblioteca con sus números, fase y plantillas', function () {
    foreach (SERVICES_LIBRARY_KEYS as $i => $key) {
        $flow = FlowLibrary::find($key);
        expect($flow)->not->toBeNull($key)
            ->and($flow['number'])->toBe(21 + $i)
            ->and($flow['phase'])->toBe(5)
            ->and($flow['graph'])->not->toBeNull();
        foreach (LibraryAdapter::templateKeys($flow['graph']) as $templateKey) {
            expect(in_array($templateKey, FlowLibrary::TEMPLATE_KEYS, true) || $templateKey === 'team_alert')->toBeTrue("{$key}: {$templateKey}");
        }
    }
});

it('cada flujo se instala y se activa sin error en un espacio de servicios y en uno de tienda', function (string $key, string $type) {
    $this->ws->forceFill(['settings' => ['business_type' => $type === 'services' ? 'services' : 'store', 'vertical' => $type === 'services' ? 'health' : 'store']])->save();
    $created = svcCreate($this, $key);
    expect($created['issues'])->toBe([], $key)->and($created['activated'])->toBeTrue($key)
        ->and($created['flow']->status)->toBe(Flow::ACTIVE)->and($created['flow']->template_key)->toBe($key);

    // Solo con correo (sin WhatsApp conectado) también se activa: quedan los pasos de correo.
    DB::table('templates')->where('workspace_id', $this->ws->id)->where('channel', 'whatsapp')->delete();
    app(CurrentWorkspace::class)->run($this->ws, fn () => ChannelAccount::query()->where('channel', 'whatsapp')->update(['status' => 'disconnected']));
    app(CurrentWorkspace::class)->run($this->ws, fn () => Flow::query()->update(['status' => Flow::ARCHIVED]));
    $emailOnly = svcCreate($this, $key);
    expect($emailOnly['issues'])->toBe([], "{$key} sin WhatsApp")->and($emailOnly['activated'])->toBeTrue("{$key} sin WhatsApp");
    foreach ((array) app(CurrentWorkspace::class)->run($this->ws, fn () => $emailOnly['flow']->currentVersion()->graph['nodes']) as $node) {
        expect($node['type'])->not->toBe('send_whatsapp');
    }
})->with(SERVICES_LIBRARY_KEYS)->with(['services', 'store']);

it('22: una propuesta enviada sin respuesta recibe WhatsApp al día 2 y sale si el cliente responde', function () {
    $this->travelTo(svcAt('2026-11-16 09:00:00'));
    $fake = FakeMessageDispatcher::install();
    expect(svcCreate($this, 'quote_followup')['activated'])->toBeTrue();
    $lead = svcLead($this, 'contacted');
    // Entrar a «Propuesta» emite quote.sent (v8) y dispara el flujo.
    app(CurrentWorkspace::class)->run($this->ws, fn () => app(ChangeLeadStage::class)->handle($this->ws, $lead, 'proposal', 'user'));
    $run = svcRuns($this)->sole();
    expect($run->context['trigger'])->toBe('quote.sent')->and($run->context['props']['source'])->toBe('stage')->and($fake->dispatched)->toBe([]);

    $this->travel(2)->days();
    $this->travel(5)->minutes();
    svcTick($this);
    expect($fake->dispatched)->toHaveCount(1)
        ->and($fake->dispatched[0]->channel->value)->toBe('whatsapp')->and($fake->dispatched[0]->kind->value)->toBe('marketing')
        ->and($fake->dispatched[0]->context['cotizacion']['servicio'])->toBe('Diseño de sonrisa');

    app(HubEvents::class)->emit($this->ws, 'whatsapp.inbound', ['message_id' => 'msg_01JRESPONDE', 'type' => 'text'], $this->contact->id);
    expect(app(CurrentWorkspace::class)->run($this->ws, fn () => $run->refresh())->exit_reason)->toBe('exit_node');
    // Pasar de propuesta a negociación no vuelve a disparar.
    app(CurrentWorkspace::class)->run($this->ws, fn () => app(ChangeLeadStage::class)->handle($this->ws, $lead->refresh(), 'won', 'user'));
    expect(svcRuns($this))->toHaveCount(1);
});

it('22: sin respuesta en 10 días, último aviso y una llamada para el vendedor en «Hoy»; ganar el lead lo saca', function () {
    $this->travelTo(svcAt('2026-11-16 09:00:00'));
    $fake = FakeMessageDispatcher::install();
    svcCreate($this, 'quote_followup');
    $lead = svcLead($this, 'contacted');
    app(CurrentWorkspace::class)->run($this->ws, fn () => app(ChangeLeadStage::class)->handle($this->ws, $lead, 'proposal', 'user'));
    foreach ([2, 3, 5] as $days) {
        $this->travel($days)->days();
        $this->travel(5)->minutes();
        svcTick($this);
    }
    expect(array_map(fn ($m) => $m->channel->value, $fake->dispatched))->toBe(['whatsapp', 'email', 'whatsapp'])
        ->and(app(CurrentWorkspace::class)->run($this->ws, fn () => LeadTask::query()->where('lead_id', $lead->id)->where('kind', 'call')->count()))->toBe(1);

    // Otro ciclo: ganar el lead es la meta.
    app(CurrentWorkspace::class)->run($this->ws, fn () => Flow::query()->update(['reentry_policy' => ['mode' => 'always']]));
    $other = svcLead($this, 'contacted', ['contact_id' => $this->flowContact('otro@ejemplo.com', '+573009876543')->id]);
    app(CurrentWorkspace::class)->run($this->ws, fn () => app(ChangeLeadStage::class)->handle($this->ws, $other, 'proposal', 'user'));
    app(CurrentWorkspace::class)->run($this->ws, fn () => app(ChangeLeadStage::class)->handle($this->ws, $other->refresh(), 'won', 'user'));
    expect(svcRuns($this)->last()->exit_reason)->toBe('goal_reached');
});

it('23: pide confirmar 48 h antes, sigue la cita si se reprograma, y la confirmación es la meta', function () {
    $this->travelTo(svcAt('2026-11-16 09:00:00'));
    $fake = FakeMessageDispatcher::install();
    svcCreate($this, 'appointment_confirmation');
    $appt = svcAppointment($this, svcAt('2026-11-20 10:00:00'));
    app(HubEvents::class)->emit($this->ws, 'appointment.booked', svcBooked($appt), $this->contact->id, $this->site);
    $run = svcRuns($this)->sole();
    expect($run->status)->toBe('waiting')->and($run->next_run_at->toImmutable()->equalTo(svcAt('2026-11-18 10:00:00')))->toBeTrue();

    // Se reprograma para el día 21: al despertar el 18, vuelve a esperar hasta el 19.
    app(CurrentWorkspace::class)->run($this->ws, fn () => $appt->forceFill(['starts_at' => svcAt('2026-11-21 10:00:00')->utc(), 'status' => 'rescheduled'])->save());
    $this->travelTo(svcAt('2026-11-18 10:05:00'));
    svcTick($this);
    expect($fake->dispatched)->toBe([])
        ->and(app(CurrentWorkspace::class)->run($this->ws, fn () => $run->refresh())->next_run_at->toImmutable()->equalTo(svcAt('2026-11-19 10:00:00')))->toBeTrue();

    $this->travelTo(svcAt('2026-11-19 10:05:00'));
    svcTick($this);
    expect($fake->dispatched)->toHaveCount(1)
        ->and($fake->dispatched[0]->channel->value)->toBe('whatsapp')->and($fake->dispatched[0]->kind->value)->toBe('utility')
        ->and($fake->dispatched[0]->context['cita']['servicio'])->toBe('Limpieza dental');

    // «Confirmo»: la agenda la confirma y emite appointment.confirmed (v8), la meta del flujo.
    app(CurrentWorkspace::class)->run($this->ws, fn () => app(ChangeAppointment::class)->mark($this->ws, $appt->refresh(), 'confirmed', 'contact'));
    $run = app(CurrentWorkspace::class)->run($this->ws, fn () => $run->refresh());
    expect($run->exit_reason)->toBe('goal_reached')
        ->and($this->chRows($this->ws, "SELECT event FROM events WHERE workspace_id = {workspace_id:UInt32} AND event = 'appointment.confirmed'"))
        ->toHaveCount(1);
});

it('23: si no confirma en un día y la cita sigue, avisa al equipo; una cita cancelada saca el run', function () {
    $this->travelTo(svcAt('2026-11-16 09:00:00'));
    $fake = FakeMessageDispatcher::install();
    svcCreate($this, 'appointment_confirmation');
    $appt = svcAppointment($this, svcAt('2026-11-20 10:00:00'));
    app(HubEvents::class)->emit($this->ws, 'appointment.booked', svcBooked($appt), $this->contact->id, $this->site);
    $this->travelTo(svcAt('2026-11-18 10:05:00'));
    svcTick($this);
    $this->travelTo(svcAt('2026-11-19 10:10:00'));
    svcTick($this);
    expect($fake->dispatched)->toHaveCount(2)->and($fake->dispatched[1]->channel->value)->toBe('email')->and($fake->dispatched[1]->to)->toBe($this->owner->email);

    // Otra cita, cancelada antes de pedir la confirmación: el run sale sin enviar nada.
    app(CurrentWorkspace::class)->run($this->ws, fn () => Flow::query()->update(['reentry_policy' => ['mode' => 'always']]));
    $cancelled = svcAppointment($this, svcAt('2026-11-26 10:00:00'));
    app(HubEvents::class)->emit($this->ws, 'appointment.booked', svcBooked($cancelled), $this->contact->id, $this->site);
    app(CurrentWorkspace::class)->run($this->ws, fn () => $cancelled->forceFill(['status' => 'cancelled'])->save());
    $this->travelTo(svcAt('2026-11-24 10:05:00'));
    svcTick($this);
    expect($fake->dispatched)->toHaveCount(2)->and(svcRuns($this)->last()->exit_reason)->toBe('filter');
});

it('27: confirmación al instante, recordatorios un día y una hora antes y la grabación después', function () {
    $this->travelTo(svcAt('2026-11-16 09:00:00'));
    $fake = FakeMessageDispatcher::install();
    svcCreate($this, 'event_registration');
    $this->flowEvent('form.submitted', ['form_plugin' => 'elementor', 'form_id' => '7', 'form_name' => 'Webinar', 'fields' => [
        'email' => 'ana@ejemplo.com', 'event_name' => 'Webinar de ortodoncia invisible', 'event_date' => '2026-11-19 18:00', 'event_url' => 'https://meet.ejemplo.com/abc',
    ]]);
    expect($fake->dispatched)->toHaveCount(1)->and($fake->dispatched[0]->kind->value)->toBe('utility')
        ->and($fake->dispatched[0]->context['evento'])->toMatchArray(['nombre' => 'Webinar de ortodoncia invisible', 'hora' => '6:00 pm', 'enlace' => 'https://meet.ejemplo.com/abc']);
    $this->travelTo(svcAt('2026-11-18 18:05:00'));
    svcTick($this);
    $this->travelTo(svcAt('2026-11-19 17:05:00'));
    svcTick($this);
    $this->travelTo(svcAt('2026-11-19 21:05:00'));
    svcTick($this);
    expect(array_map(fn ($m) => $m->kind->value, $fake->dispatched))->toBe(['utility', 'utility', 'utility', 'marketing'])
        ->and($fake->dispatched[3]->channel->value)->toBe('email')
        ->and(svcRuns($this)->sole()->status)->toBe('completed');
});

it('27: quien se inscribe con menos de una hora recibe la confirmación y la grabación, sin recordatorios repetidos', function () {
    $this->travelTo(svcAt('2026-11-19 17:30:00'));
    $fake = FakeMessageDispatcher::install();
    svcCreate($this, 'event_registration');
    $this->flowEvent('form.submitted', ['form_plugin' => 'cf7', 'form_id' => '7', 'fields' => ['event_date' => '2026-11-19 18:00']]);
    expect($fake->dispatched)->toHaveCount(1);
    $this->travelTo(svcAt('2026-11-19 21:05:00'));
    svcTick($this);
    expect($fake->dispatched)->toHaveCount(2)->and($fake->dispatched[1]->kind->value)->toBe('marketing');
});

it('33: la compra que dispara la venta cruzada no es su meta; la siguiente sí', function () {
    $this->travelTo(svcAt('2026-11-16 09:00:00'));
    $fake = FakeMessageDispatcher::install();
    svcCreate($this, 'cross_sell');
    $this->flowEvent('order.paid', ['order' => $this->orderSnapshot()]);
    $run = svcRuns($this)->sole();
    expect($run->status)->toBe('waiting');
    $this->travel(7)->days();
    $this->travel(5)->minutes();
    svcTick($this);
    expect($fake->dispatched)->toHaveCount(1)->and($fake->dispatched[0]->channel->value)->toBe('email')
        ->and(app(CurrentWorkspace::class)->run($this->ws, fn () => $run->refresh())->status)->toBe('completed');
});

it('34: si el cliente responde que tuvo un problema con el pedido, avisa al dueño', function () {
    $this->travelTo(svcAt('2026-11-16 09:00:00'));
    $fake = FakeMessageDispatcher::install();
    svcCreate($this, 'delivery_check');
    $this->flowEvent('order.completed', ['order_id' => '1234', 'order' => $this->orderSnapshot(['status' => 'completed'])]);
    $this->travel(2)->days();
    $this->travel(5)->minutes();
    svcTick($this);
    expect($fake->dispatched)->toHaveCount(1)->and($fake->dispatched[0]->kind->value)->toBe('utility')->and($fake->dispatched[0]->context['pedido']['numero'])->toBe('1234');
    app(HubEvents::class)->emit($this->ws, 'message.replied', ['message_id' => 'msg_01JPROBLEMA', 'channel' => 'whatsapp', 'kind' => 'utility', 'source_type' => 'flow', 'button_payload' => 'delivery_problem'], $this->contact->id);
    svcTick($this);
    expect($fake->dispatched)->toHaveCount(2)->and($fake->dispatched[1]->to)->toBe($this->owner->email);
});

it('21: la nutrición sale cuando el lead avanza a una cotización', function () {
    $this->travelTo(svcAt('2026-11-16 09:00:00'));
    $fake = FakeMessageDispatcher::install();
    svcCreate($this, 'lead_nurture');
    $lead = svcLead($this);
    app(HubEvents::class)->emit($this->ws, 'lead.created', ['lead_id' => $lead->publicId(), 'origin' => 'form', 'score' => 20, 'temperature' => 'cold'], $this->contact->id, $this->site);
    $this->travel(1)->days();
    $this->travel(5)->minutes();
    svcTick($this);
    expect($fake->dispatched)->toHaveCount(1)->and($fake->dispatched[0]->channel->value)->toBe('email');
    app(CurrentWorkspace::class)->run($this->ws, fn () => app(ChangeLeadStage::class)->handle($this->ws, $lead, 'proposal', 'user'));
    $this->travel(5)->days();
    $this->travel(5)->minutes();
    svcTick($this);
    expect($fake->dispatched)->toHaveCount(1);
    expect(svcRuns($this)->firstWhere('flow_id', app(CurrentWorkspace::class)->run($this->ws, fn () => Flow::query()->where('template_key', 'lead_nurture')->value('id')))->exit_reason)->toBe('exit_node');
});

it('35: el aniversario de la primera compra entra por fecha con un cupón', function () {
    $this->fakeCoupons();
    $fake = FakeMessageDispatcher::install();
    app(CurrentWorkspace::class)->run($this->ws, fn () => $this->contact->forceFill(['first_order_at' => '2025-11-17 15:00:00'])->save());
    svcCreate($this, 'first_purchase_anniversary');
    $this->travelTo(svcAt('2026-11-17 10:05:00'));
    $this->artisan('igr:flows:date-triggers', ['--now' => now()->toIso8601String()])->assertSuccessful();
    svcTick($this);
    expect($fake->dispatched)->toHaveCount(1)->and($fake->dispatched[0]->context['cupon']['codigo'] ?? null)->toStartWith('ANIVERSARIO');
});

it('las de utilidad llevan su justificación y las demás son marketing (cumplimiento.md §4.1)', function () {
    $utility = [];
    foreach (SERVICES_LIBRARY_KEYS as $key) {
        foreach ((array) FlowLibrary::find($key)['graph']['nodes'] as $node) {
            if (in_array($node['type'], ['send_whatsapp', 'send_email'], true) && ($node['config']['kind'] ?? 'marketing') !== 'marketing') {
                expect(mb_strlen((string) ($node['config']['reclassify_reason'] ?? '')))->toBeGreaterThan(10);
                $utility[] = $node['config']['template_key'];
            }
        }
    }
    expect(array_values(array_unique($utility)))->toEqualCanonicalizing([
        'wa_appointment_confirm', 'appointment_confirm', 'wa_event_confirmation', 'event_confirmation', 'wa_event_reminder', 'event_reminder',
        'wa_new_client_welcome', 'new_client_welcome', 'wa_delivery_check', 'delivery_check',
    ]);
});
