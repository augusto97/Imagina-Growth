<?php

declare(strict_types=1);

namespace App\Domain\Billing\Console;

use App\Domain\Billing\Invoices\InvoiceLifecycle;
use App\Domain\Billing\Usage\BillingStatus;
use App\Domain\Billing\Usage\EvaluateBillingStatus;
use App\Domain\Workspaces\Models\Account;
use Illuminate\Console\Command;

/** Cada hora: facturas vencidas y fin de los periodos de gracia (gracia → suspendida). */
final class BillingStatusCommand extends Command
{
    protected $signature = 'igr:billing:status';

    protected $description = 'Marca las facturas vencidas y revisa el estado de cobro de las cuentas';

    public function handle(InvoiceLifecycle $invoices, EvaluateBillingStatus $status): int
    {
        $overdue = $invoices->markOverdue();
        $accounts = Account::query()->whereIn('billing_status', [BillingStatus::GRACE, BillingStatus::SUSPENDED])->get();
        foreach ($accounts as $account) {
            $status->handle($account);
        }
        $this->info(__(':overdue facturas vencidas; :accounts cuentas revisadas.', ['overdue' => $overdue, 'accounts' => $accounts->count()]));

        return self::SUCCESS;
    }
}
