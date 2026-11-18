<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Insights\Detectors;

use App\Analytics\Ch;
use App\Domain\Intelligence\Insights\Detector;
use App\Domain\Intelligence\Insights\InsightCandidate;
use App\Domain\Intelligence\Insights\Money;
use App\Domain\Intelligence\Insights\Support\Data;
use App\Domain\Workspaces\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * #17 Reputación del correo: en 14 días, rebotes por encima del 2 % o quejas por
 * encima del 0,1 % de lo enviado (al menos 200 correos). Por encima de eso los
 * proveedores empiezan a mandar todo a spam.
 */
final class EmailReputationDetector implements Detector
{
    public function key(): string
    {
        return 'email_reputation';
    }

    public function detect(Workspace $workspace, CarbonImmutable $now): array
    {
        $since = $now->subDays(14);
        $row = DB::table('messages')->where('workspace_id', $workspace->id)->where('channel', 'email')->where('direction', 'out')
            ->where('created_at', '>=', $since->utc())->whereNotIn('status', ['queued', 'deferred'])
            ->selectRaw("COUNT(*) AS sent, SUM(status = 'bounced') AS bounced")->first();
        if ($row === null) {
            return [];
        }
        $sent = (int) ($row->sent ?? 0);
        if ($sent < 200) {
            return [];
        }
        $complaints = (int) (Ch::forWorkspace($workspace)->select(<<<'SQL'
            SELECT count() AS n FROM events
            WHERE workspace_id = {workspace_id:UInt32} AND event = 'message.complained' AND occurred_at >= {since:DateTime64(3)} AND toString(properties.channel) = 'email'
            SQL, ['since' => Data::ch($since)])[0]['n'] ?? 0);
        $bounceRate = (int) $row->bounced / $sent;
        $complaintRate = $complaints / $sent;
        $maxBounce = (float) config('igr-insights.thresholds.bounce_rate', 0.02);
        $maxComplaint = (float) config('igr-insights.thresholds.complaint_rate', 0.001);
        if ($bounceRate <= $maxBounce && $complaintRate <= $maxComplaint) {
            return [];
        }
        $magnitude = max($bounceRate / $maxBounce, $complaintRate / $maxComplaint);

        return [new InsightCandidate(
            fingerprint: 'email',
            severity: $magnitude >= 2 ? 'critical' : 'warning',
            title: $complaintRate > $maxComplaint
                ? __('Las quejas por spam llegaron al :rate de tus correos', ['rate' => Money::percent($complaintRate, 2)])
                : __('El :rate de tus correos rebota', ['rate' => Money::percent($bounceRate)]),
            facts: ['sent' => $sent, 'bounced' => (int) $row->bounced, 'complaints' => $complaints, 'bounce_rate' => round($bounceRate, 4),
                'complaint_rate' => round($complaintRate, 5), 'bounce_limit' => $maxBounce, 'complaint_limit' => $maxComplaint, 'window_days' => 14],
            assumptions: [__('Limpia la lista (quita correos que rebotan), envía solo a quien aceptó y ofrece la baja visible.')],
            action: ['label' => __('Revisa el canal de correo'), 'to' => 'canales'],
            periodStart: $since->toDateString(),
            periodEnd: $now->toDateString(),
            magnitude: $magnitude,
        )];
    }
}
