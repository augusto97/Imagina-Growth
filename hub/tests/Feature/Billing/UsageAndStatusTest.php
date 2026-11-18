<?php

use App\Domain\Billing\Contracts\Entitlements;
use App\Domain\Billing\Mail\BillingNoticeMail;
use App\Domain\Billing\Mail\UsageAlertMail;
use App\Domain\Billing\Plans\PlanResolver;
use App\Domain\Billing\Usage\BillingStatus;
use App\Domain\Billing\Usage\ComputeUsage;
use App\Domain\Billing\Usage\EvaluateBillingStatus;
use App\Domain\Billing\Usage\UsageCalculator;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Workspaces\Models\Account;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->artisan('igr:plans:sync')->assertSuccessful();
    $this->owner = $this->userWith2fa(['email' => 'duena@pinturas.co']);
    $this->ws = $this->workspaceFor($this->owner);
    DB::table('accounts')->where('id', $this->ws->account_id)->update(['plan_id' => 'tienda_inicio', 'billing_email' => 'cobros@pinturas.co']);
    app(PlanResolver::class)->refresh($this->ws);
    Mail::fake();
});

function billingOrders($ws, int $n, ?CarbonImmutable $at = null): void
{
    $rows = [];
    for ($i = 0; $i < $n; $i++) {
        $rows[] = ['id' => strtoupper((string) Str::ulid()), 'workspace_id' => $ws->id, 'site_id' => 'S1', 'external_id' => (string) Str::uuid(), 'status' => 'processing', 'is_paid' => true, 'currency' => 'COP', 'total' => 50000, 'created_at' => $at ?? now(), 'updated_at' => now()];
    }
    foreach (array_chunk($rows, 200) as $chunk) {
        DB::table('orders')->insert($chunk);
    }
}

it('calcula el uso del mes: pedidos, contactos activos, mensajes por canal, tokens de IA', function () {
    billingOrders($this->ws, 5);
    billingOrders($this->ws, 3, CarbonImmutable::now()->subMonths(2)); // otro mes: no cuenta
    $other = $this->workspaceFor($this->userWith2fa(), 'Otra');
    billingOrders($other, 7); // otro espacio: no cuenta
    $msg = fn (string $channel, string $kind) => ['id' => strtoupper((string) Str::ulid()), 'workspace_id' => $this->ws->id, 'channel' => $channel, 'direction' => 'out', 'kind' => $kind, 'source_type' => 'flow', 'status' => 'delivered', 'sent_at' => now(), 'created_at' => now(), 'updated_at' => now()];
    DB::table('messages')->insert([$msg('email', 'marketing'), $msg('email', 'transactional'), $msg('whatsapp', 'marketing'), $msg('whatsapp', 'utility'), $msg('whatsapp', 'service'), $msg('sms', 'marketing')]);
    DB::table('ai_usage')->insert(['workspace_id' => $this->ws->id, 'date' => now('America/Bogota')->toDateString(), 'feature' => 'analyst', 'model' => 'm', 'requests' => 1, 'tokens_in' => 1000, 'tokens_out' => 500, 'cost_usd' => 0.42]);

    app(CurrentWorkspace::class)->run($this->ws, function () {
        Contact::query()->create(['workspace_id' => $this->ws->id, 'first_name' => 'Activa', 'last_seen_at' => now()]);
        Contact::query()->create(['workspace_id' => $this->ws->id, 'first_name' => 'Dormida', 'last_seen_at' => now()->subMonths(3)]);
    });

    $values = app(ComputeUsage::class)->handle($this->ws);

    expect($values['orders'])->toBe(5)
        ->and($values['contacts'])->toBe(1)
        ->and($values['emails'])->toBe(2)
        ->and($values['wa_marketing'])->toBe(1)
        ->and($values['wa_utility'])->toBe(1)
        ->and($values['wa_service'])->toBe(1)
        ->and($values['sms'])->toBe(1)
        ->and($values['ai_tokens'])->toBe(1500);
    $row = DB::table('usage_counters')->where('workspace_id', $this->ws->id)->where('period', UsageCalculator::currentPeriod($this->ws))->first();
    expect((int) $row->orders)->toBe(5)->and($row->computed_at)->not->toBeNull();
});

