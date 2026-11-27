<?php

use App\Domain\Consent\Policy\LegalWindow;
use App\Domain\Consent\Policy\PolicyDecision;
use App\Domain\Consent\Policy\PolicyRequest;
use App\Domain\Consent\Policy\SendPolicy;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Messaging\Enums\Channel;
use App\Domain\Messaging\Enums\MessageKind;
use App\Domain\Messaging\Models\ChannelAccount;
use App\Support\Holidays\Holidays;
use App\Support\Pii\BlindIndex;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Suite obligatoria de cumplimiento.md §4.2 (regla 6): ningún mensaje de
 * marketing sale en Colombia un domingo, un festivo o fuera de la ventana de la
 * Ley 2300; se aplaza al siguiente momento permitido y nunca se descarta.
 */

beforeEach(function () {
    $this->owner = $this->userWith2fa();
    $this->ws = $this->workspaceFor($this->owner);
    $this->ws->forceFill(['country' => 'CO', 'timezone' => 'America/Bogota'])->save();
    $this->policy = app(SendPolicy::class);
    [$this->contact, $this->wa, $this->email] = app(CurrentWorkspace::class)->run($this->ws, function () {
        $contact = Contact::query()->create([
            'workspace_id' => $this->ws->id, 'first_name' => 'Ana', 'country' => 'CO',
            'email' => 'ana@ejemplo.com', 'email_bidx' => BlindIndex::email($this->ws->id, 'ana@ejemplo.com'),
            'phone' => '+573001234567', 'phone_bidx' => BlindIndex::phone($this->ws->id, '+573001234567'),
        ]);
        foreach (['whatsapp', 'email'] as $channel) {
            DB::table('contact_consents')->insert([
                'id' => strtoupper((string) Str::ulid()), 'workspace_id' => $this->ws->id, 'contact_id' => $contact->id,
                'channel' => $channel, 'purpose' => 'marketing', 'status' => 'granted', 'source' => 'checkout',
                'granted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $wa = ChannelAccount::query()->create(['workspace_id' => $this->ws->id, 'channel' => 'whatsapp', 'provider' => 'meta_cloud', 'display_name' => 'WhatsApp', 'status' => 'active', 'quality_rating' => 'GREEN', 'is_default' => true]);
        $email = ChannelAccount::query()->create(['workspace_id' => $this->ws->id, 'channel' => 'email', 'provider' => 'ses', 'display_name' => 'Correo', 'status' => 'active', 'is_default' => true]);

        return [$contact, $wa, $email];
    });
});

/** Revisión de un WhatsApp de marketing a la hora local de Bogotá indicada. */
function policyAt($test, string $bogota, MessageKind $kind = MessageKind::Marketing, bool $reserve = false, Channel $channel = Channel::WhatsApp, array $extra = []): PolicyDecision
{
    $at = CarbonImmutable::parse($bogota, 'America/Bogota')->utc();

    return app(CurrentWorkspace::class)->run($test->ws, function () use ($test, $at, $kind, $reserve, $channel, $extra) {
        $request = new PolicyRequest($test->ws, $extra['contact'] ?? $test->contact->fresh(), $channel, $kind, $extra['source'] ?? 'flow', $extra['account'] ?? null, $at, $extra['test'] ?? false, $extra['explicit'] ?? false);

        return $reserve ? $test->policy->checkAndReserve($request) : $test->policy->check($request);
    });
}

function bogota(PolicyDecision $d): string
{
    return $d->until?->setTimezone('America/Bogota')->format('Y-m-d H:i') ?? '-';
}

it('permite de lunes a viernes de 7:00 a 18:59 y aplaza desde las 19:00 al día siguiente a las 7:00', function () {
    // Martes 17 de noviembre de 2026.
    expect(policyAt($this, '2026-11-17 07:00:00')->allowed())->toBeTrue()
        ->and(policyAt($this, '2026-11-17 18:59:59')->allowed())->toBeTrue();
    $d = policyAt($this, '2026-11-17 19:00:00');
    expect($d->deferred())->toBeTrue()->and($d->reason)->toBe(PolicyDecision::OUTSIDE_HOURS)->and(bogota($d))->toBe('2026-11-18 07:00');
    $early = policyAt($this, '2026-11-17 06:59:00');
    expect(bogota($early))->toBe('2026-11-17 07:00');
});

it('el sábado solo de 8:00 a 15:00: a las 15:01 se aplaza al lunes (el domingo nunca)', function () {
    // Sábado 21 de noviembre de 2026; el lunes 23 no es festivo.
    expect(policyAt($this, '2026-11-21 07:59:00')->deferred())->toBeTrue()
        ->and(bogota(policyAt($this, '2026-11-21 07:59:00')))->toBe('2026-11-21 08:00')
        ->and(policyAt($this, '2026-11-21 14:59:59')->allowed())->toBeTrue();
    $d = policyAt($this, '2026-11-21 15:01:00');
    expect($d->reason)->toBe(PolicyDecision::OUTSIDE_HOURS)->and(bogota($d))->toBe('2026-11-23 07:00');
    expect(policyAt($this, '2026-11-21 15:00:00')->deferred())->toBeTrue();
});

it('el domingo no sale nada de marketing: se aplaza al lunes a las 7:00', function () {
    $d = policyAt($this, '2026-11-22 10:00:00');
    expect($d->deferred())->toBeTrue()->and($d->reason)->toBe(PolicyDecision::SUNDAY)->and(bogota($d))->toBe('2026-11-23 07:00');
});

it('los festivos (fijos, de Ley Emiliani y de Pascua) se saltan aunque caigan entre semana', function (string $holiday, string $next) {
    $d = policyAt($this, $holiday.' 10:00:00');
    expect($d->reason)->toBe(PolicyDecision::HOLIDAY)->and(bogota($d))->toBe($next);
})->with([
    'Inmaculada (martes)' => ['2026-12-08', '2026-12-09 07:00'],
    'Reyes trasladado al lunes' => ['2027-01-11', '2027-01-12 07:00'],
    'Jueves Santo 2027' => ['2027-03-25', '2027-03-27 08:00'],   // jueves y viernes santos → sábado a las 8:00
    'Navidad (viernes)' => ['2026-12-25', '2026-12-26 08:00'],
    'Año Nuevo 2027 (viernes)' => ['2027-01-01', '2027-01-02 08:00'],
]);

it('cambio de día: un mensaje de las 23:30 sale el día siguiente a las 7:00 y un sábado tarde salta domingo y festivo', function () {
    expect(bogota(policyAt($this, '2026-11-18 23:30:00')))->toBe('2026-11-19 07:00');
    // Sábado 4 de julio de 2026 a las 16:00 → domingo 5 → lunes 6 normal.
    expect(bogota(policyAt($this, '2026-07-04 16:00:00')))->toBe('2026-07-06 07:00');
    // Sábado 15 de agosto de 2026 a las 16:00 → domingo 16 → lunes 17 (Asunción trasladada) → martes 18.
    expect(bogota(policyAt($this, '2026-08-15 16:00:00')))->toBe('2026-08-18 07:00');
});

it('lo transaccional y la utilidad no tienen ventana ni frecuencia', function () {
    expect(policyAt($this, '2026-11-22 23:00:00', MessageKind::Transactional)->allowed())->toBeTrue()
        ->and(policyAt($this, '2026-12-25 03:00:00', MessageKind::Utility)->allowed())->toBeTrue();
});

it('frecuencia: un comercial al día sumando canales; el segundo se aplaza al día siguiente, y el tope semanal a la otra semana', function () {
    expect(policyAt($this, '2026-11-17 10:00:00', reserve: true)->allowed())->toBeTrue();
    // El mismo día por correo: ya recibió uno (se suman los canales).
    $second = policyAt($this, '2026-11-17 11:00:00', reserve: true, channel: Channel::Email);
    expect($second->reason)->toBe(PolicyDecision::FREQUENCY_DAY)->and(bogota($second))->toBe('2026-11-18 07:00');
    expect(policyAt($this, '2026-11-18 07:00:00', reserve: true)->allowed())->toBeTrue()
        ->and(policyAt($this, '2026-11-19 07:00:00', reserve: true)->allowed())->toBeTrue();
    // Cuarto de la semana (tope 3): al lunes siguiente.
    $fourth = policyAt($this, '2026-11-20 07:00:00', reserve: true);
    expect($fourth->reason)->toBe(PolicyDecision::FREQUENCY_WEEK)->and(bogota($fourth))->toBe('2026-11-23 07:00');
    // check() no reserva: revisar varias veces no gasta el cupo.
    expect(policyAt($this, '2026-11-24 10:00:00')->allowed())->toBeTrue()->and(policyAt($this, '2026-11-24 10:00:00')->allowed())->toBeTrue();
});

it('dos reservas simultáneas no pasan las dos (el contador es atómico)', function () {
    $results = [policyAt($this, '2026-11-17 10:00:00', reserve: true), policyAt($this, '2026-11-17 10:00:00', reserve: true)];
    expect(array_filter($results, fn ($d) => $d->allowed()))->toHaveCount(1);
});

it('un canal por semana (opcional) y la preferencia «semanal» del contacto', function () {
    $this->ws->forceFill(['settings' => ['frequency' => ['marketing_per_day' => 2, 'marketing_per_week' => 5, 'one_channel_per_week' => true]] + $this->ws->settings])->save();
    expect(policyAt($this, '2026-11-17 10:00:00', reserve: true)->allowed())->toBeTrue();
    expect(policyAt($this, '2026-11-17 11:00:00', reserve: true, channel: Channel::Email)->reason)->toBe(PolicyDecision::FREQUENCY_CHANNEL_WEEK);
    DB::table('contact_preferences')->insert(['contact_id' => $this->contact->id, 'workspace_id' => $this->ws->id, 'frequency' => 'weekly', 'updated_at' => now()]);
    expect(policyAt($this, '2026-11-18 10:00:00', reserve: true)->reason)->toBe(PolicyDecision::FREQUENCY_PREFERENCE);
});

it('sin consentimiento, suprimido, dado de baja o en el RNE no sale marketing; lo transaccional respeta solo rebotes y bloqueos', function () {
    DB::table('contact_consents')->where('contact_id', $this->contact->id)->where('channel', 'email')->update(['status' => 'revoked']);
    expect(policyAt($this, '2026-11-17 10:00:00', channel: Channel::Email)->reason)->toBe(PolicyDecision::NO_CONSENT);

    $suppress = fn (string $channel, string $bidx, string $reason) => DB::table('suppressions')->insert(['workspace_id' => $this->ws->id, 'channel' => $channel, 'value_bidx' => $bidx, 'reason' => $reason]);
    $suppress('whatsapp', $this->contact->phone_bidx, 'rne');
    expect(policyAt($this, '2026-11-17 10:00:00')->reason)->toBe(PolicyDecision::EXCLUDED_REGISTRY)
        ->and(policyAt($this, '2026-11-17 10:00:00', MessageKind::Transactional)->allowed())->toBeTrue();
    DB::table('suppressions')->delete();
    $suppress('whatsapp', $this->contact->phone_bidx, 'wa_block');
    expect(policyAt($this, '2026-11-17 10:00:00', MessageKind::Transactional)->reason)->toBe(PolicyDecision::SUPPRESSED);
});

it('cuenta del canal: Zender nunca envía marketing; la calidad baja frena el marketing (en amarillo solo campañas)', function () {
    expect(policyAt($this, '2026-11-17 10:00:00', extra: ['source' => 'campaign'])->allowed())->toBeTrue();
    $this->wa->forceFill(['quality_rating' => 'YELLOW'])->save();
    expect(policyAt($this, '2026-11-17 10:00:00', extra: ['source' => 'campaign'])->reason)->toBe(PolicyDecision::QUALITY_LOW)
        ->and(policyAt($this, '2026-11-17 10:00:00')->allowed())->toBeTrue();
    $this->wa->forceFill(['quality_rating' => 'RED'])->save();
    expect(policyAt($this, '2026-11-17 10:00:00')->reason)->toBe(PolicyDecision::QUALITY_LOW);
    $zender = app(CurrentWorkspace::class)->run($this->ws, fn () => ChannelAccount::query()->create(['workspace_id' => $this->ws->id, 'channel' => 'whatsapp', 'provider' => 'zender', 'display_name' => 'Zender', 'status' => 'active']));
    expect(policyAt($this, '2026-11-17 10:00:00', extra: ['account' => $zender])->reason)->toBe(PolicyDecision::UNOFFICIAL_MARKETING)
        ->and(policyAt($this, '2026-11-17 10:00:00', MessageKind::Transactional, extra: ['account' => $zender])->allowed())->toBeTrue()
        // La ventana de 24 h es de la API oficial: por un WhatsApp no oficial la respuesta de servicio sale sin ella.
        ->and(policyAt($this, '2026-11-17 10:00:00', MessageKind::Service)->reason)->toBe(PolicyDecision::OUTSIDE_24H_WINDOW)
        ->and(policyAt($this, '2026-11-17 10:00:00', MessageKind::Service, extra: ['account' => $zender])->allowed())->toBeTrue();
    $this->email->forceFill(['status' => 'paused'])->save();
    expect(policyAt($this, '2026-11-17 10:00:00', channel: Channel::Email)->reason)->toBe(PolicyDecision::CHANNEL_UNAVAILABLE);
});

it('WhatsApp libre (servicio) solo dentro de la ventana de 24 h; el modo prueba y los avisos al equipo no miran al contacto', function () {
    expect(policyAt($this, '2026-11-17 10:00:00', MessageKind::Service)->reason)->toBe(PolicyDecision::OUTSIDE_24H_WINDOW);
    DB::table('conversations')->insert(['id' => strtoupper((string) Str::ulid()), 'workspace_id' => $this->ws->id, 'contact_id' => $this->contact->id, 'channel_account_id' => $this->wa->id, 'status' => 'open', 'window_expires_at' => CarbonImmutable::parse('2026-11-17 20:00:00', 'America/Bogota')->utc(), 'created_at' => now(), 'updated_at' => now()]);
    expect(policyAt($this, '2026-11-17 10:00:00', MessageKind::Service)->allowed())->toBeTrue();

    expect(policyAt($this, '2026-11-22 23:00:00', extra: ['test' => true])->allowed())->toBeTrue();
    $noone = app(CurrentWorkspace::class)->run($this->ws, fn () => Contact::query()->create(['workspace_id' => $this->ws->id, 'first_name' => 'Sin datos']));
    expect(policyAt($this, '2026-11-17 10:00:00', extra: ['contact' => $noone])->reason)->toBe(PolicyDecision::NO_ADDRESS)
        ->and(policyAt($this, '2026-11-17 10:00:00', MessageKind::Utility, extra: ['contact' => null, 'explicit' => true])->allowed())->toBeTrue();
});

it('el job de envío revisa otra vez la ventana justo antes del proveedor', function () {
    $request = app(CurrentWorkspace::class)->run($this->ws, fn () => new PolicyRequest($this->ws, $this->contact, Channel::WhatsApp, MessageKind::Marketing));
    $late = CarbonImmutable::parse('2026-11-17 19:00:30', 'America/Bogota');
    expect($this->policy->windowAllows($request, $late)->deferred())->toBeTrue()
        ->and($this->policy->windowAllows($request, $late->subMinute())->allowed())->toBeTrue();
});

it('la tabla de excepciones del admin agrega o quita festivos', function () {
    DB::table('holiday_overrides')->insert(['country' => 'CO', 'date' => '2026-11-24', 'is_holiday' => true, 'name' => 'Día cívico', 'created_at' => now(), 'updated_at' => now()]);
    Holidays::flush();
    expect(policyAt($this, '2026-11-24 10:00:00')->reason)->toBe(PolicyDecision::HOLIDAY);
    DB::table('holiday_overrides')->delete();
    Holidays::flush();
    expect(LegalWindow::isOpen('CO', CarbonImmutable::parse('2026-11-24 10:00:00', 'America/Bogota')))->toBeTrue();
});

it('el admin de la plataforma agrega y quita festivos con igr:holidays', function () {
    $this->artisan('igr:holidays', ['country' => 'CO', 'date' => '2026-11-24', '--name' => 'Día cívico'])->assertSuccessful();
    expect(policyAt($this, '2026-11-24 10:00:00')->reason)->toBe(PolicyDecision::HOLIDAY);
    $this->artisan('igr:holidays', ['country' => 'CO', 'date' => '2026-12-08', '--remove' => true])->assertSuccessful();
    expect(policyAt($this, '2026-12-08 10:00:00')->allowed())->toBeTrue();
    $this->artisan('igr:holidays', ['country' => 'CO', 'date' => '2026-12-08', '--reset' => true])->assertSuccessful();
    $this->artisan('igr:holidays', ['country' => 'CO', 'date' => 'mañana'])->assertFailed();
    expect(policyAt($this, '2026-12-08 10:00:00')->reason)->toBe(PolicyDecision::HOLIDAY);
    DB::table('holiday_overrides')->delete();
    Holidays::flush();
});
