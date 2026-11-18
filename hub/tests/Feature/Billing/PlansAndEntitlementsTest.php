<?php

use App\Domain\Billing\Contracts\Entitlements;
use App\Domain\Billing\Models\Plan;
use App\Domain\Billing\Plans\PlanEntitlements;
use App\Domain\Billing\Plans\PlanResolver;
use App\Domain\Billing\Usage\BillingStatus;
use App\Domain\Workspaces\Actions\CreateWorkspace;
use App\Domain\Workspaces\Data\WorkspaceData;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;

beforeEach(function () {
    $this->artisan('igr:plans:sync')->assertSuccessful();
    $this->owner = $this->userWith2fa();
    $this->ws = $this->workspaceFor($this->owner);
});

function billingAssignPlan($ws, ?string $plan): void
{
    DB::table('accounts')->where('id', $ws->account_id)->update(['plan_id' => $plan]);
    app(PlanResolver::class)->refresh($ws);
}

it('sincroniza los planes de la configuración sin duplicar', function () {
    $this->artisan('igr:plans:sync')->assertSuccessful();
    expect(Plan::query()->count())->toBe(count(config('igr-plans.plans')))
        ->and(Plan::query()->find('tienda_crece')->modules)->toContain('loyalty')
        ->and(Plan::query()->find('servicios_inicio')->metric)->toBe('contacts');
});

it('reemplaza UnlimitedEntitlements por PlanEntitlements', function () {
    expect(app(Entitlements::class))->toBeInstanceOf(PlanEntitlements::class);
});

it('sin plan todo está permitido y no hay límites', function () {
    $e = app(Entitlements::class);
    expect($e->allows($this->ws, 'ai_agent'))->toBeTrue()
        ->and($e->allows($this->ws, 'white_label'))->toBeTrue()
        ->and($e->limit($this->ws, 'orders'))->toBeNull()
        ->and($e->planFor($this->ws))->toBeNull();
});

it('los módulos salen del plan de la cuenta con Pennant y cambian al cambiar de plan', function () {
    billingAssignPlan($this->ws, 'tienda_inicio');
    $e = app(Entitlements::class);
    expect($e->planFor($this->ws))->toBe('tienda_inicio')
        ->and($e->allows($this->ws, 'reviews'))->toBeTrue()
        ->and($e->allows($this->ws, 'loyalty'))->toBeFalse()
        ->and($e->allows($this->ws, 'inbox'))->toBeFalse()
        ->and(Feature::for($this->ws)->active('module.loyalty'))->toBeFalse()
        // Pennant guarda el valor resuelto.
        ->and(DB::table('features')->where('name', 'module.loyalty')->count())->toBe(1);

    billingAssignPlan($this->ws, 'tienda_crece');
    expect(app(Entitlements::class)->allows($this->ws, 'loyalty'))->toBeTrue();
});

it('el límite del volumen depende de la métrica del plan', function () {
    billingAssignPlan($this->ws, 'tienda_crece');
    $e = app(Entitlements::class);
    expect($e->limit($this->ws, 'orders'))->toBe(1500)
        ->and($e->limit($this->ws, 'contacts'))->toBeNull()
        ->and($e->limit($this->ws, 'emails'))->toBe(25000)
        ->and($e->limit($this->ws, 'ai_budget_usd'))->toBe(25)
        ->and($e->limit($this->ws, 'desconocido'))->toBeNull();

    billingAssignPlan($this->ws, 'servicios_inicio');
    expect(app(Entitlements::class)->limit($this->ws, 'contacts'))->toBe(1000)
        ->and(app(Entitlements::class)->limit($this->ws, 'orders'))->toBeNull();
});

it('el ajuste de la plataforma por espacio manda sobre el plan, en los dos sentidos', function () {
    billingAssignPlan($this->ws, 'tienda_inicio');
    expect(app(Entitlements::class)->allows($this->ws, 'inbox'))->toBeFalse();
    DB::table('workspace_feature_overrides')->insert(['workspace_id' => $this->ws->id, 'feature' => 'inbox', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('workspace_feature_overrides')->insert(['workspace_id' => $this->ws->id, 'feature' => 'reviews', 'enabled' => false, 'created_at' => now(), 'updated_at' => now()]);
    app(PlanResolver::class)->refresh($this->ws);

    expect(app(Entitlements::class)->allows($this->ws, 'inbox'))->toBeTrue()
        ->and(app(Entitlements::class)->allows($this->ws, 'reviews'))->toBeFalse();

    // Otro espacio de la misma cuenta no hereda el ajuste.
    $other = app(CreateWorkspace::class)->handle($this->owner, new WorkspaceData(name: 'Otra tienda', currency: 'COP'));
    DB::table('workspaces')->where('id', $other->id)->update(['account_id' => $this->ws->account_id]);
    $other->refresh();
    expect(app(Entitlements::class)->allows($other, 'inbox'))->toBeFalse();
});

it('una cuenta suspendida no envía marketing ni importa; en gracia sí', function () {
    $e = app(Entitlements::class);
    expect($e->allows($this->ws, 'marketing_sends'))->toBeTrue()->and($e->allows($this->ws, 'imports'))->toBeTrue();

    DB::table('accounts')->where('id', $this->ws->account_id)->update(['billing_status' => BillingStatus::GRACE]);
    expect($e->allows($this->ws, 'marketing_sends'))->toBeTrue();

    DB::table('accounts')->where('id', $this->ws->account_id)->update(['billing_status' => BillingStatus::SUSPENDED]);
    expect($e->allows($this->ws, 'marketing_sends'))->toBeFalse()
        ->and($e->allows($this->ws, 'imports'))->toBeFalse()
        // El resto sigue: datos, medición, bandeja.
        ->and($e->allows($this->ws, 'inbox'))->toBeTrue();
});
