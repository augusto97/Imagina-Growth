<?php

use App\Domain\Workspaces\Models\User;
use App\Support\Money\ExchangeRates;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/*
 * F11: tasas de cambio diarias contra el dólar (fuente libre de ExchangeRate-API), última tasa conocida si la fuente
 * falla y tasa manual por moneda en Plataforma para el superadmin.
 */

function fxResponse(array $rates, int $updatedAt): array
{
    return [
        'result' => 'success', 'provider' => 'https://www.exchangerate-api.com', 'base_code' => 'USD',
        'time_last_update_unix' => $updatedAt, 'time_next_update_unix' => $updatedAt + 86400, 'rates' => ['USD' => 1] + $rates,
    ];
}

beforeEach(function () {
    $this->admin = $this->userWith2fa(['is_platform_admin' => true]);
    // La respuesta de la fuente se cambia entre llamadas.
    $this->fx = Http::response('', 500);
    Http::fake(['open.er-api.com/*' => fn () => $this->fx]);
});

it('trae las tasas del día, las guarda contra el dólar y usa la última conocida si la fuente falla', function () {
    $day1 = strtotime('2026-11-18 00:02:00 UTC');
    $this->fx = Http::response(fxResponse(['COP' => 3_400.5, 'MXN' => 19.1, 'VES' => 900.25, 'PEN' => 3.43, 'CLP' => 1_007.8, 'ARS' => 1_562.9, 'EUR' => 0.91], $day1));
    $this->artisan('igr:fx:update')->assertSuccessful();
    expect(DB::table('fx_rates')->where('base', 'USD')->where('date', '2026-11-18')->count())->toBe(7)
        ->and((float) DB::table('fx_rates')->where('quote', 'COP')->value('rate'))->toBe(3400.5)
        ->and(DB::table('fx_rates')->where('quote', 'COP')->value('source'))->toBe('open.er-api.com');

    // Otro día, sin VES: VES sigue con su última tasa conocida.
    $day2 = strtotime('2026-11-19 00:02:00 UTC');
    $this->fx = Http::response(fxResponse(['COP' => 3_350.0, 'MXN' => 19.0, 'PEN' => 3.42, 'CLP' => 1_007.0, 'ARS' => 1_563.0, 'EUR' => 0.91], $day2));
    $this->artisan('igr:fx:update')->expectsOutputToContain('VES')->assertSuccessful();
    $fx = new ExchangeRates;
    expect($fx->perUsd('COP', '2026-11-19'))->toBe(3350.0)
        ->and($fx->perUsd('COP', '2026-11-18'))->toBe(3400.5)
        ->and($fx->perUsd('COP', '2026-11-25'))->toBe(3350.0)        // después: la última conocida
        ->and($fx->perUsd('COP', '2026-01-01'))->toBe(3400.5)        // antes de la primera: la primera
        ->and($fx->perUsd('VES', '2026-11-19'))->toBe(900.25)
        ->and($fx->perUsd('USD', '2026-11-19'))->toBe(1.0)
        // Entre dos monedas pasa por el dólar.
        ->and(round((float) $fx->convert(19.0, 'MXN', 'COP', '2026-11-19'), 2))->toBe(3350.0)
        ->and($fx->convert(10.0, 'USD', 'COP', '2026-11-19'))->toBe(33500.0)
        ->and($fx->convert(10.0, 'USD', 'BRL', '2026-11-19'))->toBeNull();   // sin tasa

    // La fuente cae: no se borra nada, el comando falla con el motivo y rige la última tasa.
    $this->fx = Http::response('', 503);
    $this->artisan('igr:fx:update')->expectsOutputToContain('503')->assertFailed();
    expect(DB::table('fx_rates')->where('quote', 'COP')->count())->toBe(2)->and((new ExchangeRates)->perUsd('COP', now()))->toBe(3350.0);
    $this->fx = Http::response(['result' => 'error', 'error-type' => 'unsupported-code']);
    $this->artisan('igr:fx:update')->assertFailed();
});

