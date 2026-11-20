<?php

declare(strict_types=1);

namespace App\Domain\Agency\Reports;

use App\Domain\Agency\Mail\MonthlyReportMail;
use App\Domain\Agency\Models\MonthlyReport;
use App\Domain\Agency\Queries\MoneyGenerated;
use App\Domain\Workspaces\Actions\RecordAuditLog;
use App\Domain\Workspaces\Enums\WorkspaceRole;
use App\Domain\Workspaces\Models\User;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Pii\Pii;
use App\Support\Tenancy\CurrentWorkspace;
use App\Support\Tenancy\WorkspaceLocale;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;

/**
 * El reporte mensual con marca (M22): hechos → resumen (IA validada o
 * plantilla) → PDF → correo a los dueños del espacio de trabajo. Uno por mes y
 * espacio de trabajo (`monthly_reports` único por periodo); regenerarlo
 * reemplaza el anterior.
 */
final class MonthlyReports
{
    public function __construct(
        private readonly MonthlyReportFacts $facts,
        private readonly MonthlyReportSummary $summary,
        private readonly MonthlyReportPdf $pdf,
        private readonly CurrentWorkspace $current,
        private readonly RecordAuditLog $audit,
    ) {}

    /** El mes que se reporta hoy: el anterior, en la zona horaria del espacio de trabajo. */
    public static function lastMonth(Workspace $workspace, ?CarbonImmutable $now = null): CarbonImmutable
    {
        [$from] = MoneyGenerated::month($workspace, $now);

        return $from->subMonth();
    }

    public function generate(Workspace $workspace, CarbonImmutable $period, bool $force = false): MonthlyReport
    {
        return $this->current->run($workspace, function () use ($workspace, $period, $force): MonthlyReport {
            [$from] = MoneyGenerated::month($workspace, $period);
            $existing = MonthlyReport::query()->whereDate('period', $from->toDateString())->first();
            if ($existing !== null && ! $force) {
                return $existing;
            }
            $facts = $this->facts->build($workspace, $from);
            $summary = $this->summary->write($workspace->id, $facts);

            $report = $existing ?? new MonthlyReport(['workspace_id' => $workspace->id, 'period' => $from->toDateString()]);
            $report->fill([
                'facts' => $facts + ['validacion' => array_intersect_key($summary['validation'], array_flip(['ok', 'fallback_reason', 'unknown', 'attempts']))],
                'summary' => $summary['text'],
                'summary_source' => $summary['source'],
            ])->save();
            $report->forceFill(['pdf_path' => $this->pdf->render($workspace, $report)])->save();

            return $report;
        });
    }

    /**
     * Envía el reporte a los dueños (los que no lo desactivaron en sus avisos).
     *
     * @return array{status: string, count: int}
     */
    public function send(Workspace $workspace, MonthlyReport $report, bool $force = false, ?string $userId = null): array
    {
        if ($report->sent_at !== null && ! $force) {
            return ['status' => 'already_sent', 'count' => (int) ($report->recipients['count'] ?? 0)];
        }
        $owners = self::recipients($workspace);
        foreach ($owners as $user) {
            Mail::to($user->email)->locale(WorkspaceLocale::of($workspace))
                ->queue(new MonthlyReportMail($workspace->id, $report->id, $user->name));
        }
        $report->forceFill([
            'sent_at' => $owners->isEmpty() ? $report->sent_at : now(),
            'recipients' => [
                'count' => $owners->count(),
                'users' => $owners->map(fn (User $u) => $u->publicId())->values()->all(),
                'masked' => $owners->map(fn (User $u) => Pii::maskEmail((string) $u->email))->values()->all(),
            ],
        ])->save();
        $this->audit->handle($force ? 'agency.report_resent' : 'agency.report_sent', $report, ['period' => $report->period->format('Y-m'), 'recipients' => $owners->count()], $workspace->id, null, $userId);

        return ['status' => $owners->isEmpty() ? 'no_recipients' : 'sent', 'count' => $owners->count()];
    }

    /** @return Collection<int, User> */
    public static function recipients(Workspace $workspace): Collection
    {
        return $workspace->users()->withPivot('notifications')->wherePivot('role', WorkspaceRole::Owner->value)->get()
            ->filter(fn (User $u) => (json_decode((string) $u->pivot?->getAttribute('notifications'), true)['monthly_report'] ?? true) !== false)
            ->values();
    }
}
