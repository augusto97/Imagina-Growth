<?php

use App\Domain\Billing\Contracts\Entitlements;
use App\Domain\Billing\Models\Plan;
use App\Domain\Billing\Plans\PlanCatalog;
use App\Domain\Billing\Plans\PlanResolver;
use App\Domain\Billing\Plans\PlatformAccounts;
use App\Domain\Billing\Support\PlatformSending;
use App\Domain\Intelligence\Ai\Support\AiMeter;
use App\Domain\Messaging\Contracts\MessageDispatcher;
use App\Domain\Messaging\Data\DispatchResult;
use App\Domain\Messaging\Data\OutboundMessage;
use App\Domain\Messaging\Enums\Channel;
use App\Domain\Messaging\Enums\MessageKind;
use App\Domain\Workspaces\Actions\CreateWorkspace;
use App\Domain\Workspaces\Data\WorkspaceData;
use App\Domain\Workspaces\Enums\AccountRole;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Pennant\Feature;
use Tests\Feature\Messaging\Support\MessagingFixtures;

/*
 * S3 (docs/auditoria-2026-11.md): una cuenta sin plan ya no recibe todo. Rige el plan gratuito por defecto
 * (Diagnóstico): analítica, segmentos, RFM y hallazgos, IA mínima y sin correo por la cuenta de SES de la
 * plataforma. Las cuentas propias del superadmin tienen el plan `plataforma`.
 */
uses(MessagingFixtures::class);

beforeEach(function () {
    config(['igr-plans.default_plan' => 'diagnostico']);
    $this->setUpMessaging();
    $this->travelTo(CarbonImmutable::parse('2026-11-17 10:00:00', 'America/Bogota'));
});

function defaultPlanSend($test, Channel $channel = Channel::Email): DispatchResult
{
    return app(CurrentWorkspace::class)->run($test->ws, fn () => app(MessageDispatcher::class)->dispatch(new OutboundMessage(
        $test->ws->id, $test->contact->id, $channel, MessageKind::Marketing, 'flow',
        sourceId: 'fl_01', templateId: $channel === Channel::Email ? 'tpl_email' : 'tpl_wa', idempotencyKey: 'run:R1:n1:'.$channel->value,
    )));
}

it('el valor que trae la configuración es Diagnóstico, no «todo»', function () {
    $saved = [getenv('IGR_DEFAULT_PLAN'), $_ENV['IGR_DEFAULT_PLAN'] ?? null, $_SERVER['IGR_DEFAULT_PLAN'] ?? null];
    putenv('IGR_DEFAULT_PLAN');
    unset($_ENV['IGR_DEFAULT_PLAN'], $_SERVER['IGR_DEFAULT_PLAN']);
    try {
        $config = require base_path('config/igr-plans.php');
    } finally {
        $saved[0] === false ? putenv('IGR_DEFAULT_PLAN') : putenv('IGR_DEFAULT_PLAN='.$saved[0]);
        $saved[1] === null ? null : $_ENV['IGR_DEFAULT_PLAN'] = $saved[1];
        $saved[2] === null ? null : $_SERVER['IGR_DEFAULT_PLAN'] = $saved[2];
    }
    expect($config['default_plan'])->toBe(PlanCatalog::DEFAULT_PLAN)
        ->and($config['plans']['diagnostico']['limits']['emails'])->toBe(0)
        ->and($config['plans']['diagnostico']['modules'])->toBe(['ai'])
        ->and($config['plans']['plataforma']['modules'])->toBe($config['modules'])
        ->and($config['plans']['plataforma']['public'])->toBeFalse();
});

