<?php

declare(strict_types=1);

namespace App\Domain\Billing\Invoices;

use App\Domain\Billing\Models\BillingInvoice;
use App\Domain\Workspaces\Models\Account;

final class ManualPaymentLinkProvider implements PaymentLinkProvider
{
    public function name(): string
    {
        return 'manual';
    }

    public function linkFor(BillingInvoice $invoice, Account $account): ?string
    {
        $template = config('igr-plans.invoices.manual_payment_url');
        if (! is_string($template) || $template === '') {
            return null;
        }
        $url = strtr($template, ['{number}' => rawurlencode($invoice->number), '{total}' => rawurlencode((string) $invoice->total), '{currency}' => $invoice->currency]);

        return filter_var($url, FILTER_VALIDATE_URL) !== false && str_starts_with($url, 'https://') ? $url : null;
    }
}
