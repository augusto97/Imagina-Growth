<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Predictions\Actions;

use App\Analytics\Ch;
use App\Domain\Intelligence\Predictions\Models\PredictionRun;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Todas las predicciones de un espacio de trabajo (inteligencia.md §4). Con
 * `train` (semanal o a pedido) también ajusta BG/NBD y Gamma-Gamma en el servicio
 * Python; cada noche recalcula lo demás. Un paso que falla queda como corrida
 * `failed` y no detiene los demás.
 */
final class RunPredictions
{
    public const STEPS = ['edno', 'lifetime', 'affinity', 'next_product', 'price_band', 'best_hour', 'engagement', 'propensity'];

    public function __construct(
        private readonly ComputeExpectedNextOrder $edno,
        private readonly TrainLifetimeModels $lifetime,
        private readonly HeuristicChurn $heuristic,
        private readonly ComputeAffinity $affinity,
        private readonly BuildProductTransitions $transitions,
        private readonly ComputePriceBands $priceBands,
        private readonly ComputeBestTimes $bestTimes,
        private readonly ComputeEngagement $engagement,
        private readonly CurrentWorkspace $current,
    ) {}

    /**
     * @param  list<string>  $only
     * @return array<string, string> paso → estado
     */
    public function handle(Workspace $workspace, bool $train, array $only = []): array
    {
        return $this->current->run($workspace, function () use ($workspace, $train, $only): array {
            $want = fn (string $step): bool => $only === [] || in_array($step, $only, true);
            $out = [];
            $step = function (string $name, callable $fn) use (&$out, $workspace): void {
                try {
                    $out[$name] = (string) ($fn()['status'] ?? 'completed');
                } catch (Throwable $e) {
                    Log::error('igr.predictions.step_failed', ['workspace' => $workspace->id, 'step' => $name, 'error' => mb_substr($e->getMessage(), 0, 300)]);
                    PredictionRun::record($workspace->id, $name === 'lifetime' ? 'clv' : $name, 'failed', ['reason' => mb_substr($e->getMessage(), 0, 300)]);
                    $out[$name] = 'failed';
                }
            };

            if ($want('edno') || $want('lifetime')) {
                $step('edno', fn () => $this->edno->handle($workspace, $want('edno')));
            }
            if ($train && $want('lifetime')) {
                $step('lifetime', fn () => $this->lifetime->handle($workspace));
            }
            // Sin BG/NBD vigente, el abandono sale de la heurística de días sin comprar.
            $churn = PredictionRun::query()->where('workspace_id', $workspace->id)->where('model', 'churn')->orderByDesc('trained_at')->orderByDesc('id')->first();
            if (($want('edno') || $want('lifetime')) && ($churn === null || $churn->status !== 'completed')) {
                $step('churn', fn () => $this->heuristic->handle($workspace, $this->edno->intervals()));
            }
            foreach (['affinity' => $this->affinity, 'next_product' => $this->transitions, 'price_band' => $this->priceBands, 'best_hour' => $this->bestTimes, 'engagement' => $this->engagement] as $name => $action) {
                if ($want($name)) {
                    $step($name, fn () => $action->handle($workspace));
                }
            }
            if ($train && $want('propensity')) {
                $step('propensity', fn () => $this->propensity($workspace));
            }

            return $out;
        });
    }

    /**
     * Propensión a 30 días (§4.4): solo con volumen (2.000 compradores y 50.000
     * sesiones al mes). El modelo (LightGBM) todavía no está activo; la corrida deja
     * dicho por qué no hay predicción.
     *
     * @return array{status: string}
     */
    private function propensity(Workspace $workspace): array
    {
        $buyers = DB::table('orders')->where('workspace_id', $workspace->id)->where('is_paid', true)->whereNotNull('contact_id')->distinct()->count('contact_id');
        $sessions = 0;
        try {
            $sessions = (int) (Ch::forWorkspace($workspace)->select(
                'SELECT count() AS c FROM sessions WHERE workspace_id = {workspace_id:UInt32} AND started_at >= now() - INTERVAL 30 DAY'
            )[0]['c'] ?? 0);
        } catch (Throwable) {
        }
        $minBuyers = (int) config('igr-predictions.thresholds.propensity_buyers', 2000);
        $minSessions = (int) config('igr-predictions.thresholds.propensity_sessions_month', 50000);
        $reason = $buyers < $minBuyers || $sessions < $minSessions
            ? __('Hay :b compradores y :s sesiones en 30 días; la propensión necesita :mb compradores y :ms sesiones al mes.', ['b' => $buyers, 's' => $sessions, 'mb' => $minBuyers, 'ms' => $minSessions])
            : __('Hay volumen suficiente, pero el modelo de propensión todavía no está activo en esta versión.');
        PredictionRun::record($workspace->id, 'propensity', 'insufficient_data', ['reason' => $reason, 'buyers' => $buyers, 'sessions_30d' => $sessions]);

        return ['status' => 'insufficient_data'];
    }
}