it('sin plan rige Diagnóstico: solo lo básico, IA mínima y sin tope de volumen', function () {
    $e = app(Entitlements::class);
    expect($e->planFor($this->ws))->toBe('diagnostico')
        ->and(app(PlanResolver::class)->source($this->ws))->toBe('default')
        ->and($e->allows($this->ws, 'ai'))->toBeTrue()
        ->and($e->allows($this->ws, 'inbox'))->toBeFalse()
        ->and($e->allows($this->ws, 'bookings'))->toBeFalse()
        ->and($e->allows($this->ws, 'api'))->toBeFalse()
        ->and($e->allows($this->ws, 'white_label'))->toBeFalse()
        ->and($e->limit($this->ws, 'emails'))->toBe(0)
        ->and($e->limit($this->ws, 'orders'))->toBeNull()
        ->and(app(AiMeter::class)->budget($this->ws->id))->toBe(1.0)
        // Los permisos de estado de la cuenta no dependen del plan.
        ->and($e->allows($this->ws, 'marketing_sends'))->toBeTrue()
        ->and($e->allows($this->ws, 'imports'))->toBeTrue();

    // El panel lo muestra como plan por defecto, con su tope de IA.
    $this->actingAs($this->owner)->getJson("/api/w/{$this->ws->publicId()}/billing")->assertOk()
        ->assertJsonPath('plan.id', 'diagnostico')->assertJsonPath('plan_source', 'default');
    $this->actingAs($this->owner)->getJson("/api/w/{$this->ws->publicId()}/ai/usage")->assertOk()->assertJsonPath('budget_usd', 1);
});

it('sin la fila del plan en la tabla usa la definición de la configuración, nunca «todo»', function () {
    Plan::query()->whereKey('diagnostico')->delete();
    app(PlanResolver::class)->refreshAll();
    $e = app(Entitlements::class);
    expect($e->planFor($this->ws))->toBe('diagnostico')
        ->and($e->allows($this->ws, 'inbox'))->toBeFalse()
        ->and($e->limit($this->ws, 'emails'))->toBe(0);

    // Una clave que no existe ni en la tabla ni en la configuración: el mínimo absoluto.
    config(['igr-plans.default_plan' => 'no_existe']);
    app(PlanResolver::class)->refreshAll();
    expect(app(Entitlements::class)->allows($this->ws, 'ai'))->toBeFalse()
        ->and(app(Entitlements::class)->limit($this->ws, 'emails'))->toBe(0)
        ->and(app(AiMeter::class)->budget($this->ws->id))->toBe(0.0);
});

it('con default_plan = none vuelve a «sin plan = todo» (solo desarrollo)', function () {
    config(['igr-plans.default_plan' => 'none']);
    app(PlanResolver::class)->refreshAll();
    $e = app(Entitlements::class);
    expect($e->planFor($this->ws))->toBeNull()
        ->and(app(PlanResolver::class)->source($this->ws))->toBe('unrestricted')
        ->and($e->allows($this->ws, 'white_label'))->toBeTrue()
        ->and($e->limit($this->ws, 'emails'))->toBeNull();
});

it('Diagnóstico no envía correo por la cuenta de SES de la plataforma, pero sí por SMTP propio y WhatsApp', function () {
    expect(PlatformSending::emailAllowed($this->ws))->toBeFalse();
    $result = defaultPlanSend($this);
    expect($result->status)->toBe(DispatchResult::DENIED)->and($result->reason)->toBe(PlatformSending::DENIED);

    // Conectar SES se rechaza con el motivo; SMTP sigue disponible.
    $this->actingAs($this->owner)->postJson("/api/w/{$this->ws->publicId()}/channels/email", [
        'provider' => 'ses', 'from_email' => 'hola@otra.co', 'from_name' => 'Otra',
    ])->assertUnprocessable()->assertJsonPath('errors.provider.0', PlatformSending::message());
    $this->actingAs($this->owner)->getJson("/api/w/{$this->ws->publicId()}/channels")->assertOk()->assertJsonPath('meta.platform_email', false);

    // WhatsApp lo paga cada cliente a Meta desde su cuenta: no depende del plan.
    Http::fake(['graph.test/*' => Http::response(['messages' => [['id' => 'wamid.1']]])]);
    expect(defaultPlanSend($this, Channel::WhatsApp)->status)->toBe(DispatchResult::QUEUED);
});

it('con un plan que incluye correos, SES vuelve a enviar', function () {
    DB::table('accounts')->where('id', $this->ws->account_id)->update(['plan_id' => 'tienda_inicio']);
    app(PlanResolver::class)->refresh($this->ws);
    expect(PlatformSending::emailAllowed($this->ws))->toBeTrue()
        ->and(defaultPlanSend($this)->status)->toBe(DispatchResult::QUEUED);
});

it('las rutas de módulos fuera del plan responden 402 sin plan', function () {
    $this->actingAs($this->owner)->postJson("/api/w/{$this->ws->publicId()}/bookings/services", ['name' => 'Asesoría', 'duration_minutes' => 30])
        ->assertStatus(402)->assertJsonPath('error', 'plan_required');
});

