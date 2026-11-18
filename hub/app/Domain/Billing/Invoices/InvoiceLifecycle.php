<?php

declare(strict_types=1);

namespace App\Domain\Billing\Invoices;

use App\Domain\Billing\Mail\InvoiceMail;
use App\Domain\Billing\Models\BillingInvoice;
use App\Domain\Billing\Usage\BillingRecipients;
use App\Domain\Billing\Usage\EvaluateBillingStatus;
use App\Domain\Workspaces\Models\Account;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Throwable;

/**
 * Estados de la factura: draft → sent → paid | overdue → paid; draft|sent|overdue → void.
 * Pagar o anular una vencida reevalúa el estado de cobro de la cuenta (puede volver a activa).
 */
final class InvoiceLifecycle
{
    public function __construct(
        private readonly InvoicePdf $pdf, private readonly AccountBranding $branding, private readonly EvaluateBillingStatus $status,
    ) {}

    /** @return int correos enviados */
    public function send(BillingInvoice $invoice): int
    {
        if (! in_array($invoice->status, ['draft', 'sent', 'overdue'], true)) {
            throw new InvalidArgumentException('status');
        }
        $account = $this->account($invoice);
        if ($invoice->pdf_path === null || ! Storage::disk('local')->exists($invoice->pdf_path)) {
            $this->pdf->render($invoice, $account);
        }
        $brand = $this->branding->for($account);
        $sent = 0;
        foreach (BillingRecipients::for($account) as $to) {
            try {
                Mail::to($to)->send(new InvoiceMail($invoice, $account->name, $brand, AccountBranding::panelUrl($account)));
                $sent++;
            } catch (Throwable $e) {
                Log::warning('igr.billing.invoice_mail_failed', ['invoice' => $invoice->id, 'error' => class_basename($e)]);
            }
        }
        $invoice->forceFill(['status' => $invoice->status === 'draft' ? 'sent' : $invoice->status, 'sent_at' => now()])->save();

        return $sent;
    }

    public function markPaid(BillingInvoice $invoice, ?CarbonImmutable $paidAt = null): void
    {
        if (! in_array($invoice->status, ['draft', 'sent', 'overdue'], true)) {
            throw new InvalidArgumentException('status');
        }
        $invoice->forceFill(['status' => 'paid', 'paid_at' => $paidAt ?? now()])->save();
        $this->status->handle($this->account($invoice));
    }

    public function void(BillingInvoice $invoice): void
    {
        if ($invoice->status === 'paid') {
            throw new InvalidArgumentException('status');
        }
        $invoice->forceFill(['status' => 'void'])->save();
        $this->status->handle($this->account($invoice));
    }

    /**
     * Pasa a vencidas las enviadas cuyo plazo terminó y reevalúa esas cuentas.
     *
     * @return int facturas vencidas
     */
    public function markOverdue(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $invoices = BillingInvoice::query()->where('status', 'sent')->whereNotNull('due_at')->where('due_at', '<', $now)->get();
        foreach ($invoices as $invoice) {
            $invoice->forceFill(['status' => 'overdue'])->save();
        }
        foreach ($invoices->pluck('account_id')->unique() as $accountId) {
            $account = Account::query()->whereKey((string) $accountId)->first();
            if ($account !== null) {
                $this->status->handle($account, $now);
            }
        }

        return $invoices->count();
    }

    private function account(BillingInvoice $invoice): Account
    {
        return Account::query()->findOrFail($invoice->account_id);
    }
}
