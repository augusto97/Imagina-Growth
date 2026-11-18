<?php

declare(strict_types=1);

namespace App\Domain\Surveys\Actions;

use App\Domain\Commerce\Models\Order;
use App\Domain\Surveys\Models\SurveyDefinition;
use App\Domain\Surveys\Models\SurveyRequest;
use App\Domain\Workspaces\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Programa las encuestas NPS y CSAT activas de un pedido (M15, flujo 17): una
 * por encuesta y pedido, N días después del evento elegido (entrega o pago).
 * Corre dentro del contexto del espacio de trabajo.
 */
final class ScheduleSurveyRequests
{
    /** @return list<SurveyRequest> */
    public function handle(Workspace $workspace, Order $order, string $trigger, ?CarbonImmutable $now = null): array
    {
        if ($order->contact_id === null || in_array($order->status, ['cancelled', 'refunded', 'failed'], true)) {
            return [];
        }
        $now ??= CarbonImmutable::now();
        $out = [];
        $surveys = SurveyDefinition::query()->whereIn('kind', NpsSurvey::KINDS)->where('status', 'active')->get();
        foreach ($surveys as $survey) {
            $t = NpsSurvey::trigger($survey);
            if ($t['after'] !== $trigger) {
                continue;
            }
            $existing = SurveyRequest::query()->where('survey_id', $survey->id)->where('order_id', $order->id)->first();
            if ($existing !== null) {
                $out[] = $existing;

                continue;
            }
            $at = $now->addDays($t['days']);
            try {
                $out[] = SurveyRequest::query()->create([
                    'workspace_id' => $workspace->id,
                    'survey_id' => $survey->id,
                    'site_id' => $order->site_id,
                    'contact_id' => $order->contact_id,
                    'order_id' => $order->id,
                    'status' => 'scheduled',
                    'scheduled_for' => $at,
                    'expires_at' => $at->addDays(30),
                ]);
            } catch (UniqueConstraintViolationException) {
                // Otro proceso la programó al mismo tiempo.
            }
        }

        return $out;
    }
}
