<?php

use App\Analytics\Ch;
use App\Domain\Billing\Contracts\Entitlements;
use App\Domain\Billing\Mail\InvoiceMail;
use App\Domain\Billing\Models\PlanChangeRequest;
use App\Domain\Billing\Usage\BillingStatus;
use App\Domain\Workspaces\Enums\WorkspaceRole;
use App\Domain\Workspaces\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    Storage::fake('local');
    Mail::fake();
    $this->artisan('igr:plans:sync')->assertSuccessful();
    $this->admin = $this->userWith2fa(['is_platform_admin' => true]);
    $this->owner = $this->userWith2fa();
    $this->ws = $this->workspaceFor($this->owner);
    $this->account = $this->ws->account;
});

it('solo el superadmin entra al panel: dueños, agencias y anónimos no', function (string $method, string $path) {
    $this->actingAs($this->owner)->json($method, '/api/platform/'.$path)->assertForbidden();
    $agency = $this->userWith2fa();
    $this->account->users()->attach($agency->id, ['role' => 'agency_owner']);
    $this->actingAs($agency)->json($method, '/api/platform/'.$path)->assertForbidden();
})->with([
    ['GET', 'overview'], ['GET', 'health'], ['PUT', 'accounts/acc_x/plan'], ['PUT', 'workspaces/ws_x/features/inbox'],
    ['POST', 'accounts/acc_x/invoices'], ['POST', 'invoices/inv_x/paid'], ['POST', 'plan-requests/pcr_x/apply'],
]);

it('sin sesión responde 401 y sin 2FA, 403', function () {
    $this->getJson('/api/platform/overview')->assertUnauthorized();
    $no2fa = User::factory()->create(['is_platform_admin' => true]);
    config(['igr.require_two_factor' => true]);
    $this->actingAs($no2fa)->getJson('/api/platform/overview')->assertForbidden()->assertJsonPath('error', 'two_factor_required');
});

it('muestra cuentas y espacios con plan, uso y estado de cobro', function () {
    DB::table('accounts')->where('id', $this->account->id)->update(['plan_id' => 'tienda_inicio', 'billing_status' => 'grace', 'grace_ends_at' => now()->addDays(3)]);
    DB::table('usage_counters')->insert(['workspace_id' => $this->ws->id, 'period' => now('America/Bogota')->format('Y-m'), 'orders' => 310, 'computed_at' => now()]);
    $res = $this->actingAs($this->admin)->getJson('/api/platform/overview')->assertOk();
    $row = collect($res->json('accounts'))->firstWhere('id', $this->account->publicId());
    expect($row['plan_id'])->toBe('tienda_inicio')
        ->and($row['billing_status'])->toBe('grace')
        ->and($row['workspaces'][0]['usage'])->toMatchArray(['metric' => 'orders', 'value' => 310, 'state' => 'critical'])
        ->and(collect($res->json('plans'))->pluck('id'))->toContain('agencia');
});

