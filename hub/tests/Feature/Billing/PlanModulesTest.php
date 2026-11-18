<?php

use App\Domain\Billing\Contracts\Entitlements;
use App\Domain\Bookings\Jobs\SyncAppointmentCalendar;
use App\Domain\Bookings\Models\Appointment;
use App\Domain\Messaging\Testing\FakeMessageDispatcher;
use App\Domain\Quotes\Models\Quote;
use App\Domain\Services\Contracts\LeadIntake;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;

require_once dirname(__DIR__).'/Bookings/BookingFixtures.php';
require_once dirname(__DIR__).'/Quotes/QuoteFixtures.php';

/*
 * Módulos del plan (M21) en la agenda, el cotizador y los leads (M18): sin el módulo
 * no se ofrecen horarios ni se reciben reservas, el cotizador del sitio desaparece y el
 * panel no deja crear nada nuevo (402 plan_required); lo que ya existe se sigue viendo.
 */

/** @param list<string> $deny */
function planGateDeny(array $deny): void
{
    app()->instance(Entitlements::class, new class($deny) implements Entitlements
    {
        /** @param list<string> $deny */
        public function __construct(private array $deny) {}

        public function allows(Workspace $workspace, string $module): bool
        {
            return ! in_array($module, $this->deny, true);
        }

        public function limit(Workspace $workspace, string $key): ?int
        {
            return null;
        }

        public function planFor(Workspace $workspace): ?string
        {
            return 'tienda_inicio';
        }
    });
}

beforeEach(function () {
    $this->useIngestInfrastructure();
    Queue::fake([SyncAppointmentCalendar::class]);
    $this->travelTo(CarbonImmutable::parse('2026-11-30 07:00', 'America/Bogota'));
    $this->owner = $this->userWith2fa();
    $this->ws = $this->workspaceFor($this->owner);
    $this->ws->forceFill(['timezone' => 'America/Bogota', 'country' => 'CO'])->save();
    [$this->site] = $this->connectedSite($this->ws);
    FakeMessageDispatcher::install();
    bookingFakeLeads();
    $this->service = bookingService($this->ws);
    bookingHours($this->ws);
    $this->calc = quoteCalculator($this->ws);
    $this->pub = '/p/'.$this->site->public_key;
    $this->api = "/api/w/{$this->ws->publicId()}";
});

it('sin la agenda en el plan, la página de reservas no ofrece horarios ni recibe citas', function () {
    $this->get("{$this->pub}/reservar")->assertOk()->assertSee('9:00 am');
    planGateDeny(['bookings']);

    $this->get("{$this->pub}/reservar")->assertNotFound()->assertSee('Esta página no está disponible')->assertDontSee('name="start"', false);
    $this->get("{$this->pub}/reservar/valoracion")->assertNotFound()->assertDontSee('name="start"', false);
    $this->post("{$this->pub}/reservar/valoracion", ['start' => '2026-11-30T15:00:00Z', 'name' => 'Ana Ruiz', 'phone' => '300 123 4567'])->assertNotFound();
    expect(bookingIn($this->ws, fn () => Appointment::query()->count()))->toBe(0);
});

it('sin el cotizador en el plan, el sitio no lo muestra ni calcula', function () {
    $url = "{$this->pub}/quotes/{$this->calc->publicId()}";
    $this->getJson($url, ['Origin' => 'https://tienda.co'])->assertOk();
    planGateDeny(['quotes']);

    $this->getJson($url, ['Origin' => 'https://tienda.co'])->assertNotFound()->assertJsonPath('error.code', 'unknown_calculator');
    $this->call('POST', $url.'/estimate', [], [], [], ['CONTENT_TYPE' => 'text/plain;charset=UTF-8', 'HTTP_ORIGIN' => 'https://tienda.co'],
        json_encode(['answers' => ['area' => 40, 'tipo' => 'esmalte'], 'fields' => ['phone' => '300 123 4567']]))->assertNotFound();
    expect(app(CurrentWorkspace::class)->run($this->ws, fn () => Quote::query()->count()))->toBe(0);
});

it('el panel no deja crear servicios, citas, cotizadores ni leads fuera del plan, pero sí ver lo que hay', function () {
    planGateDeny(['bookings', 'quotes', 'services']);
    $contact = bookingContact($this->ws);

    $this->actingAs($this->owner)->postJson("{$this->api}/bookings/services", ['name' => 'Limpieza dental'])
        ->assertStatus(402)->assertJsonPath('error', 'plan_required')->assertJsonPath('module', 'bookings')
        ->assertJsonPath('message', fn (string $m) => str_contains($m, 'agenda de citas'));
    $this->actingAs($this->owner)->postJson("{$this->api}/bookings/appointments", ['service_id' => $this->service->publicId(), 'contact_id' => $contact->publicId(), 'start' => '2026-11-30T15:00:00Z'])
        ->assertStatus(402)->assertJsonPath('error', 'plan_required');
    $this->actingAs($this->owner)->postJson("{$this->api}/quotes/calculators", ['name' => 'Fachadas', 'kind' => 'area', 'definition' => quoteAreaDefinition()])
        ->assertStatus(402)->assertJsonPath('module', 'quotes');
    $this->actingAs($this->owner)->postJson("{$this->api}/leads", ['contact_id' => $contact->publicId(), 'service' => 'Consultoría'])
        ->assertStatus(402)->assertJsonPath('module', 'services');

    $this->actingAs($this->owner)->getJson("{$this->api}/bookings")->assertOk();
    $this->actingAs($this->owner)->getJson("{$this->api}/quotes/calculators")->assertOk()->assertJsonCount(1, 'data');
    $this->actingAs($this->owner)->getJson("{$this->api}/leads")->assertOk();
});

it('con el plan completo crear sigue funcionando', function () {
    $this->actingAs($this->owner)->postJson("{$this->api}/bookings/services", ['name' => 'Limpieza dental'])->assertCreated();
    $this->actingAs($this->owner)->postJson("{$this->api}/quotes/calculators", ['name' => 'Fachadas', 'kind' => 'area', 'definition' => quoteAreaDefinition()])->assertCreated();
    app()->forgetInstance(LeadIntake::class); // el lead real (CaptureLead), no el de prueba de la agenda
    $this->actingAs($this->owner)->postJson("{$this->api}/leads", ['contact_id' => bookingContact($this->ws)->publicId(), 'service' => 'Consultoría'])->assertCreated();
});
