<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Insights\Detectors;

use App\Domain\Campaigns\Models\Campaign;
use App\Domain\Campaigns\Support\CommercialCalendar;
use App\Domain\Intelligence\Insights\Detector;
use App\Domain\Intelligence\Insights\InsightCandidate;
use App\Domain\Services\Verticals\VerticalCatalog;
use App\Domain\Workspaces\Models\Workspace;
use Carbon\CarbonImmutable;

/**
 * #24 Momento comercial cerca (rediseño v2 §11.5): unas semanas antes del día sugerido para enviar la campaña de un
 * momento del calendario comercial del país (Día de la Madre, Black Friday, inscripciones de enero, chequeo antes de
 * vacaciones…) que aplica al tipo de negocio y la vertical, y que todavía no tiene su campaña, avisa con la campaña
 * lista para crear en un clic (`calendar_campaign`). Sin dinero estimado: no hay con qué calcularlo sin inventar.
 * Vence el día del momento.
 */
final class CommercialMomentDetector implements Detector
{
    public function key(): string
    {
        return 'commercial_moment';
    }

    public function detect(Workspace $workspace, CarbonImmutable $now): array
    {
        $tz = (string) ($workspace->timezone ?: 'America/Bogota');
        $today = $now->setTimezone($tz)->startOfDay();
        $profile = VerticalCatalog::forWorkspace($workspace);
        $created = (array) ($workspace->setting('calendar_campaigns') ?? []);
        $alive = Campaign::query()->whereIn('id', array_values(array_filter($created, 'is_string')))->where('status', '!=', Campaign::CANCELLED)->pluck('id')->flip();
        $out = [];
        foreach (CommercialCalendar::upcoming((string) $workspace->country, $profile['business_type'], $profile['vertical'], $today, 120) as $m) {
            $ref = $m['key'].':'.$m['year'];
            $date = CarbonImmutable::parse((string) $m['date'], $tz);
            if ($today->toDateString() < $m['notice_from'] || $today->greaterThanOrEqualTo($date) || (isset($created[$ref]) && $alive->has($created[$ref]))) {
                continue;
            }
            $daysLeft = (int) $today->diffInDays($date);
            $sendOn = CarbonImmutable::parse((string) $m['starts_communicating'], $tz);
            $out[] = new InsightCandidate(
                fingerprint: $ref,
                severity: 'opportunity',
                title: __('En :n días es :name: tu campaña ya está lista para revisar', ['n' => $daysLeft, 'name' => $m['name']]),
                facts: [
                    'moment' => $m['key'], 'moment_name' => $m['name'], 'date' => $m['date'], 'days_left' => $daysLeft,
                    'send_on' => $m['starts_communicating'], 'channel' => $m['campaign']['channel'], 'template_key' => $m['campaign']['template'],
                    'audience' => $m['campaign']['segment'] ?? 'all_contacts', 'for' => $m['campaign']['for'],
                ],
                assumptions: [__('Sugerimos enviarla el :date. La campaña queda en borrador: revísala, ajusta el texto y apruébala.', ['date' => $sendOn->toDateString()])],
                action: ['label' => __('Crear la campaña de :name', ['name' => $m['name']]), 'to' => 'campanas?vista=calendario', 'kind' => 'calendar_campaign', 'payload' => ['moment' => $m['key'], 'year' => $m['year']]],
                periodStart: $today->toDateString(),
                periodEnd: $m['date'],
                validDays: max(1, $daysLeft),
            );
        }

        return $out;
    }
}
