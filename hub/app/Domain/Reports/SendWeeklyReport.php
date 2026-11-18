<?php

declare(strict_types=1);

namespace App\Domain\Reports;

use App\Domain\Reports\Mail\WeeklyReportMail;
use App\Domain\Workspaces\Enums\WorkspaceRole;
use App\Domain\Workspaces\Models\User;
use App\Domain\Workspaces\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Envía el resumen semanal a los dueños del espacio de trabajo (que no lo
 * hayan desactivado en workspace_user.notifications.weekly_report) y lo
 * registra en report_deliveries. Idempotente por semana.
 */
final class SendWeeklyReport
{
    public function __construct(private readonly WeeklyReport $report) {}

    /** @return array{status: string, recipients: list<string>, delivery_id: string|null} */
    public function handle(Workspace $workspace, ?CarbonImmutable $weekStart = null, bool $force = false): array
    {
        $weekStart ??= WeeklyReport::lastWeekStart($workspace);
        $existing = DB::table('report_deliveries')->where('workspace_id', $workspace->id)->where('kind', 'weekly')
            ->where('period_start', $weekStart->toDateString())->first();
        if ($existing !== null && $existing->delivered_at !== null && ! $force) {
            return ['status' => 'already_sent', 'recipients' => [], 'delivery_id' => (string) $existing->id];
        }

        $recipients = $workspace->users()->withPivot('notifications')->wherePivot('role', WorkspaceRole::Owner->value)->get()
            ->filter(fn (User $u) => (json_decode((string) $u->pivot?->getAttribute('notifications'), true)['weekly_report'] ?? true) !== false)
            ->values();
        $facts = $this->report->facts($workspace, $weekStart);
        foreach ($recipients as $user) {
            Mail::to($user->email)->locale(str_replace('-', '_', $workspace->locale))->queue(new WeeklyReportMail($facts, $user->name));
        }

        $id = $existing->id ?? strtoupper((string) Str::ulid());
        DB::table('report_deliveries')->updateOrInsert(
            ['workspace_id' => $workspace->id, 'kind' => 'weekly', 'period_start' => $weekStart->toDateString()],
            [
                'id' => $id,
                'period_end' => $weekStart->addDays(6)->toDateString(),
                'facts' => json_encode($facts, JSON_UNESCAPED_UNICODE),
                'recipients' => json_encode($recipients->map(fn (User $u) => $u->publicId())->all()),
                'delivered_at' => $recipients->isEmpty() ? null : now(),
                'updated_at' => now(),
                'created_at' => $existing->created_at ?? now(),
            ],
        );

        return ['status' => $recipients->isEmpty() ? 'no_recipients' : 'sent', 'recipients' => $recipients->map(fn (User $u) => $u->email)->all(), 'delivery_id' => (string) $id];
    }

    /** ¿Le toca hoy? Lunes a las 07:00 en la zona del espacio de trabajo (inteligencia.md §6.2). */
    public static function isDue(Workspace $workspace, ?CarbonImmutable $now = null): bool
    {
        $local = ($now ?? CarbonImmutable::now())->setTimezone($workspace->timezone ?: 'UTC');

        return $local->isMonday() && $local->hour === 7;
    }
}
