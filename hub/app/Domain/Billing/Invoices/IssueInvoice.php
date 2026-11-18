<?php

declare(strict_types=1);

namespace App\Domain\Billing\Invoices;

use App\Domain\Billing\Models\BillingInvoice;
use App\Domain\Billing\Models\Plan;
use App\Domain\Workspaces\Models\Account;
use App\Domain\Workspaces\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Emite una factura manual (M21 v1): numeración consecutiva por año
 * (`IGR-2026-0001`), totales calculados en el servidor, PDF y enlace de pago.
 * Queda en borrador hasta que se envía (`SendInvoice`).
 */
final class IssueInvoice
{
    public function __construct(private readonly InvoicePdf $pdf, private readonly PaymentLinkProvider $payments) {}

    /**
     * @param  list<array{concept: string, quantity: int|float, unit_price: int|float|string}>  $lines
     */
    public function handle(Account $account, string $period, array $lines, ?string $currency = null, ?CarbonImmutable $dueAt = null, ?string $notes = null, ?User $issuer = null): BillingInvoice
    {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period) !== 1) {
            throw new InvalidArgumentException('period');
        }
        $normalized = [];
        $total = 0;
        foreach ($lines as $line) {
            $quantity = round((float) $line['quantity'], 2);
            $unit = round((float) $line['unit_price'], 2);
            if (trim($line['concept']) === '' || $quantity <= 0 || $unit < 0) {
                throw new InvalidArgumentException('lines');
            }
            $lineTotal = round($quantity * $unit, 2);
            $total += $lineTotal;
            $normalized[] = ['concept' => mb_substr(trim($line['concept']), 0, 200), 'quantity' => $quantity, 'unit_price' => number_format($unit, 2, '.', ''), 'total' => number_format($lineTotal, 2, '.', '')];
        }
        if ($normalized === []) {
            throw new InvalidArgumentException('lines');
        }
        $currency = strtoupper($currency ?? self::currencyFor($account));
        $dueAt ??= CarbonImmutable::now()->addDays((int) config('igr-plans.invoices.due_days', 15))->endOfDay();

        $invoice = $this->create($account, [
            'period' => $period.'-01', 'lines' => $normalized, 'total' => number_format($total, 2, '.', ''), 'currency' => $currency,
            'status' => 'draft', 'due_at' => $dueAt, 'notes' => $notes !== null ? mb_substr($notes, 0, 500) : null, 'issued_by' => $issuer?->id,
        ]);
        $link = $this->payments->linkFor($invoice, $account);
        if ($link !== null) {
            $invoice->forceFill(['payment_url' => $link])->save();
        }
        $this->pdf->render($invoice, $account);

        return $invoice->refresh();
    }

    /**
     * Líneas sugeridas para el periodo: el precio del plan de la cuenta por cada
     * espacio de trabajo activo. El superadmin las edita antes de emitir.
     *
     * @return list<array{concept: string, quantity: int, unit_price: string}>
     */
    public static function suggestedLines(Account $account): array
    {
        $plan = $account->plan_id !== null ? Plan::query()->find($account->plan_id) : null;
        if ($plan === null) {
            return [];
        }
        $workspaces = max(1, DB::table('workspaces')->where('account_id', $account->id)->count());
        $price = self::currencyFor($account) === 'COP' && $plan->price_cop !== null ? (string) $plan->price_cop : (string) $plan->price_usd;

        return [['concept' => __('Plan :plan', ['plan' => $plan->name]), 'quantity' => $workspaces, 'unit_price' => $price]];
    }

    public static function currencyFor(Account $account): string
    {
        return ((string) DB::table('accounts')->where('id', $account->id)->value('country')) === 'CO' ? 'COP' : 'USD';
    }

    /** @param array<string, mixed> $attributes */
    private function create(Account $account, array $attributes): BillingInvoice
    {
        for ($attempt = 0; ; $attempt++) {
            try {
                return DB::transaction(function () use ($account, $attributes) {
                    $prefix = (string) config('igr-plans.invoices.number_prefix', 'IGR').'-'.now()->format('Y').'-';
                    $last = DB::table('billing_invoices')->where('number', 'like', $prefix.'%')->lockForUpdate()->orderByDesc('number')->value('number');
                    $next = $last !== null ? ((int) substr((string) $last, strlen($prefix))) + 1 : 1;

                    return BillingInvoice::query()->create($attributes + ['account_id' => $account->id, 'number' => $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT)]);
                });
            } catch (QueryException $e) {
                // Choque con otra emisión al mismo tiempo (número único): se reintenta.
                if ($attempt >= 3 || ! str_contains($e->getMessage(), 'Duplicate')) {
                    throw $e;
                }
            }
        }
    }
}
