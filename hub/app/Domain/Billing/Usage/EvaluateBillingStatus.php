<?php

declare(strict_types=1);

namespace App\Domain\Billing\Usage;

use App\Domain\Billing\Mail\BillingNoticeMail;
use App\Domain\Billing\Plans\PlanResolver;
use App\Domain\Workspaces\Contracts\Branding;
use App\Domain\Workspaces\Models\Account;
use App\Domain\Workspaces\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Transiciones del estado de cobro de una cuenta (BillingStatus). Motivos: algún
 * espacio de trabajo pasó el 100 % del volumen de su plan este mes, o hay una
 * factura vencida. Avisa por correo al entrar en gracia y al suspender.
 */
final class EvaluateBillingStatus
{
    public function __construct(private readonly PlanResolver $plans, private readonly Branding $branding) {}

    /** @return string el estado resultante */
    public function handle(Account $account, ?CarbonImmutable $now = null): string
    {
        $now ??= CarbonImmutable::now();
        $current = BillingStatus::of($account->id);
        $reasons = $this->reasons($account);
        $graceEnds = DB::table('accounts')->where('id', $account->id)->value('grace_ends_at');
        $next = $current;
        $update = [];
        if ($reasons !== []) {
            if (in_array($current, [BillingStatus::ACTIVE, BillingStatus::TRIAL], true)) {
                $next = BillingStatus::GRACE;
                $update = ['billing_status' => $next, 'grace_ends_at' => $now->addDays((int) config('igr-plans.grace_days', 7))];
            } elseif ($current === BillingStatus::GRACE && $graceEnds !== null && $now->greaterThanOrEqualTo(CarbonImmutable::parse((string) $graceEnds))) {
                $next = BillingStatus::SUSPENDED;
                $update = ['billing_status' => $next];
            }
        } elseif (in_array($current, [BillingStatus::GRACE, BillingStatus::SUSPENDED], true)) {
            $next = BillingStatus::ACTIVE;
            $update = ['billing_status' => $next, 'grace_ends_at' => null];
        }
        if ($update !== []) {
            DB::table('accounts')->where('id', $account->id)->update($update + ['updated_at' => now()]);
            if (in_array($next, [BillingStatus::GRACE, BillingStatus::SUSPENDED], true)) {
                $this->notify($account, $next, $reasons, $update['grace_ends_at'] ?? null);
            }
        }

        return $next;
    }

    /** @return list<string> overage:<espacio> | overdue */
    public function reasons(Account $account): array
    {
        $reasons = [];
        foreach (Workspace::query()->where('account_id', $account->id)->get() as $workspace) {
            $plan = $this->plans->planFor($workspace);
            if ($plan === null || $plan->included <= 0) {
                continue;
            }
            $value = (int) DB::table('usage_counters')->where('workspace_id', $workspace->id)
                ->where('period', UsageCalculator::currentPeriod($workspace))->value($plan->metric);
            if ($value >= $plan->included) {
                $reasons[] = 'overage:'.$workspace->id;
            }
        }
        if (DB::table('billing_invoices')->where('account_id', $account->id)->where('status', 'overdue')->exists()) {
            $reasons[] = 'overdue';
        }

        return $reasons;
    }

    /** @param list<string> $reasons */
    private function notify(Account $account, string $status, array $reasons, ?CarbonImmutable $graceEnds): void
    {
        $workspace = Workspace::query()->where('account_id', $account->id)->orderBy('created_at')->first();
        if ($workspace === null) {
            return;
        }
        $brand = $this->branding->for($workspace);
        foreach (BillingRecipients::for($account) as $to) {
            try {
                Mail::to($to)->send(new BillingNoticeMail($account->name, $status, in_array('overdue', $reasons, true), $graceEnds, $brand, UsageAlerts::url($workspace)));
            } catch (Throwable $e) {
                Log::warning('igr.billing.notice_failed', ['account' => $account->id, 'error' => class_basename($e)]);
            }
        }
    }
}
