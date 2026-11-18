<?php

declare(strict_types=1);

namespace App\Domain\Billing\Invoices;

use App\Domain\Billing\Models\BillingInvoice;
use App\Domain\Workspaces\Models\Account;
use Spatie\LaravelPdf\Facades\Pdf;

/**
 * PDF de la factura (dompdf, sin Chromium), con la marca del emisor en el encabezado
 * (`Branding`). Se guarda en el disco local privado: `billing/invoices/<cuenta>/<número>.pdf`.
 */
final class InvoicePdf
{
    public function __construct(private readonly AccountBranding $branding) {}

    public function render(BillingInvoice $invoice, Account $account): string
    {
        $path = 'billing/invoices/'.$account->id.'/'.$invoice->number.'.pdf';
        Pdf::view('billing.invoice-pdf', [
            'invoice' => $invoice, 'account' => $account, 'brand' => $this->branding->for($account),
        ])->format('a4')->disk('local')->save($path);
        $invoice->forceFill(['pdf_path' => $path])->save();

        return $path;
    }
}
