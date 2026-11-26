<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Insights\Detectors;

use App\Domain\Flows\Models\Flow;
use App\Domain\Intelligence\Insights\Detector;
use App\Domain\Intelligence\Insights\InsightCandidate;
use App\Domain\Intelligence\Insights\Money;
use App\Domain\Intelligence\Insights\Support\Assumptions;
use App\Domain\Intelligence\Insights\Support\Data;
use App\Domain\Intelligence\Insights\Support\Impact;
use App\Domain\Intelligence\Insights\Support\Term;
use App\Domain\Workspaces\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * #15 Flujo esencial inactivo: sin el flujo de carrito abandonado de la biblioteca
 * activo, el dinero estimado que se pierde = carritos abandonados de 30 días ×
 * tasa de recuperación de referencia (5 % por defecto, editable) × ticket.
 */
final class EssentialFlowInactiveDetector implements Detector
{
    public function __construct(private readonly Assumptions $assumptions) {}

    public function key(): string
    {
        return 'essential_flow_inactive';
    }

    public function detect(Workspace $workspace, CarbonImmutable $now): array
    {
        $status = Data::libraryFlowStatus('abandoned_cart');
        if ($status === Flow::ACTIVE) {
            return [];
        }
        $since = $now->subDays(30);
        $row = DB::table('carts')->where('workspace_id', $workspace->id)->whereNotNull('abandoned_at')->where('abandoned_at', '>=', $since->utc())
            ->selectRaw('COUNT(*) AS n, COALESCE(AVG(subtotal_ws), 0) AS ticket')->first();
        if ($row === null) {
            return [];
        }
        $n = (int) ($row->n ?? 0);
        if ($n < 5) {
            return [];
        }
        $ticket = (float) $row->ticket;
        $rate = $this->assumptions->value('cart_recovery_rate');
        $formula = Impact::of(
            new Term(__('carritos abandonados en 30 días'), $n, 'count'),
            new Term(__('de recuperación de referencia'), $rate, 'rate', 'cart_recovery_rate'),
            new Term(__('de ticket'), $ticket, 'money'),
        );

        return [new InsightCandidate(
            fingerprint: 'abandoned_cart',
            severity: $n >= 50 ? 'warning' : 'opportunity',
            title: __('Sin la automatización de carrito abandonado dejas ir :n carritos al mes', ['n' => Money::number($n)]),
            facts: ['abandoned_carts' => $n, 'avg_cart' => round($ticket, 2), 'cart_recovery_rate' => $rate, 'flow_status' => $status ?? 'missing', 'window_days' => 30],
            assumptions: [__('La tasa de recuperación es una referencia conservadora; tus resultados pueden ser mejores.')],
            action: ['label' => __('Activa la automatización «Carrito abandonado»'), 'to' => 'flujos?tab=biblioteca', 'kind' => 'activate_flow', 'payload' => ['library_key' => 'abandoned_cart']],
            periodStart: $since->toDateString(),
            periodEnd: $now->toDateString(),
            formula: $formula,
        )];
    }
}
