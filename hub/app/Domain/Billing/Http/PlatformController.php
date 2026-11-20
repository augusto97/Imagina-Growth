<?php

declare(strict_types=1);

namespace App\Domain\Billing\Http;

use App\Domain\Billing\Invoices\InvoiceLifecycle;
use App\Domain\Billing\Invoices\IssueInvoice;
use App\Domain\Billing\Models\BillingInvoice;
use App\Domain\Billing\Models\Plan;
use App\Domain\Billing\Models\PlanChangeRequest;
use App\Domain\Billing\Plans\AssignPlan;
use App\Domain\Billing\Plans\PlanCatalog;
use App\Domain\Billing\Plans\PlanResolver;
use App\Domain\Billing\Platform\PlatformHealth;
use App\Domain\Billing\Usage\BillingStatus;
use App\Domain\Billing\Usage\UsageCalculator;
use App\Domain\Billing\Usage\UsageSummary;
use App\Domain\Workspaces\Actions\RecordAuditLog;
use App\Domain\Workspaces\Models\Account;
use App\Domain\Workspaces\Models\User;
use App\Domain\Workspaces\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Panel interno de la plataforma (hub/CLAUDE.md §16, solo superadmin): cuentas y
 * espacios con plan, uso y estado de cobro; salud técnica; asignación de plan;
 * módulos forzados por espacio; facturas y pedidos de cambio de plan.
 * Toda acción queda en audit_logs.
 */
final class PlatformController
{
    public function __construct(private readonly PlanResolver $plans, private readonly RecordAuditLog $audit) {}

    /** GET /api/platform/overview */
    public function overview(UsageSummary $usage): JsonResponse
    {
        $accounts = Account::query()->orderBy('name')->limit(300)->get();
        $workspaces = Workspace::query()->whereIn('account_id', $accounts->pluck('id'))->orderBy('name')->get()->groupBy('account_id');
        $billing = DB::table('accounts')->whereIn('id', $accounts->pluck('id'))->get(['id', 'billing_status', 'grace_ends_at'])->keyBy('id');
        $invoices = DB::table('billing_invoices')->whereIn('account_id', $accounts->pluck('id'))->whereIn('status', ['sent', 'overdue'])
            ->selectRaw("account_id, SUM(status = 'overdue') AS overdue, COUNT(*) AS open_count")->groupBy('account_id')->get()->keyBy('account_id');
        $overrides = DB::table('workspace_feature_overrides')->get()->groupBy('workspace_id');

        $rows = $accounts->map(function (Account $account) use ($workspaces, $billing, $invoices, $overrides, $usage) {
            $b = $billing->get($account->id);
            $inv = $invoices->get($account->id);

            return [
                'id' => $account->publicId(), 'name' => $account->name, 'type' => $account->type->value, 'plan_id' => $account->plan_id,
                // Sin plan asignado rige el plan por defecto (S3).
                'effective_plan_id' => $account->plan_id ?: PlanCatalog::defaultPlanId(),
                'billing_status' => (string) ($b->billing_status ?? BillingStatus::ACTIVE),
                'grace_ends_at' => isset($b->grace_ends_at) ? CarbonImmutable::parse((string) $b->grace_ends_at)->toIso8601String() : null,
                'open_invoices' => (int) ($inv->open_count ?? 0), 'overdue_invoices' => (int) ($inv->overdue ?? 0),
                'workspaces' => $workspaces->get($account->id, collect())->map(function (Workspace $ws) use ($usage, $overrides) {
                    $summary = $usage->for($ws);

                    return [
                        'id' => $ws->publicId(), 'name' => $ws->name,
                        'usage' => $summary['meters'][0], 'ai' => $summary['meters'][count($summary['meters']) - 1],
                        'overrides' => $overrides->get($ws->id, collect())->mapWithKeys(fn ($o) => [(string) $o->feature => (bool) $o->enabled])->all(),
                    ];
                })->values()->all(),
            ];
        })->all();

        $requests = PlanChangeRequest::query()->where('status', 'pending')->latest()->limit(50)->get();
        $invoiceRows = BillingInvoice::query()->orderByDesc('created_at')->limit(50)->get();
        $names = Account::query()->whereIn('id', $requests->pluck('account_id')->merge($invoiceRows->pluck('account_id'))->unique())->get()->keyBy('id');

        return response()->json([
            'accounts' => $rows,
            'plans' => Plan::query()->orderBy('metric')->orderBy('price_usd')->get()->map(fn (Plan $p) => $p->toPublicArray() + ['public' => $p->public])->all(),
            'modules' => PlanCatalog::modules(),
            'plan_requests' => $requests->map(fn (PlanChangeRequest $r) => [
                'id' => $r->publicId(), 'account_id' => $names->get($r->account_id)?->publicId(), 'account' => $names->get($r->account_id)?->name,
                'from_plan' => $r->from_plan, 'to_plan' => $r->to_plan, 'note' => $r->note, 'created_at' => $r->created_at?->toIso8601String(),
            ])->all(),
            'invoices' => $invoiceRows->map(fn (BillingInvoice $i) => $i->toPublicArray() + [
                'account_id' => $names->get($i->account_id)?->publicId(), 'account' => $names->get($i->account_id)?->name,
            ])->all(),
        ]);
    }