it('avisa al 80 % y al 100 % una sola vez por umbral y periodo', function () {
    billingOrders($this->ws, 240); // 80 % de 300
    app(ComputeUsage::class)->handle($this->ws);
    app(ComputeUsage::class)->handle($this->ws);
    Mail::assertSent(UsageAlertMail::class, fn (UsageAlertMail $m) => $m->threshold === 80 && $m->metric === 'orders');
    Mail::assertSent(UsageAlertMail::class, 2); // dueña + correo de cobro, una vez

    billingOrders($this->ws, 60); // 100 %
    app(ComputeUsage::class)->handle($this->ws);
    app(ComputeUsage::class)->handle($this->ws);
    Mail::assertSent(UsageAlertMail::class, 4);
    Mail::assertSent(UsageAlertMail::class, fn (UsageAlertMail $m) => $m->threshold === 100 && $m->hasTo('cobros@pinturas.co'));
    $sent = json_decode((string) DB::table('usage_counters')->where('workspace_id', $this->ws->id)->value('alerts_sent'), true);
    expect(array_keys($sent))->toEqualCanonicalizing(['orders:80', 'orders:100']);
});

it('pasado el 100 % entra en gracia, suspende al terminar la gracia y vuelve a activa con el plan correcto', function () {
    billingOrders($this->ws, 300);
    app(ComputeUsage::class)->handle($this->ws);
    $account = Account::query()->find($this->ws->account_id);
    expect(BillingStatus::of($account->id))->toBe(BillingStatus::GRACE);
    $graceEnds = CarbonImmutable::parse((string) DB::table('accounts')->where('id', $account->id)->value('grace_ends_at'));
    expect((int) round(now()->diffInDays($graceEnds)))->toBe(7);
    Mail::assertSent(BillingNoticeMail::class, fn (BillingNoticeMail $m) => $m->status === 'grace' && ! $m->overdue);

    // Durante la gracia todo sigue.
    expect(app(Entitlements::class)->allows($this->ws, 'marketing_sends'))->toBeTrue();

    app(EvaluateBillingStatus::class)->handle($account, CarbonImmutable::now()->addDays(8));
    expect(BillingStatus::of($account->id))->toBe(BillingStatus::SUSPENDED)
        ->and(app(Entitlements::class)->allows($this->ws, 'marketing_sends'))->toBeFalse()
        ->and(app(Entitlements::class)->allows($this->ws, 'imports'))->toBeFalse();
    Mail::assertSent(BillingNoticeMail::class, fn (BillingNoticeMail $m) => $m->status === 'suspended');

    // Sube de plan: el uso ya cabe y la cuenta vuelve a activa.
    DB::table('accounts')->where('id', $account->id)->update(['plan_id' => 'tienda_crece']);
    app(PlanResolver::class)->refresh($this->ws);
    app(EvaluateBillingStatus::class)->handle($account);
    expect(BillingStatus::of($account->id))->toBe(BillingStatus::ACTIVE)
        ->and(DB::table('accounts')->where('id', $account->id)->value('grace_ends_at'))->toBeNull();
});

it('una factura vencida también abre la gracia', function () {
    DB::table('billing_invoices')->insert(['id' => strtoupper((string) Str::ulid()), 'account_id' => $this->ws->account_id, 'number' => 'IGR-2026-9999', 'period' => '2026-10-01', 'lines' => '[]', 'total' => 10, 'currency' => 'USD', 'status' => 'overdue', 'created_at' => now(), 'updated_at' => now()]);
    $account = Account::query()->find($this->ws->account_id);
    expect(app(EvaluateBillingStatus::class)->reasons($account))->toBe(['overdue'])
        ->and(app(EvaluateBillingStatus::class)->handle($account))->toBe(BillingStatus::GRACE);
    Mail::assertSent(BillingNoticeMail::class, fn (BillingNoticeMail $m) => $m->overdue);
});

it('el comando recorre los espacios y acepta uno solo', function () {
    billingOrders($this->ws, 2);
    $this->artisan('igr:billing:usage', ['--workspace' => $this->ws->publicId()])->assertSuccessful();
    expect((int) DB::table('usage_counters')->where('workspace_id', $this->ws->id)->value('orders'))->toBe(2);
    $this->artisan('igr:billing:usage', ['--workspace' => 'ws_no_existe'])->assertFailed();
    $this->artisan('igr:billing:usage')->assertSuccessful();
});

it('los correos de aviso salen bien renderizados y con la marca', function () {
    billingOrders($this->ws, 300);
    app(ComputeUsage::class)->handle($this->ws);
    Mail::assertSent(UsageAlertMail::class, function (UsageAlertMail $m) {
        $html = $m->render();

        return str_contains($html, '/facturacion') && str_contains($html, 'pedidos');
    });
});
