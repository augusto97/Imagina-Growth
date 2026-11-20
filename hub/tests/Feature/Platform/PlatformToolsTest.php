<?php

use App\Domain\Campaigns\Support\WaRateCard;
use App\Domain\Platform\Settings\PlatformSettings;
use App\Domain\Workspaces\Models\User;
use App\Support\Holidays\Holidays;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/*
 * F17: lo que antes eran comandos tiene su equivalente en Plataforma → Ajustes: festivos extra por país
 * (igr:holidays), tarifas de WhatsApp vigentes (igr:wa-rates), administradores (igr:admin:create) y los planes
 * incluidos que falten (igr:plans:sync --missing).
 */

beforeEach(function () {
    $this->admin = $this->userWith2fa(['is_platform_admin' => true, 'password' => 'Clave-Admin-2026']);
});

it('agrega un festivo decretado, quita uno de la ley y vuelve a la ley, con auditoría', function () {
    expect(Holidays::isHoliday('CO', CarbonImmutable::parse('2026-07-03')))->toBeFalse();

    $res = $this->actingAs($this->admin)->putJson('/api/platform/holidays/co/2026-07-03', ['is_holiday' => true, 'name' => 'Día cívico decretado'])->assertOk();
    expect(collect($res->json('holidays'))->firstWhere('date', '2026-07-03'))->toMatchArray(['name' => 'Día cívico decretado', 'origin' => 'added'])
        ->and(Holidays::isHoliday('CO', CarbonImmutable::parse('2026-07-03')))->toBeTrue();

    // Quitar uno de la ley (20 de julio) y deshacerlo.
    $res = $this->actingAs($this->admin)->putJson('/api/platform/holidays/CO/2026-07-20', ['is_holiday' => false])->assertOk();
    expect(collect($res->json('holidays'))->firstWhere('date', '2026-07-20'))->toMatchArray(['origin' => 'removed', 'name' => 'Día de la Independencia'])
        ->and(Holidays::isHoliday('CO', CarbonImmutable::parse('2026-07-20')))->toBeFalse();
    $res = $this->actingAs($this->admin)->deleteJson('/api/platform/holidays/CO/2026-07-20')->assertOk();
    expect(collect($res->json('holidays'))->firstWhere('date', '2026-07-20')['origin'])->toBe('law')
        ->and(Holidays::isHoliday('CO', CarbonImmutable::parse('2026-07-20')))->toBeTrue();

    // Un festivo nuevo necesita nombre; país y fecha se validan.
    $this->actingAs($this->admin)->putJson('/api/platform/holidays/CO/2026-07-04', ['is_holiday' => true])->assertUnprocessable()->assertJsonValidationErrors('name');
    $this->actingAs($this->admin)->putJson('/api/platform/holidays/AR/2026-07-04', ['is_holiday' => true, 'name' => 'X'])->assertUnprocessable();
    $this->actingAs($this->admin)->putJson('/api/platform/holidays/CO/04-07-2026', ['is_holiday' => true, 'name' => 'X'])->assertUnprocessable();

    $list = $this->actingAs($this->admin)->getJson('/api/platform/holidays?country=VE&year=2027')->assertOk();
    expect($list->json('country'))->toBe('VE')->and($list->json('countries'))->toBe(['CO', 'MX', 'VE'])
        ->and(collect($list->json('holidays'))->pluck('origin')->unique()->values()->all())->toBe(['law'])
        ->and(DB::table('audit_logs')->where('action', 'platform.holiday_set')->count())->toBe(2)
        ->and(DB::table('audit_logs')->where('action', 'platform.holiday_removed')->count())->toBe(1);
});