it('la tasa manual del superadmin manda desde su fecha hasta que se quita, y queda en la auditoría', function () {
    DB::table('fx_rates')->insert([
        ['date' => '2026-11-10', 'base' => 'USD', 'quote' => 'VES', 'rate' => 880, 'source' => 'open.er-api.com', 'fetched_at' => now()],
        ['date' => '2026-11-19', 'base' => 'USD', 'quote' => 'VES', 'rate' => 916, 'source' => 'open.er-api.com', 'fetched_at' => now()],
    ]);
    $owner = $this->userWith2fa();
    $this->actingAs($owner)->getJson('/api/platform/fx')->assertForbidden();
    $this->actingAs($owner)->putJson('/api/platform/fx/VES', ['rate' => 1])->assertForbidden();

    $list = $this->actingAs($this->admin)->getJson('/api/platform/fx')->assertOk();
    $ves = collect($list->json('currencies'))->firstWhere('currency', 'VES');
    expect($list->json('base'))->toBe('USD')->and($list->json('attribution_url'))->toBe('https://www.exchangerate-api.com')
        ->and($ves['rate'])->toEqual(916)->and($ves['manual'])->toBeFalse()->and($ves['auto']['date'])->toBe('2026-11-19');

    // Tasa oficial del BCV desde el 15: manda desde esa fecha; antes sigue la automática.
    $this->actingAs($this->admin)->putJson('/api/platform/fx/ves', ['rate' => 0])->assertUnprocessable()->assertJsonValidationErrors('rate');
    $this->actingAs($this->admin)->putJson('/api/platform/fx/BRL', ['rate' => 5])->assertUnprocessable()->assertJsonValidationErrors('currency');
    $res = $this->actingAs($this->admin)->putJson('/api/platform/fx/ves', ['rate' => 925.5, 'effective_from' => '2026-11-15', 'note' => 'Tasa oficial del BCV'])->assertOk();
    $ves = collect($res->json('currencies'))->firstWhere('currency', 'VES');
    expect($ves['manual'])->toBeTrue()->and($ves['rate'])->toEqual(925.5)->and($ves['override']['note'])->toBe('Tasa oficial del BCV');
    $fx = new ExchangeRates;
    expect($fx->perUsd('VES', '2026-11-20'))->toBe(925.5)->and($fx->perUsd('VES', '2026-11-12'))->toBe(880.0)
        ->and($fx->describe('VES')['manual'])->toBeTrue()->and($fx->describe('VES')['stale'])->toBeFalse();
    expect(DB::table('audit_logs')->where('action', 'platform.fx_override_set')->where('user_id', $this->admin->id)->exists())->toBeTrue();

    $this->actingAs($this->admin)->deleteJson('/api/platform/fx/VES')->assertOk();
    expect((new ExchangeRates)->perUsd('VES', '2026-11-20'))->toBe(916.0)
        ->and(DB::table('audit_logs')->where('action', 'platform.fx_override_removed')->exists())->toBeTrue();
});

it('«Actualizar ahora» trae las tasas sin esperar al programador y dice por qué falló', function () {
    $this->fx = Http::response('', 429);
    $this->actingAs($this->admin)->postJson('/api/platform/fx/refresh')->assertStatus(502)->assertJsonPath('last_update.ok', false);
    $this->fx = Http::response(fxResponse(['COP' => 3_345.39, 'MXN' => 19.03], now()->getTimestamp()));
    $res = $this->actingAs($this->admin)->postJson('/api/platform/fx/refresh')->assertOk();
    expect($res->json('last_update.ok'))->toBeTrue()
        ->and(collect($res->json('currencies'))->firstWhere('currency', 'COP')['rate'])->toEqual(3345.39)
        ->and(collect($res->json('currencies'))->firstWhere('currency', 'VES')['rate'])->toBeNull();
});

it('igr:doctor avisa si faltan tasas o están viejas', function () {
    $check = function (): array {
        Artisan::call('igr:doctor', ['--json' => true]);

        return collect(json_decode(trim(Artisan::output()), true)['checks'])->firstWhere('key', 'data.fx');
    };
    expect($check()['status'])->toBe('warning')->and($check()['items'])->toContain('COP');
    foreach (ExchangeRates::currencies() as $c) {
        DB::table('fx_rates')->insert(['date' => now()->subDays(10)->toDateString(), 'base' => 'USD', 'quote' => $c, 'rate' => 2, 'source' => 'open.er-api.com', 'fetched_at' => now()]);
    }
    expect($check()['title'])->toBe('Tasas de cambio desactualizadas');
    DB::table('fx_rates')->update(['date' => now()->toDateString()]);
    expect($check()['status'])->toBe('ok');
    expect(User::query()->where('is_platform_admin', true)->count())->toBe(1);
});