    /** GET /api/platform/health */
    public function health(PlatformHealth $health): JsonResponse
    {
        return response()->json($health->snapshot());
    }

    /** PUT /api/platform/accounts/{account}/plan */
    public function assignPlan(Request $request, string $account): JsonResponse
    {
        $model = $this->account($account);
        $data = $request->validate(['plan_id' => ['present', 'nullable', 'string', Rule::exists('plans', 'id')->whereNull('archived_at')]]);
        $this->applyPlan($model, $data['plan_id'], $request->user());

        return response()->json(['id' => $model->publicId(), 'plan_id' => $data['plan_id']]);
    }

    /** PUT /api/platform/accounts/{account}/billing-status — reactivar o suspender a mano. */
    public function setBillingStatus(Request $request, string $account): JsonResponse
    {
        $model = $this->account($account);
        $data = $request->validate([
            'status' => ['required', 'in:'.implode(',', [BillingStatus::TRIAL, BillingStatus::ACTIVE, BillingStatus::GRACE, BillingStatus::SUSPENDED])],
            'grace_days' => ['nullable', 'integer', 'min:1', 'max:60'],
        ]);
        $from = BillingStatus::of($model->id);
        $graceEnds = $data['status'] === BillingStatus::GRACE ? now()->addDays((int) ($data['grace_days'] ?? config('igr-plans.grace_days', 7))) : null;
        DB::table('accounts')->where('id', $model->id)->update(['billing_status' => $data['status'], 'grace_ends_at' => $graceEnds, 'updated_at' => now()]);
        $this->audit->handle('platform.billing_status', $model, ['from' => $from, 'to' => $data['status']], null, $model->id);

        return response()->json(['id' => $model->publicId(), 'billing_status' => $data['status']]);
    }

    /** PUT /api/platform/workspaces/{workspace}/features/{feature} — enabled: true | false | null (quitar). */
    public function setFeature(Request $request, string $workspace, string $feature): JsonResponse
    {
        $ws = Workspace::findByPublicId($workspace) ?? abort(404);
        abort_unless(in_array($feature, PlanCatalog::modules(), true), 404);
        $data = $request->validate(['enabled' => ['present', 'nullable', 'boolean'], 'reason' => ['nullable', 'string', 'max:255']]);
        /** @var User $user */
        $user = $request->user();
        if ($data['enabled'] === null) {
            DB::table('workspace_feature_overrides')->where('workspace_id', $ws->id)->where('feature', $feature)->delete();
        } else {
            DB::table('workspace_feature_overrides')->upsert([[
                'workspace_id' => $ws->id, 'feature' => $feature, 'enabled' => (bool) $data['enabled'], 'set_by' => $user->id,
                'reason' => $data['reason'] ?? null, 'created_at' => now(), 'updated_at' => now(),
            ]], ['workspace_id', 'feature'], ['enabled', 'set_by', 'reason', 'updated_at']);
        }
        $this->plans->refresh($ws);
        $this->audit->handle('platform.feature_override', $ws, ['feature' => $feature, 'enabled' => $data['enabled']], $ws->id, (string) $ws->account_id);

        return response()->json(['workspace_id' => $ws->publicId(), 'feature' => $feature, 'enabled' => $this->plans->moduleAllowed($ws, $feature), 'override' => $data['enabled']]);
    }