it('la salud agrega ingesta, rechazos, fallos por proveedor, conversiones y costo de IA', function () {
    $this->useIngestInfrastructure();
    DB::table('ingest_rejections')->insert([['workspace_id' => $this->ws->id, 'reason' => 'schema', 'created_at' => now()], ['workspace_id' => $this->ws->id, 'reason' => 'schema', 'created_at' => now()->subDays(2)]]);
    DB::table('messages')->insert(['id' => strtoupper((string) Str::ulid()), 'workspace_id' => $this->ws->id, 'channel' => 'whatsapp', 'provider' => 'meta', 'direction' => 'out', 'kind' => 'marketing', 'source_type' => 'flow', 'status' => 'failed', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('ai_usage')->insert(['workspace_id' => $this->ws->id, 'date' => now()->toDateString(), 'feature' => 'analyst', 'model' => 'm', 'cost_usd' => 4.5]);
    app(Ch::class)->insert('conversion_deliveries', [['workspace_id' => $this->ws->ch_id, 'event_id' => (string) Str::uuid(), 'platform' => 'meta', 'status' => 'failed', 'http_status' => 400, 'error' => 'x', 'match_keys' => [], 'attempts' => 3, 'delivered_at' => now()->utc()->format('Y-m-d H:i:s.v')]]);
    DB::table('accounts')->where('id', $this->account->id)->update(['plan_id' => 'tienda_inicio']);

    $res = $this->actingAs($this->admin)->getJson('/api/platform/health')->assertOk();
    expect($res->json('rejections_24h'))->toBe([['reason' => 'schema', 'count' => 1]])
        ->and($res->json('failed_deliveries_24h'))->toBe([['channel' => 'whatsapp', 'provider' => 'meta', 'count' => 1]])
        ->and($res->json('conversion_failures_24h.0'))->toMatchArray(['platform' => 'meta', 'workspace_id' => $this->ws->publicId(), 'count' => 1])
        ->and($res->json('ai_cost_month.0'))->toMatchArray(['workspace_id' => $this->ws->publicId(), 'cost_usd' => 4.5, 'budget_usd' => 5, 'pct' => 90])
        ->and($res->json('ingest'))->toBeArray()->and($res->json('ingest.0'))->toHaveKeys(['shard', 'lag_seconds', 'pending']);
});

it('asigna un plan a la cuenta y los módulos cambian enseguida', function () {
    $this->actingAs($this->admin)->putJson('/api/platform/accounts/'.$this->account->publicId().'/plan', ['plan_id' => 'tienda_inicio'])->assertOk();
    expect(app(Entitlements::class)->allows($this->ws, 'loyalty'))->toBeFalse();
    $this->putJson('/api/platform/accounts/'.$this->account->publicId().'/plan', ['plan_id' => 'tienda_pro'])->assertOk();
    expect(app(Entitlements::class)->allows($this->ws, 'loyalty'))->toBeTrue()
        ->and(DB::table('audit_logs')->where('action', 'platform.plan_assigned')->count())->toBe(2);
    $this->putJson('/api/platform/accounts/'.$this->account->publicId().'/plan', ['plan_id' => 'no_existe'])->assertStatus(422);
    $this->putJson('/api/platform/accounts/acc_01JD3QF0A1B2C3D4E5F6G7H8J9/plan', ['plan_id' => 'tienda_pro'])->assertNotFound();
});

it('fuerza o quita un módulo para un espacio de trabajo', function () {
    DB::table('accounts')->where('id', $this->account->id)->update(['plan_id' => 'tienda_inicio']);
    $url = '/api/platform/workspaces/'.$this->ws->publicId().'/features/inbox';
    $this->actingAs($this->admin)->putJson($url, ['enabled' => true, 'reason' => 'Piloto'])->assertOk()->assertJsonPath('enabled', true);
    expect(app(Entitlements::class)->allows($this->ws, 'inbox'))->toBeTrue();
    $this->putJson($url, ['enabled' => null])->assertOk()->assertJsonPath('enabled', false);
    expect(app(Entitlements::class)->allows($this->ws, 'inbox'))->toBeFalse();
    $this->putJson('/api/platform/workspaces/'.$this->ws->publicId().'/features/inventado', ['enabled' => true])->assertNotFound();
});

it('crea, envía, cobra y anula facturas desde el panel', function () {
    DB::table('accounts')->where('id', $this->account->id)->update(['plan_id' => 'tienda_crece', 'billing_email' => 'cobros@pinturas.co']);
    $draft = $this->actingAs($this->admin)->getJson('/api/platform/accounts/'.$this->account->publicId().'/invoice-draft')->assertOk();
    expect($draft->json('currency'))->toBe('COP')->and($draft->json('lines.0.unit_price'))->toBe('599000.00');

    $res = $this->postJson('/api/platform/accounts/'.$this->account->publicId().'/invoices', [
        'period' => '2026-11', 'lines' => $draft->json('lines'), 'send' => true,
    ])->assertCreated()->assertJsonPath('status', 'sent')->assertJsonPath('has_pdf', true);
    Mail::assertSent(InvoiceMail::class);
    $id = $res->json('id');
    expect($res->json())->not->toHaveKey('pdf_path');

    $this->get('/api/platform/invoices/'.$id.'/pdf')->assertOk();
    $this->postJson('/api/platform/invoices/'.$id.'/paid')->assertOk()->assertJsonPath('status', 'paid');
    $this->postJson('/api/platform/invoices/'.$id.'/void')->assertStatus(422);
    $this->postJson('/api/platform/accounts/'.$this->account->publicId().'/invoices', ['period' => '2026-13', 'lines' => []])->assertStatus(422);
    expect(DB::table('audit_logs')->where('action', 'platform.invoice_paid')->count())->toBe(1);
});

it('aplica o rechaza los pedidos de cambio de plan', function () {
    $pcr = PlanChangeRequest::query()->create(['account_id' => $this->account->id, 'workspace_id' => $this->ws->id, 'to_plan' => 'tienda_pro', 'status' => 'pending']);
    $this->actingAs($this->admin)->getJson('/api/platform/overview')->assertJsonPath('plan_requests.0.to_plan', 'tienda_pro');
    $this->postJson('/api/platform/plan-requests/'.$pcr->publicId().'/apply')->assertOk()->assertJsonPath('status', 'applied');
    expect($this->account->refresh()->plan_id)->toBe('tienda_pro');
    $this->postJson('/api/platform/plan-requests/'.$pcr->publicId().'/reject')->assertStatus(422);
});

it('reactiva una cuenta suspendida a mano', function () {
    DB::table('accounts')->where('id', $this->account->id)->update(['billing_status' => BillingStatus::SUSPENDED]);
    $this->actingAs($this->admin)->putJson('/api/platform/accounts/'.$this->account->publicId().'/billing-status', ['status' => 'active'])->assertOk();
    expect(BillingStatus::of($this->account->id))->toBe(BillingStatus::ACTIVE)
        ->and(DB::table('audit_logs')->where('action', 'platform.billing_status')->count())->toBe(1);
});

it('un dueño con rol de espacio tampoco ve el panel aunque sea admin de otro espacio', function () {
    $admin = $this->addMember($this->ws, WorkspaceRole::Admin, twoFactor: true);
    $this->actingAs($admin)->getJson('/api/platform/overview')->assertForbidden();
});
