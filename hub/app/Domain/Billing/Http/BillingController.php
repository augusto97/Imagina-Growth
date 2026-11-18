<?php

declare(strict_types=1);

namespace App\Domain\Billing\Http;

use App\Domain\Billing\Mail\PlanChangeRequestedMail;
use App\Domain\Billing\Models\BillingInvoice;
use App\Domain\Billing\Models\Plan;
use App\Domain\Billing\Models\PlanChangeRequest;
use App\Domain\Billing\Plans\PlanCatalog;
use App\Domain\Billing\Plans\PlanResolver;
use App\Domain\Billing\Usage\BillingStatus;
use App\Domain\Billing\Usage\ComputeUsage;
use App\Domain\Billing\Usage\UsageSummary;
use App\Domain\Workspaces\Actions\RecordAuditLog;
use App\Domain\Workspaces\Models\Account;
use App\Domain\Workspaces\Models\User;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * «Plan y facturación» del espacio de trabajo (M21): plan, módulos, uso del mes con
 * avisos al 80/100 %, estado de cobro, facturas de la cuenta y pedido de cambio de
 * plan. El dueño y los administradores ven el plan y el uso; las facturas y el
 * cambio de plan son solo del dueño (`ws.manageBilling`).
 */
final class BillingController
{
    public function __construct(private readonly CurrentWorkspace $current, private readonly PlanResolver $plans) {}

    /** GET /api/w/{workspace}/billing */
    public function show(UsageSummary $usage): JsonResponse
    {
        Gate::authorize('ws.manageWorkspace');
        $workspace = $this->current->get();
        $account = Account::query()->findOrFail($workspace->account_id);
        $row = DB::table('accounts')->where('id', $account->id)->first(['billing_status', 'grace_ends_at', 'trial_ends_at']);
        $plan = $this->plans->planFor($workspace);
        $canBilling = Gate::allows('ws.manageBilling');

        $modules = array_map(fn (string $m) => [
            'key' => $m,
            'enabled' => $this->plans->moduleAllowed($workspace, $m),
            'in_plan' => $plan === null || in_array($m, (array) $plan->modules, true),
        ], PlanCatalog::modules());

        $pending = PlanChangeRequest::query()->where('account_id', $account->id)->where('status', 'pending')->latest()->first();

        return response()->json([
            'account' => [
                'name' => $account->name,
                'billing_status' => BillingStatus::of($account->id),
                'grace_ends_at' => $row?->grace_ends_at !== null ? CarbonImmutable::parse((string) $row->grace_ends_at)->toIso8601String() : null,
                'trial_ends_at' => $row?->trial_ends_at !== null ? CarbonImmutable::parse((string) $row->trial_ends_at)->toIso8601String() : null,
            ],
            'plan' => $plan?->toPublicArray(),
            'usage' => $usage->for($workspace),
            'modules' => $modules,
            'plans' => Plan::query()->where('public', true)->orderBy('metric')->orderBy('price_usd')->get()->map(fn (Plan $p) => $p->toPublicArray())->all(),
            'pending_request' => $pending !== null ? ['id' => $pending->publicId(), 'to_plan' => $pending->to_plan, 'created_at' => $pending->created_at?->toIso8601String()] : null,
            'invoices' => $canBilling
                ? BillingInvoice::query()->where('account_id', $account->id)->where('status', '!=', 'draft')->orderByDesc('created_at')->limit(24)->get()->map(fn (BillingInvoice $i) => $i->toPublicArray())->all()
                : null,
            'can_manage_billing' => $canBilling,
        ]);
    }

    /** POST /api/w/{workspace}/billing/usage/refresh — recalcula el uso ahora (máx. 1 por minuto). */
    public function refresh(ComputeUsage $compute, UsageSummary $usage): JsonResponse
    {
        Gate::authorize('ws.manageWorkspace');
        $workspace = $this->current->get();
        if (Cache::add('igr:billing:refresh:'.$workspace->id, 1, 60)) {
            $compute->handle($workspace);
        }

        return response()->json(['usage' => $usage->for($workspace)]);
    }

    /** POST /api/w/{workspace}/billing/plan-request — el dueño pide otro plan; avisa a la plataforma. */
    public function requestPlan(Request $request, RecordAuditLog $audit): JsonResponse
    {
        Gate::authorize('ws.manageBilling');
        $data = $request->validate([
            'plan_id' => ['required', 'string', 'max:40'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $plan = Plan::query()->where('public', true)->whereKey((string) $data['plan_id'])->first();
        if ($plan === null) {
            return response()->json(['message' => __('Ese plan no existe.')], 422);
        }
        $workspace = $this->current->get();
        $account = Account::query()->findOrFail($workspace->account_id);
        if ($account->plan_id === $plan->id) {
            return response()->json(['message' => __('Ya tienes ese plan.')], 422);
        }
        /** @var User $user */
        $user = $request->user();
        PlanChangeRequest::query()->where('account_id', $account->id)->where('status', 'pending')->update(['status' => 'rejected', 'handled_at' => now()]);
        $pcr = PlanChangeRequest::query()->create([
            'account_id' => $account->id, 'workspace_id' => $workspace->id, 'user_id' => $user->id,
            'from_plan' => $account->plan_id, 'to_plan' => $plan->id, 'note' => $data['note'] ?? null, 'status' => 'pending',
        ]);
        $audit->handle('billing.plan_requested', $pcr, ['from' => $account->plan_id, 'to' => $plan->id], $workspace->id, $account->id);

        $recipients = User::query()->where('is_platform_admin', true)->limit(20)->pluck('email')->map(fn ($e) => (string) $e)->all();
        $extra = config('igr-plans.invoices.platform_email');
        if (is_string($extra) && $extra !== '') {
            $recipients[] = $extra;
        }
        $url = rtrim((string) config('app.url'), '/').'/w/'.$workspace->publicId().'/plataforma';
        foreach (array_unique($recipients) as $to) {
            try {
                Mail::to($to)->send(new PlanChangeRequestedMail($account->name, $account->plan_id, $plan->name, $pcr->note, $url));
            } catch (Throwable $e) {
                Log::warning('igr.billing.plan_request_mail_failed', ['request' => $pcr->id, 'error' => class_basename($e)]);
            }
        }

        return response()->json(['id' => $pcr->publicId(), 'to_plan' => $plan->id, 'status' => 'pending'], 201);
    }

    /** GET /api/w/{workspace}/billing/invoices/{invoice}/pdf */
    public function pdf(string $invoice): StreamedResponse|JsonResponse
    {
        Gate::authorize('ws.manageBilling');
        $model = BillingInvoice::findByPublicId($invoice);
        // La factura es de la cuenta del espacio activo: otra cuenta responde 404.
        if ($model === null || $model->account_id !== $this->current->get()->account_id || $model->status === 'draft') {
            abort(404);
        }
        if ($model->pdf_path === null || ! Storage::disk('local')->exists($model->pdf_path)) {
            return response()->json(['message' => __('El PDF todavía no está listo.')], 404);
        }

        return Storage::disk('local')->download($model->pdf_path, $model->number.'.pdf', ['Content-Type' => 'application/pdf']);
    }
}
