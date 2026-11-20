<?php

use App\Domain\Consent\Policy\PolicyDecision;
use App\Domain\Consent\Policy\PolicyRequest;
use App\Domain\Consent\Policy\SendPolicy;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Messaging\Enums\Channel;
use App\Domain\Messaging\Enums\MessageKind;
use App\Domain\Messaging\Models\ChannelAccount;
use App\Support\Pii\BlindIndex;
use App\Support\Tenancy\CurrentWorkspace;
use App\Support\Tenancy\WorkspaceLocale;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Venezuela (cumplimiento.md §7): el estándar de Colombia en la hora de Caracas —lunes a viernes de 7:00 a
 * 19:00 y sábados de 8:00 a 15:00— y nunca domingos ni feriados nacionales (LOTTT art. 184 y Ley de Fiestas
 * Nacionales). Lo que cae fuera se aplaza al siguiente momento permitido; nunca se descarta.
 */

beforeEach(function () {
    $this->owner = $this->userWith2fa();
    $this->ws = $this->workspaceFor($this->owner, 'Pinturas El Ávila', 'USD');
    $this->ws->forceFill(['country' => 'VE', 'timezone' => 'America/Caracas', 'locale' => 'es-VE'])->save();
    $this->policy = app(SendPolicy::class);
    $this->contact = app(CurrentWorkspace::class)->run($this->ws, function () {
        $contact = Contact::query()->create([
            'workspace_id' => $this->ws->id, 'first_name' => 'Luis', 'country' => 'VE',
            'phone' => '+584121234567', 'phone_bidx' => BlindIndex::phone($this->ws->id, '+584121234567'),
        ]);
        DB::table('contact_consents')->insert([
            'id' => strtoupper((string) Str::ulid()), 'workspace_id' => $this->ws->id, 'contact_id' => $contact->id,
            'channel' => 'whatsapp', 'purpose' => 'marketing', 'status' => 'granted', 'source' => 'checkout',
            'granted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        ChannelAccount::query()->create(['workspace_id' => $this->ws->id, 'channel' => 'whatsapp', 'provider' => 'meta_cloud', 'display_name' => 'WhatsApp', 'status' => 'active', 'quality_rating' => 'GREEN', 'is_default' => true]);

        return $contact;
    });
});

/** Revisión de un WhatsApp a la hora local de Caracas indicada. */
function venezuelaPolicyAt($test, string $caracas, MessageKind $kind = MessageKind::Marketing, bool $window = false): PolicyDecision
{
    $at = CarbonImmutable::parse($caracas, 'America/Caracas')->utc();

    return app(CurrentWorkspace::class)->run($test->ws, function () use ($test, $at, $kind, $window) {
        $request = new PolicyRequest($test->ws, $test->contact->fresh(), Channel::WhatsApp, $kind, 'flow', null, $at);

        return $window ? $test->policy->windowAllows($request, $at) : $test->policy->check($request);
    });
}

function caracas(PolicyDecision $d): string
{
    return $d->until?->setTimezone('America/Caracas')->format('Y-m-d H:i') ?? '-';
}

it('de lunes a viernes de 7:00 a 18:59 en Caracas; desde las 19:00 se aplaza al día siguiente a las 7:00', function () {
    // Martes 17 de noviembre de 2026.
    expect(venezuelaPolicyAt($this, '2026-11-17 07:00:00')->allowed())->toBeTrue()
        ->and(venezuelaPolicyAt($this, '2026-11-17 18:59:59')->allowed())->toBeTrue();
    $late = venezuelaPolicyAt($this, '2026-11-17 19:00:00');
    expect($late->deferred())->toBeTrue()->and($late->reason)->toBe(PolicyDecision::OUTSIDE_HOURS)->and(caracas($late))->toBe('2026-11-18 07:00')
        ->and(caracas(venezuelaPolicyAt($this, '2026-11-17 06:30:00')))->toBe('2026-11-17 07:00');
});

it('el sábado solo de 8:00 a 15:00 y el domingo nunca', function () {
    expect(venezuelaPolicyAt($this, '2026-11-21 14:59:59')->allowed())->toBeTrue();
    $saturday = venezuelaPolicyAt($this, '2026-11-21 15:01:00');
    expect($saturday->reason)->toBe(PolicyDecision::OUTSIDE_HOURS)->and(caracas($saturday))->toBe('2026-11-23 07:00');
    $sunday = venezuelaPolicyAt($this, '2026-11-22 10:00:00');
    expect($sunday->reason)->toBe(PolicyDecision::SUNDAY)->and(caracas($sunday))->toBe('2026-11-23 07:00');
});

it('no sale marketing en feriado nacional: se aplaza al siguiente día permitido', function (string $holiday, string $next) {
    $d = venezuelaPolicyAt($this, $holiday);
    expect($d->deferred())->toBeTrue()->and($d->reason)->toBe(PolicyDecision::HOLIDAY)->and(caracas($d))->toBe($next);
    // El job revisa la misma ventana justo antes de llamar al proveedor.
    expect(venezuelaPolicyAt($this, $holiday, window: true)->reason)->toBe(PolicyDecision::HOLIDAY);
})->with([
    'lunes de Carnaval (salta el martes)' => ['2027-02-08 10:00:00', '2027-02-10 07:00'],
    'Jueves Santo (salta el Viernes Santo y va al sábado)' => ['2027-03-25 10:00:00', '2027-03-27 08:00'],
    '19 de abril' => ['2027-04-19 10:00:00', '2027-04-20 07:00'],
    'Batalla de Carabobo' => ['2026-06-24 10:00:00', '2026-06-25 07:00'],
    'Natalicio del Libertador' => ['2026-07-24 10:00:00', '2026-07-25 08:00'],
    'Día de la Resistencia Indígena' => ['2026-10-12 10:00:00', '2026-10-13 07:00'],
    'Nochebuena y Navidad' => ['2026-12-24 10:00:00', '2026-12-26 08:00'],
    'Fin de Año y Año Nuevo' => ['2026-12-31 10:00:00', '2027-01-02 08:00'],
]);

it('un sábado tarde salta el domingo y el lunes de Carnaval', function () {
    $d = venezuelaPolicyAt($this, '2027-02-06 16:00:00');
    expect(caracas($d))->toBe('2027-02-10 07:00');
});

it('lo transaccional y la utilidad salen en feriado', function () {
    expect(venezuelaPolicyAt($this, '2026-12-25 03:00:00', MessageKind::Transactional)->allowed())->toBeTrue()
        ->and(venezuelaPolicyAt($this, '2027-02-09 11:00:00', MessageKind::Utility)->allowed())->toBeTrue();
});

it('el motivo del aplazamiento dice «feriado» en el panel de Venezuela', function () {
    $d = venezuelaPolicyAt($this, '2026-12-25 10:00:00');
    expect(WorkspaceLocale::run($this->ws, fn () => PolicyDecision::label((string) $d->reason)))->toBe('Feriado: no se envían mensajes comerciales');
});