it('un festivo agregado en otro proceso llega a los trabajos sin reiniciar', function () {
    $day = CarbonImmutable::parse('2026-09-14');
    expect(Holidays::isHoliday('MX', $day))->toBeFalse(); // queda en la memoria del proceso
    app(PlatformSettings::class)->version();

    // Otro proceso: la fila y el aviso de cambio (touch), sin tocar la memoria de este.
    DB::table('holiday_overrides')->insert(['country' => 'MX', 'date' => '2026-09-14', 'is_holiday' => true, 'name' => 'Decretado', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('platform_settings')->insert(['key' => PlatformSettings::GENERATION, 'value' => '"x"', 'encrypted' => false, 'created_at' => now(), 'updated_at' => now()]);
    Cache::forget(PlatformSettings::VERSION_KEY);
    Cache::forget(PlatformSettings::ROWS_KEY);
    expect(Holidays::isHoliday('MX', $day))->toBeFalse();

    dispatch(function () use ($day) {
        $GLOBALS['igr_holiday_job'] = Holidays::isHoliday('MX', $day);
    });
    expect($GLOBALS['igr_holiday_job'] ?? null)->toBeTrue();
    unset($GLOBALS['igr_holiday_job']);
});

it('actualiza las tarifas de WhatsApp vigentes desde una fecha y vuelve a cargar las incluidas', function () {
    $res = $this->actingAs($this->admin)->getJson('/api/platform/wa-rates')->assertOk();
    expect($res->json('effective_from'))->toBe(WaRateCard::EFFECTIVE_FROM)
        ->and($res->json('rates.CO.marketing'))->toBe(0.0125)
        ->and($res->json('markets'))->toContain('CO', 'MX', 'LA', 'ZZ')
        ->and($res->json('categories'))->toBe(['marketing', 'utility', 'authentication', 'service']);

    $rates = ['CO' => ['marketing' => 0.0131, 'utility' => 0.0009, 'authentication' => 0.0009, 'service' => 0.0009]];
    $res = $this->actingAs($this->admin)->putJson('/api/platform/wa-rates', ['effective_from' => '2027-01-01', 'rates' => $rates])->assertOk();
    expect($res->json('effective_from'))->toBe('2027-01-01')
        ->and($res->json('rates.CO.marketing'))->toBe(0.0131)
        ->and(WaRateCard::rate('CO', 'marketing', CarbonImmutable::parse('2027-02-01')))->toBe(0.0131)
        // Los mensajes de antes siguen con la tarifa de su fecha.
        ->and(WaRateCard::rate('CO', 'marketing', CarbonImmutable::parse('2026-11-01')))->toBe(0.0125)
        ->and(DB::table('audit_logs')->where('action', 'platform.wa_rates_set')->value('changes'))->toContain('2027-01-01');

    $this->actingAs($this->admin)->putJson('/api/platform/wa-rates', ['effective_from' => '2027-01-01', 'rates' => ['XX' => $rates['CO']]])->assertUnprocessable();
    $this->actingAs($this->admin)->putJson('/api/platform/wa-rates', ['effective_from' => '2027-01-01', 'rates' => ['CO' => ['marketing' => 0.01]]])->assertUnprocessable();
    $this->actingAs($this->admin)->putJson('/api/platform/wa-rates', ['effective_from' => '2027-01-01', 'rates' => ['CO' => ['marketing' => 5] + $rates['CO']]])->assertUnprocessable();

    DB::table('wa_rates')->delete();
    $this->actingAs($this->admin)->postJson('/api/platform/wa-rates/seed')->assertOk()->assertJsonPath('effective_from', WaRateCard::EFFECTIVE_FROM);
    expect(WaRateCard::rate('MX', 'marketing'))->toBe(0.0397);
});

it('agrega y quita administradores pidiendo la contraseña, sin dejar la plataforma sola', function () {
    $owner = $this->userWith2fa(['email' => 'duena@tienda.test']);
    $ws = $this->workspaceFor($owner);

    $this->actingAs($this->admin)->postJson('/api/platform/admins', ['email' => 'duena@tienda.test', 'password' => 'otra'])
        ->assertUnprocessable()->assertJsonPath('error', 'invalid_password');
    $res = $this->actingAs($this->admin)->postJson('/api/platform/admins', ['email' => 'Duena@Tienda.test', 'password' => 'Clave-Admin-2026'])->assertOk();
    $hash = $owner->password;
    expect($res->json('created'))->toBeFalse()->and($res->json('password'))->toBeNull()
        ->and($owner->refresh()->is_platform_admin)->toBeTrue()
        ->and($owner->password)->toBe($hash)
        ->and(DB::table('accounts')->where('id', $ws->account_id)->value('plan_id'))->toBe('plataforma')
        ->and(collect($res->json('data'))->pluck('email')->all())->toContain('duena@tienda.test');

    // Una persona nueva: contraseña generada que se muestra una sola vez.
    $res = $this->actingAs($this->admin)->postJson('/api/platform/admins', ['email' => 'nuevo@imagina.test', 'name' => 'Nuevo', 'password' => 'Clave-Admin-2026'])->assertCreated();
    $new = User::query()->where('email', 'nuevo@imagina.test')->firstOrFail();
    expect($res->json('created'))->toBeTrue()->and(Hash::check((string) $res->json('password'), $new->password))->toBeTrue()
        ->and($new->is_platform_admin)->toBeTrue()->and($res->json('admin.two_factor'))->toBeFalse()
        ->and(DB::table('audit_logs')->where('action', 'platform.admin_created')->value('changes'))->not->toContain((string) $res->json('password'));
    expect($this->actingAs($this->admin)->getJson('/api/platform/admins')->assertOk()->json('data'))->toHaveCount(3);

    // Quitar: nunca a uno mismo ni al último.
    $me = $this->admin->publicId();
    $this->actingAs($this->admin)->deleteJson("/api/platform/admins/{$me}", ['password' => 'Clave-Admin-2026'])->assertUnprocessable()->assertJsonPath('error', 'self');
    $this->actingAs($this->admin)->deleteJson('/api/platform/admins/'.$new->publicId(), ['password' => 'mala'])->assertUnprocessable();
    $this->actingAs($this->admin)->deleteJson('/api/platform/admins/'.$new->publicId(), ['password' => 'Clave-Admin-2026'])->assertOk();
    $this->actingAs($this->admin)->deleteJson('/api/platform/admins/'.$owner->publicId(), ['password' => 'Clave-Admin-2026'])->assertOk();
    expect($new->refresh()->is_platform_admin)->toBeFalse()
        ->and(DB::table('audit_logs')->where('action', 'platform.admin_removed')->count())->toBe(2)
        ->and(User::query()->where('is_platform_admin', true)->count())->toBe(1);
    // A quien ya no administra no se le puede quitar (no existe como administrador).
    DB::table('users')->where('id', $owner->id)->update(['is_platform_admin' => true]);
    DB::table('users')->where('id', $this->admin->id)->update(['is_platform_admin' => false]);
    $owner->forceFill(['password' => 'Clave-Duena-2026'])->save();
    $this->actingAs($owner->refresh())->deleteJson("/api/platform/admins/{$me}", ['password' => 'Clave-Duena-2026'])->assertNotFound();
});

it('crea desde el panel los planes incluidos que falten sin pisar los editados', function () {
    DB::table('plans')->where('id', 'tienda_pro')->delete();
    DB::table('plans')->where('id', 'tienda_crece')->update(['name' => 'Crece (editado)']);
    $res = $this->actingAs($this->admin)->postJson('/api/platform/plans/sync')->assertOk();
    expect($res->json('created'))->toBe(1)
        ->and(DB::table('plans')->where('id', 'tienda_pro')->exists())->toBeTrue()
        ->and(DB::table('plans')->where('id', 'tienda_crece')->value('name'))->toBe('Crece (editado)')
        ->and(collect($res->json('plans'))->pluck('id')->all())->toContain('tienda_pro');
    $this->actingAs($this->admin)->postJson('/api/platform/plans/sync')->assertOk()->assertJsonPath('created', 0);
});