    /** GET /api/platform/accounts/{account}/invoice-draft — líneas sugeridas para facturar. */
    public function invoiceDraft(string $account): JsonResponse
    {
        $model = $this->account($account);
        $ws = Workspace::query()->where('account_id', $model->id)->orderBy('created_at')->first();

        return response()->json([
            'period' => $ws !== null ? UsageCalculator::currentPeriod($ws) : now()->format('Y-m'),
            'currency' => IssueInvoice::currencyFor($model),
            'lines' => IssueInvoice::suggestedLines($model),
        ]);
    }

    /** POST /api/platform/accounts/{account}/invoices */
    public function createInvoice(Request $request, string $account, IssueInvoice $issue, InvoiceLifecycle $lifecycle): JsonResponse
    {
        $model = $this->account($account);
        $data = $request->validate([
            'period' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'currency' => ['nullable', 'in:COP,USD,MXN'],
            'due_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:500'],
            'send' => ['boolean'],
            'lines' => ['required', 'array', 'min:1', 'max:30'],
            'lines.*.concept' => ['required', 'string', 'max:200'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:100000'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0', 'max:1000000000'],
        ]);
        /** @var User $user */
        $user = $request->user();
        try {
            $invoice = $issue->handle(
                $model, $data['period'], array_values($data['lines']), $data['currency'] ?? null,
                isset($data['due_at']) ? CarbonImmutable::parse($data['due_at'])->endOfDay() : null, $data['notes'] ?? null, $user,
            );
        } catch (InvalidArgumentException) {
            return response()->json(['message' => __('Revisa las líneas de la factura.')], 422);
        }
        if ($request->boolean('send')) {
            $lifecycle->send($invoice);
        }
        $this->audit->handle('platform.invoice_created', $invoice, ['number' => $invoice->number, 'total' => (string) $invoice->total], null, $model->id);

        return response()->json($invoice->refresh()->toPublicArray(), 201);
    }

    /** POST /api/platform/invoices/{invoice}/{action} — send | paid | void */
    public function invoiceAction(string $invoice, string $action, InvoiceLifecycle $lifecycle): JsonResponse
    {
        $model = BillingInvoice::findByPublicId($invoice) ?? abort(404);
        try {
            match ($action) {
                'send' => $lifecycle->send($model),
                'paid' => $lifecycle->markPaid($model),
                'void' => $lifecycle->void($model),
                default => abort(404),
            };
        } catch (InvalidArgumentException) {
            return response()->json(['message' => __('La factura no admite esa acción en su estado actual.')], 422);
        }
        $this->audit->handle('platform.invoice_'.$action, $model, ['number' => $model->number], null, $model->account_id);

        return response()->json($model->refresh()->toPublicArray());
    }

    /** GET /api/platform/invoices/{invoice}/pdf */
    public function invoicePdf(string $invoice): StreamedResponse
    {
        $model = BillingInvoice::findByPublicId($invoice) ?? abort(404);
        abort_if($model->pdf_path === null || ! Storage::disk('local')->exists($model->pdf_path), 404);

        return Storage::disk('local')->download($model->pdf_path, $model->number.'.pdf', ['Content-Type' => 'application/pdf']);
    }

    /** POST /api/platform/plan-requests/{request}/{action} — apply | reject */
    public function planRequest(Request $request, string $planRequest, string $action): JsonResponse
    {
        $model = PlanChangeRequest::findByPublicId($planRequest) ?? abort(404);
        abort_unless(in_array($action, ['apply', 'reject'], true), 404);
        if ($model->status !== 'pending') {
            return response()->json(['message' => __('Ese pedido ya se atendió.')], 422);
        }
        /** @var User $user */
        $user = $request->user();
        if ($action === 'apply') {
            $account = Account::query()->findOrFail($model->account_id);
            $this->applyPlan($account, $model->to_plan, $user);
        }
        $model->forceFill(['status' => $action === 'apply' ? 'applied' : 'rejected', 'handled_by' => $user->id, 'handled_at' => now()])->save();

        return response()->json(['id' => $model->publicId(), 'status' => $model->status]);
    }

    private function applyPlan(Account $account, ?string $planId, mixed $user): void
    {
        app(AssignPlan::class)->handle($account, $planId, $user instanceof User ? $user : null);
    }

    private function account(string $publicId): Account
    {
        return Account::findByPublicId($publicId) ?? abort(404);
    }
}
