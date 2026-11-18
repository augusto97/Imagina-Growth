<?php

use App\Domain\Billing\Invoices\FakePaymentLinkProvider;
use App\Domain\Billing\Invoices\InvoiceLifecycle;
use App\Domain\Billing\Invoices\IssueInvoice;
use App\Domain\Billing\Invoices\PaymentLinkProvider;
use App\Domain\Billing\Mail\InvoiceMail;
use App\Domain\Billing\Models\BillingInvoice;
use App\Domain\Billing\Usage\BillingStatus;
use App\Domain\Workspaces\Models\Account;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    Mail::fake();
    config(['igr-plans.invoices.payment_link_provider' => 'fake']);
    $this->artisan('igr:plans:sync')->assertSuccessful();
    $this->owner = $this->userWith2fa(['email' => 'duena@pinturas.co']);
    $this->ws = $this->workspaceFor($this->owner);
    DB::table('accounts')->where('id', $this->ws->account_id)->update(['plan_id' => 'tienda_crece', 'billing_email' => 'cobros@pinturas.co', 'country' => 'CO', 'legal_name' => 'Pinturas La 14 S.A.S.']);
    $this->account = Account::query()->find($this->ws->account_id);
});

function billingIssue(Account $account, array $lines = [['concept' => 'Plan Tienda · Crece', 'quantity' => 1, 'unit_price' => 599000]]): BillingInvoice
{
    return app(IssueInvoice::class)->handle($account, '2026-11', $lines);
}

it('numera las facturas en orden por año y calcula el total en el servidor', function () {
    $a = billingIssue($this->account, [['concept' => 'Plan', 'quantity' => 2, 'unit_price' => '599000'], ['concept' => 'Paquete 500 pedidos', 'quantity' => 1, 'unit_price' => 99000]]);
    $b = billingIssue($this->account);
    $year = now()->format('Y');
    expect($a->number)->toBe("IGR-{$year}-0001")
        ->and($b->number)->toBe("IGR-{$year}-0002")
        ->and((string) $a->total)->toBe('1297000.00')
        ->and($a->currency)->toBe('COP')
        ->and($a->status)->toBe('draft')
        ->and($a->lines[0]['total'])->toBe('1198000.00')
        ->and($a->due_at)->not->toBeNull();
});

it('genera el PDF (dompdf) con el enlace de pago del proveedor', function () {
    $invoice = billingIssue($this->account);
    expect(app(PaymentLinkProvider::class))->toBeInstanceOf(FakePaymentLinkProvider::class)
        ->and($invoice->payment_url)->toBe('https://pagos.example.test/'.$invoice->number)
        ->and($invoice->pdf_path)->toBe('billing/invoices/'.$this->account->id.'/'.$invoice->number.'.pdf');
    $pdf = Storage::disk('local')->get($invoice->pdf_path);
    expect(substr((string) $pdf, 0, 4))->toBe('%PDF');
});

it('el proveedor manual no inventa enlaces: solo usa la URL configurada en https', function () {
    config(['igr-plans.invoices.payment_link_provider' => 'manual', 'igr-plans.invoices.manual_payment_url' => null]);
    app()->forgetInstance(PaymentLinkProvider::class);
    expect(billingIssue($this->account)->payment_url)->toBeNull();
    config(['igr-plans.invoices.manual_payment_url' => 'https://pagos.imagina.cloud/?ref={number}']);
    $invoice = billingIssue($this->account);
    expect($invoice->payment_url)->toBe('https://pagos.imagina.cloud/?ref='.$invoice->number);
});

it('rechaza líneas vacías o sin valor', function () {
    expect(fn () => billingIssue($this->account, []))->toThrow(InvalidArgumentException::class)
        ->and(fn () => billingIssue($this->account, [['concept' => ' ', 'quantity' => 1, 'unit_price' => 10]]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => billingIssue($this->account, [['concept' => 'X', 'quantity' => 0, 'unit_price' => 10]]))->toThrow(InvalidArgumentException::class);
});

it('enviar la factura la marca como enviada y la manda con el PDF adjunto', function () {
    $invoice = billingIssue($this->account);
    $sent = app(InvoiceLifecycle::class)->send($invoice);
    expect($sent)->toBe(2)->and($invoice->refresh()->status)->toBe('sent')->and($invoice->sent_at)->not->toBeNull();
    Mail::assertSent(InvoiceMail::class, fn (InvoiceMail $m) => $m->hasTo('cobros@pinturas.co') && count($m->attachments()) === 1);
    Mail::assertSent(InvoiceMail::class, fn (InvoiceMail $m) => str_contains($m->render(), 'Pagar en línea'));
});

it('vence las enviadas sin pagar, abre la gracia y pagar la cierra', function () {
    $invoice = billingIssue($this->account);
    app(InvoiceLifecycle::class)->send($invoice);
    expect(app(InvoiceLifecycle::class)->markOverdue(CarbonImmutable::now()->addDays(10)))->toBe(0);

    $this->travel(16)->days();
    $this->artisan('igr:billing:status')->assertSuccessful();
    expect($invoice->refresh()->status)->toBe('overdue')
        ->and(BillingStatus::of($this->account->id))->toBe(BillingStatus::GRACE);

    app(InvoiceLifecycle::class)->markPaid($invoice);
    expect($invoice->refresh()->status)->toBe('paid')->and($invoice->paid_at)->not->toBeNull()
        ->and(BillingStatus::of($this->account->id))->toBe(BillingStatus::ACTIVE);
});

it('una pagada no se anula ni se vuelve a pagar', function () {
    $invoice = billingIssue($this->account);
    app(InvoiceLifecycle::class)->markPaid($invoice);
    expect(fn () => app(InvoiceLifecycle::class)->void($invoice))->toThrow(InvalidArgumentException::class)
        ->and(fn () => app(InvoiceLifecycle::class)->markPaid($invoice))->toThrow(InvalidArgumentException::class);
    $other = billingIssue($this->account);
    app(InvoiceLifecycle::class)->void($other);
    expect($other->refresh()->status)->toBe('void');
});

it('sugiere el precio del plan en la moneda de la cuenta por cada espacio', function () {
    expect(IssueInvoice::suggestedLines($this->account))->toBe([['concept' => 'Plan Tienda · Crece', 'quantity' => 1, 'unit_price' => '599000.00']]);
    DB::table('accounts')->where('id', $this->account->id)->update(['country' => 'MX']);
    expect(IssueInvoice::suggestedLines($this->account->refresh())[0]['unit_price'])->toBe('149.00');
});
