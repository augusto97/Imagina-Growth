<?php

use App\Domain\Contacts\Models\Contact;
use App\Domain\Loyalty\Contracts\LoyaltyLedger;
use App\Domain\Loyalty\Ledger\DatabaseLoyaltyLedger;
use App\Domain\Loyalty\Models\LoyaltyProgram;
use App\Domain\Loyalty\Models\LoyaltyReward;
use App\Domain\Loyalty\Models\LoyaltyTier;
use App\Domain\Loyalty\Support\ProgramRules;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Support\Facades\Http;

/*
 * Ayudas de las pruebas de M13. Todas las funciones llevan el prefijo `loyalty`
 * (las pruebas de Pest comparten el espacio de nombres global).
 */

/** @param array<string, mixed> $overrides */
function loyaltyProgram(Workspace $ws, array $overrides = []): LoyaltyProgram
{
    return app(CurrentWorkspace::class)->run($ws, function () use ($overrides) {
        $d = ProgramRules::defaults();
        $program = LoyaltyProgram::query()->create(array_replace_recursive([
            'status' => 'active', 'currency_name' => 'Puntos Pintor', 'earn_rules' => $d['earn_rules'], 'redeem_rules' => $d['redeem_rules'],
            'expiry_policy' => $d['expiry_policy'], 'pending_days' => 15, 'wallet_rules' => $d['wallet_rules'],
        ], $overrides));
        app(DatabaseLoyaltyLedger::class)->forgetProgram();

        return $program;
    });
}

/** @param array<string, mixed> $attrs */
function loyaltyReward(Workspace $ws, LoyaltyProgram $program, array $attrs = []): LoyaltyReward
{
    return app(CurrentWorkspace::class)->run($ws, fn () => LoyaltyReward::query()->create($attrs + [
        'program_id' => $program->id, 'name' => '$20.000 de descuento', 'kind' => 'coupon_fixed', 'points_cost' => 500, 'value' => '20000.00', 'status' => 'active',
    ]));
}

/** @return list<LoyaltyTier> Bronce (0), Plata (500.000) y Oro (2.000.000) por gasto en 12 meses */
function loyaltyTiers(Workspace $ws, LoyaltyProgram $program): array
{
    return app(CurrentWorkspace::class)->run($ws, fn () => [
        LoyaltyTier::query()->create(['program_id' => $program->id, 'name' => 'Bronce', 'rank' => 1, 'threshold_type' => 'spend_12m', 'threshold' => '0', 'benefits' => ['multiplier' => 1]]),
        LoyaltyTier::query()->create(['program_id' => $program->id, 'name' => 'Plata', 'rank' => 2, 'threshold_type' => 'spend_12m', 'threshold' => '500000', 'benefits' => ['multiplier' => 1.5, 'free_shipping' => true], 'wc_role' => 'cliente_plata']),
        LoyaltyTier::query()->create(['program_id' => $program->id, 'name' => 'Oro', 'rank' => 3, 'threshold_type' => 'spend_12m', 'threshold' => '2000000', 'benefits' => ['multiplier' => 2]]),
    ]);
}

function loyaltyContact(Workspace $ws, array $attrs = []): Contact
{
    return app(CurrentWorkspace::class)->run($ws, fn () => Contact::query()->create($attrs + ['workspace_id' => $ws->id, 'lifecycle_stage' => 'customer']));
}

/** Corre algo con el libro dentro del espacio de trabajo. */
function loyaltyIn(Workspace $ws, callable $fn): mixed
{
    return app(CurrentWorkspace::class)->run($ws, fn () => $fn(app(LoyaltyLedger::class)));
}

/**
 * El sitio acepta todos los comandos (cupones, niveles) y devuelve un ID de
 * cupón; con loyaltySiteDown(true) responde 500.
 */
function loyaltyFakeSite(string $host = 'tienda.co'): void
{
    Http::fake([$host.'/wp-json/imagina-growth/v1/commands' => fn () => app()->bound('loyalty.site_down')
        ? Http::response(['ok' => false, 'error' => ['code' => 'internal', 'message' => 'caída']], 500)
        : Http::response(['ok' => true, 'data' => ['coupon_id' => '991', 'ok' => true, 'disabled' => true]])]);
}

function loyaltySiteDown(bool $down): void
{
    if ($down) {
        app()->instance('loyalty.site_down', true);
    } else {
        app()->offsetUnset('loyalty.site_down');
    }
}