it('los módulos que Pennant guardó con la regla vieja se recalculan', function () {
    // Antes de este cambio, sin plan se guardaba «true» para todos los módulos.
    Feature::for($this->ws)->activate('module.inbox');
    expect(Feature::for($this->ws)->active('module.inbox'))->toBeTrue();
    DB::table('features')->where('name', 'like', 'module.%')->delete(); // lo que hace la migración
    Feature::flushCache();
    expect(app(Entitlements::class)->allows($this->ws, 'inbox'))->toBeFalse();

    // Editar el plan por defecto en Plataforma → Planes también rige a las cuentas sin plan.
    $admin = $this->userWith2fa(['is_platform_admin' => true]);
    $plan = Plan::query()->findOrFail('diagnostico');
    $this->actingAs($admin)->patchJson('/api/platform/plans/diagnostico', [
        'name' => $plan->name, 'metric' => 'orders', 'included' => 0, 'modules' => ['ai', 'inbox'],
        'limits' => ['emails' => 0, 'ai_budget_usd' => 1, 'users' => 2, 'api_requests_per_minute' => null],
        'price_usd' => 0, 'price_cop' => 0, 'public' => true,
    ])->assertOk();
    expect(app(Entitlements::class)->allows($this->ws, 'inbox'))->toBeTrue();
});

it('la cuenta que crea un superadmin para sí nace con el plan de la plataforma; la de un cliente no', function () {
    $admin = $this->userWith2fa(['is_platform_admin' => true]);
    $own = app(CreateWorkspace::class)->handle($admin, new WorkspaceData(name: 'Imagina La Web', currency: 'COP'));
    $e = app(Entitlements::class);
    expect(DB::table('accounts')->where('id', $own->account_id)->value('plan_id'))->toBe('plataforma')
        ->and($e->allows($own, 'white_label'))->toBeTrue()
        ->and($e->allows($own, 'agency_reports'))->toBeTrue()
        ->and($e->limit($own, 'emails'))->toBeNull()
        ->and($e->limit($own, 'orders'))->toBeNull()
        ->and(app(AiMeter::class)->budget($own->id))->toBe((float) config('igr-ai.monthly_budget_usd'))
        ->and(DB::table('accounts')->where('id', $this->ws->account_id)->value('plan_id'))->toBeNull();
});

it('la migración da el plan de la plataforma a las cuentas sin plan del superadmin y respeta las demás', function () {
    $admin = $this->userWith2fa(['is_platform_admin' => true]);
    // Dueño de un espacio (cuenta directa), miembro de una agencia y una cuenta suya que ya tiene plan.
    $direct = $this->workspaceFor($this->userWith2fa(), 'Tienda propia');
    DB::table('workspace_user')->where('workspace_id', $direct->id)->update(['user_id' => $admin->id]);
    $agency = $this->workspaceFor($this->userWith2fa(), 'Agencia propia');
    DB::table('account_user')->insert(['account_id' => $agency->account_id, 'user_id' => $admin->id, 'role' => AccountRole::AgencyOwner->value, 'created_at' => now(), 'updated_at' => now()]);
    $assigned = $this->workspaceFor($this->userWith2fa(), 'Con plan');
    DB::table('workspace_user')->where('workspace_id', $assigned->id)->update(['user_id' => $admin->id]);
    DB::table('accounts')->where('id', $assigned->account_id)->update(['plan_id' => 'tienda_crece']);
    // Un superadmin que solo administra (rol admin) no vuelve «suya» la cuenta de un cliente.
    DB::table('workspace_user')->insert(['workspace_id' => $this->ws->id, 'user_id' => $admin->id, 'role' => 'admin', 'created_at' => now(), 'updated_at' => now()]);

    $migration = require base_path('database/migrations/2026_11_25_300100_default_plan_and_platform_accounts.php');
    $migration->up();

    $plan = fn ($ws) => DB::table('accounts')->where('id', $ws->account_id)->value('plan_id');
    expect($plan($direct))->toBe('plataforma')
        ->and($plan($agency))->toBe('plataforma')
        ->and($plan($assigned))->toBe('tienda_crece')
        ->and($plan($this->ws))->toBeNull()
        ->and(PlatformAccounts::withoutPlan())->toBe([])
        ->and(Plan::query()->whereKey(['diagnostico', 'plataforma'])->count())->toBe(2);
});
