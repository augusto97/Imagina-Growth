<?php

use App\Domain\Billing\Invoices\InvoiceLifecycle;
use App\Domain\Billing\Invoices\IssueInvoice;
use App\Domain\Billing\Mail\PlanChangeRequestedMail;
use App\Domain\Billing\Plans\PlanResolver;
use App\Domain\Workspaces\Enums\WorkspaceRole;
use App\Domain\Workspaces\Models\Account;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    Storage::fake('local');
    Mail::fake();
    $this->artisan('igr:plans:sync')->assertSuccessful();
    $this->owner = $this->userWith2fa();
    $this->ws = $this->workspaceFor($this->owner);
    DB::table('accounts')->where('id', $this->ws->account_id)->update(['plan_id' => 'tienda_inicio']);
    app(PlanResolver::class)->refresh($this->ws);
    $this->url = "/api/w/{$this->ws->publicId()}/billing";
});

function billingInvoiceFor(Account $account, bool $send = true)
{
    $invoice = app(IssueInvoice::class)->handle($account, '2026-11', [['concept' => 'Plan', 'quantity' => 1, 'unit_price' => 49]], 'USD');
    if ($send) {
        app(InvoiceLifecycle::class)->send($invoice);
    }

    return $invoice->refresh();
}

it('el dueño ve plan, módulos, uso con estados 80/100 %, planes y facturas', function () {
    DB::table('usage_counters')->insert(['workspace_id' => $this->ws->id, 'period' => now('America/Bogota')->format('Y-m'), 'orders' => 255, 'emails' => 5000, 'computed_at' => now()]);
    billingInvoiceFor(Account::query()->find($this->ws->account_id));
    billingInvoiceFor(Account::query()->find($this->ws->account_id), send: false); // borrador: no se muestra

    $res = $this->actingAs($this->owner)->getJson($this->url)->assertOk()
        ->assertJsonPath('plan.id', 'tienda_inicio')
        ->assertJsonPath('account.billing_status', 'active')
        ->assertJsonPath('can_manage_billing', true)
        ->assertJsonCount(1, 'invoices');
    $meters = collect($res->json('usage.meters'))->keyBy('metric');
    expect($meters['orders'])->toMatchArray(['value' => 255, 'limit' => 300, 'pct' => 85, 'state' => 'warning'])
        ->and($meters['emails'])->toMatchArray(['pct' => 100, 'state' => 'critical'])
        ->and($meters['wa_marketing']['limit'])->toBeNull();
    $modules = collect($res->json('modules'))->keyBy('key');
    expect($modules['reviews']['enabled'])->toBeTrue()->and($modules['loyalty']['enabled'])->toBeFalse()
        ->and(collect($res->json('plans'))->pluck('id'))->not->toContain('agencia');
});

it('un administrador ve el plan y el uso pero no las facturas; marketing no entra', function () {
    $admin = $this->addMember($this->ws, WorkspaceRole::Admin, twoFactor: true);
    $this->actingAs($admin)->getJson($this->url)->assertOk()->assertJsonPath('invoices', null)->assertJsonPath('can_manage_billing', false);
    $this->actingAs($admin)->postJson($this->url.'/plan-request', ['plan_id' => 'tienda_crece'])->assertForbidden();
    $marketing = $this->addMember($this->ws, WorkspaceRole::Marketing);
    $this->actingAs($marketing)->getJson($this->url)->assertForbidden();
});

it('el dueño pide otro plan y avisa al equipo de la plataforma', function () {
    $admin = $this->userWith2fa(['is_platform_admin' => true, 'email' => 'equipo@imagina.co']);
    $this->actingAs($this->owner)->postJson($this->url.'/plan-request', ['plan_id' => 'tienda_crece', 'note' => 'Llega diciembre'])
        ->assertCreated()->assertJsonPath('status', 'pending');
    Mail::assertSent(PlanChangeRequestedMail::class, fn (PlanChangeRequestedMail $m) => $m->hasTo('equipo@imagina.co') && $m->note === 'Llega diciembre');
    $this->getJson($this->url)->assertJsonPath('pending_request.to_plan', 'tienda_crece');

    // Un plan interno o el mismo plan no se pueden pedir.
    $this->postJson($this->url.'/plan-request', ['plan_id' => 'agencia'])->assertStatus(422);
    $this->postJson($this->url.'/plan-request', ['plan_id' => 'tienda_inicio'])->assertStatus(422);
    // Solo queda un pedido pendiente por cuenta.
    $this->postJson($this->url.'/plan-request', ['plan_id' => 'tienda_pro'])->assertCreated();
    expect(DB::table('plan_change_requests')->where('status', 'pending')->count())->toBe(1);
    expect(DB::table('audit_logs')->where('action', 'billing.plan_requested')->count())->toBe(2);
    unset($admin);
});

it('descarga el PDF de su factura y nunca el de otra cuenta', function () {
    $mine = billingInvoiceFor(Account::query()->find($this->ws->account_id));
    $otherWs = $this->workspaceFor($this->userWith2fa(), 'Otra tienda');
    $theirs = billingInvoiceFor(Account::query()->find($otherWs->account_id));

    $res = $this->actingAs($this->owner)->get($this->url.'/invoices/'.$mine->publicId().'/pdf')->assertOk();
    expect($res->headers->get('content-type'))->toBe('application/pdf');
    $this->get($this->url.'/invoices/'.$theirs->publicId().'/pdf')->assertNotFound();
    $this->get($this->url.'/invoices/inv_no_existe/pdf')->assertNotFound();
});

it('recalcular el uso bajo demanda devuelve los medidores', function () {
    DB::table('orders')->insert(['id' => strtoupper((string) Str::ulid()), 'workspace_id' => $this->ws->id, 'site_id' => 'S1', 'external_id' => '1', 'status' => 'processing', 'currency' => 'COP', 'total' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $res = $this->actingAs($this->owner)->postJson($this->url.'/usage/refresh')->assertOk();
    expect(collect($res->json('usage.meters'))->firstWhere('metric', 'orders')['value'])->toBe(1);
});

it('no muestra datos de cobro de otro espacio de trabajo', function () {
    $stranger = $this->userWith2fa();
    $this->workspaceFor($stranger, 'Ajena');
    $this->actingAs($stranger)->getJson($this->url)->assertNotFound();
});
