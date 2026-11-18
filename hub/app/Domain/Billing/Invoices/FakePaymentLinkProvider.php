<?php

declare(strict_types=1);

namespace App\Domain\Billing\Invoices;

use App\Domain\Billing\Models\BillingInvoice;
use App\Domain\Workspaces\Models\Account;

/** Para pruebas y desarrollo: un enlace determinista por factura. */
final class FakePaymentLinkProvider implements PaymentLinkProvider
{
    /** @var list<string> */
    public array $created = [];

    public function name(): string
    {
        return 'fake';
    }

    public function linkFor(BillingInvoice $invoice, Account $account): string
    {
        $this->created[] = $invoice->number;

        return 'https://pagos.example.test/'.rawurlencode($invoice->number);
    }
}
