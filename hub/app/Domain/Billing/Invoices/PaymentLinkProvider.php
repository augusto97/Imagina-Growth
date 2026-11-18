<?php

declare(strict_types=1);

namespace App\Domain\Billing\Invoices;

use App\Domain\Billing\Models\BillingInvoice;
use App\Domain\Workspaces\Models\Account;

/**
 * Crea el enlace de pago de una factura (M21 v1). Por defecto `ManualPaymentLinkProvider`
 * (URL fija configurada o ninguno); una pasarela real (Wompi, Mercado Pago, Stripe)
 * implementa este contrato más adelante.
 */
interface PaymentLinkProvider
{
    public function name(): string;

    /** URL de pago o null si el cobro es manual (transferencia). */
    public function linkFor(BillingInvoice $invoice, Account $account): ?string;
}
