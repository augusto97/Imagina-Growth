<?php

declare(strict_types=1);

namespace App\Domain\Agency\Http;

use App\Domain\Agency\Http\Concerns\ResolvesAgency;
use App\Domain\Agency\Models\MonthlyReport;
use App\Domain\Agency\Reports\MonthlyReportFacts;
use App\Domain\Agency\Reports\MonthlyReports;
use App\Domain\Agency\Support\AgencyAccess;
use App\Domain\Workspaces\Models\User;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Ids\PrefixedId;
use App\Support\Pii\Pii;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Reportes mensuales con marca (M22). Dos vistas:
 * - la del cliente: `reports…` del espacio de trabajo actual (ver y descargar con
 *   `viewDashboards`; generar y reenviar con `manageWorkspace` o siendo de la agencia);
 * - la de la agencia: `agency/reports…`, todos sus clientes de un mes.
 */
final class MonthlyReportController
{
    use ResolvesAgency;

    public function __construct(private readonly CurrentWorkspace $current, private readonly MonthlyReports $reports) {}

    /** GET reports */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('ws.viewDashboards');
        $ws = $this->current->get();
        $list = MonthlyReport::query()->orderByDesc('period')->limit(36)->get();

        return response()->json([
            'reports' => $list->map(fn (MonthlyReport $r) => self::present($r, $ws))->values(),
            'can_manage' => $this->canManage($request->user(), $ws),
            'last_month' => MonthlyReports::lastMonth($ws)->format('Y-m'),
            // A quién le llegan los informes (los dueños que no los apagaron en sus avisos), enmascarados.
            'recipients' => [
                'monthly' => self::maskedRecipients(MonthlyReports::recipients($ws)),
                'weekly' => self::maskedRecipients(MonthlyReports::recipients($ws, 'weekly_report')),
            ],
        ]);
    }

    /**
     * GET reports/preview?month=AAAA-MM — los hechos del informe de un mes (por defecto el anterior) sin guardarlo ni
     * escribir el resumen: la vista previa de lo que va a llegar.
     */
    public function preview(Request $request, MonthlyReportFacts $facts): JsonResponse
    {
        Gate::authorize('ws.viewDashboards');
        $ws = $this->current->get();
        $month = $request->validate(['month' => ['nullable', 'date_format:Y-m']])['month'] ?? null;
        $period = $month !== null ? $this->month($ws, $month) : MonthlyReports::lastMonth($ws);

        return response()->json(['period' => $period->format('Y-m'), 'facts' => $facts->build($ws, $period)]);
    }

    /**
     * @param  Collection<int, User>  $users
     * @return array{count: int, masked: list<string>}
     */
    private static function maskedRecipients(Collection $users): array
    {
        return ['count' => $users->count(), 'masked' => array_values($users->map(fn (User $u) => Pii::maskEmail((string) $u->email))->all())];
    }

    /** GET reports/{report} */
    public function show(string $report): JsonResponse
    {
        Gate::authorize('ws.viewDashboards');
        $model = MonthlyReport::findByPublicId($report) ?? abort(404);

        return response()->json(['report' => self::present($model, $this->current->get(), true)]);
    }

    /** GET reports/{report}/pdf */
    public function pdf(string $report): StreamedResponse
    {
        Gate::authorize('ws.viewDashboards');
        $model = MonthlyReport::findByPublicId($report) ?? abort(404);

        return $this->download($model);
    }

    /** POST reports/generate {month: AAAA-MM, send?: bool} */
    public function generate(Request $request): JsonResponse
    {
        $ws = $this->current->get();
        abort_unless($this->canManage($request->user(), $ws), 403);
        $data = $request->validate(['month' => ['required', 'date_format:Y-m'], 'send' => ['sometimes', 'boolean']]);
        $report = $this->reports->generate($ws, $this->month($ws, $data['month']), force: true);
        $sent = ($data['send'] ?? false) ? $this->reports->send($ws, $report, force: true, userId: $this->userId($request)) : null;

        return response()->json(['report' => self::present($report->refresh(), $ws, true), 'sent' => $sent], 201);
    }

    /** POST reports/{report}/send — reenvía a los dueños. */
    public function send(Request $request, string $report): JsonResponse
    {
        $ws = $this->current->get();
        abort_unless($this->canManage($request->user(), $ws), 403);
        $model = MonthlyReport::findByPublicId($report) ?? abort(404);
        $result = $this->reports->send($ws, $model, force: true, userId: $this->userId($request));

        return response()->json(['report' => self::present($model->refresh(), $ws), 'sent' => $result]);
    }

    /** GET agency/reports?month=AAAA-MM — el reporte de cada cliente en ese mes. */
    public function agencyIndex(Request $request): JsonResponse
    {
        $user = $this->agencyUser($request);
        $month = $request->validate(['month' => ['nullable', 'date_format:Y-m']])['month'] ?? null;
        $clients = Workspace::query()->whereIn('account_id', $this->agencyAccountIds($user))->orderBy('name')->get();
        $rows = [];
        foreach ($clients as $ws) {
            $period = $month !== null ? $this->month($ws, $month) : MonthlyReports::lastMonth($ws);
            $report = MonthlyReport::withoutWorkspaceScope()->where('workspace_id', $ws->id)->whereDate('period', $period->toDateString())->first();
            $rows[] = [
                'workspace_id' => $ws->publicId(),
                'workspace' => $ws->name,
                'period' => $period->format('Y-m'),
                'owners' => MonthlyReports::recipients($ws)->count(),
                'report' => $report !== null ? self::present($report, $ws) : null,
            ];
        }

        return response()->json(['month' => $month ?? CarbonImmutable::now()->subMonthNoOverflow()->format('Y-m'), 'clients' => $rows]);
    }

    /** POST agency/reports/{client}/generate {month, send?} */
    public function agencyGenerate(Request $request, string $client): JsonResponse
    {
        $user = $this->agencyUser($request);
        $ws = $this->clientWorkspace($user, $client);
        $data = $request->validate(['month' => ['required', 'date_format:Y-m'], 'send' => ['sometimes', 'boolean']]);
        $report = $this->reports->generate($ws, $this->month($ws, $data['month']), force: true);
        $sent = ($data['send'] ?? false) ? $this->reports->send($ws, $report, force: true, userId: $user->id) : null;

        return response()->json(['report' => self::present($report->refresh(), $ws, true), 'sent' => $sent], 201);
    }

    /** POST agency/reports/{client}/{report}/send */
    public function agencySend(Request $request, string $client, string $report): JsonResponse
    {
        $user = $this->agencyUser($request);
        $ws = $this->clientWorkspace($user, $client);
        $model = $this->agencyReport($ws, $report);

        return response()->json(['report' => self::present($model, $ws), 'sent' => $this->reports->send($ws, $model, force: true, userId: $user->id)]);
    }

    /** GET agency/reports/{client}/{report}/pdf */
    public function agencyPdf(Request $request, string $client, string $report): StreamedResponse
    {
        $user = $this->agencyUser($request);

        return $this->download($this->agencyReport($this->clientWorkspace($user, $client), $report));
    }

    private function agencyReport(Workspace $ws, string $publicId): MonthlyReport
    {
        $id = PrefixedId::decode(MonthlyReport::ID_PREFIX, $publicId);

        return ($id === null ? null : MonthlyReport::withoutWorkspaceScope()->where('workspace_id', $ws->id)->find($id)) ?? abort(404);
    }

    private function download(MonthlyReport $report): StreamedResponse
    {
        if (! is_string($report->pdf_path) || ! Storage::disk('local')->exists($report->pdf_path)) {
            abort(404);
        }

        return Storage::disk('local')->download($report->pdf_path, 'reporte-'.$report->period->format('Y-m').'.pdf', ['Content-Type' => 'application/pdf']);
    }

    private function canManage(mixed $user, Workspace $ws): bool
    {
        return Gate::allows('ws.manageWorkspace') || ($user instanceof User && AgencyAccess::canView($user, $ws));
    }

    private function userId(Request $request): ?string
    {
        $user = $request->user();

        return $user instanceof User ? $user->id : null;
    }

    private function month(Workspace $ws, string $month): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $month.'-01', (string) ($ws->timezone ?: 'America/Bogota')) ?: abort(422);
    }

    /** @return array<string, mixed> */
    public static function present(MonthlyReport $r, Workspace $ws, bool $detail = false): array
    {
        $facts = (array) $r->facts;
        $data = [
            'id' => $r->publicId(),
            'period' => $r->period->format('Y-m'),
            'month' => (string) ($facts['mes'] ?? ''),
            'currency' => (string) ($facts['moneda'] ?? $ws->currency),
            'generated' => (float) ($facts['dinero_generado']['total'] ?? 0),
            'change_pct' => $facts['dinero_generado']['cambio_vs_anterior_pct'] ?? null,
            'summary_source' => $r->summary_source,
            'has_pdf' => is_string($r->pdf_path),
            'sent_at' => $r->sent_at?->toIso8601String(),
            'recipients' => ['count' => (int) ($r->recipients['count'] ?? 0), 'masked' => array_values((array) ($r->recipients['masked'] ?? []))],
            'created_at' => $r->created_at?->toIso8601String(),
        ];
        if ($detail) {
            $data['summary'] = $r->summary;
            $data['facts'] = array_diff_key($facts, ['validacion' => true]);
        }

        return $data;
    }
}
