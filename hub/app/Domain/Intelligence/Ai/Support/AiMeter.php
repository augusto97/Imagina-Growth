<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Ai\Support;

use App\Domain\Billing\Contracts\Entitlements;
use App\Domain\Billing\Usage\UsageActivity;
use App\Domain\Intelligence\Ai\Data\AiUsage;
use App\Domain\Workspaces\Models\Workspace;
use Illuminate\Support\Facades\DB;

/**
 * Costo y tope de la IA (inteligencia.md §6.1): cada llamada suma sus tokens y su
 * costo en `ai_usage` (por día, función y modelo), y el gasto del mes se compara
 * con el tope del espacio de trabajo antes de llamar.
 */
final class AiMeter
{
    public static function cost(string $model, int $input, int $output, int $cacheRead = 0, int $cacheWrite = 0): float
    {
        $p = (array) config("igr-ai.pricing.{$model}", []);

        return round(($input * (float) ($p['input'] ?? 0) + $output * (float) ($p['output'] ?? 0)
            + $cacheRead * (float) ($p['cache_read'] ?? 0) + $cacheWrite * (float) ($p['cache_write'] ?? 0)) / 1_000_000, 6);
    }

    public function record(string $workspaceId, string $feature, string $model, AiUsage $usage): void
    {
        $date = now()->toDateString();
        DB::table('ai_usage')->upsert([[
            'workspace_id' => $workspaceId, 'date' => $date, 'feature' => mb_substr($feature, 0, 32), 'model' => mb_substr($model, 0, 48),
            'requests' => $usage->requests, 'tokens_in' => $usage->inputTokens, 'tokens_out' => $usage->outputTokens,
            'cache_read' => $usage->cacheReadTokens, 'cache_write' => $usage->cacheWriteTokens, 'cost_usd' => $usage->costUsd,
        ]], ['workspace_id', 'date', 'feature', 'model'], [
            'requests' => DB::raw('requests + VALUES(requests)'),
            'tokens_in' => DB::raw('tokens_in + VALUES(tokens_in)'),
            'tokens_out' => DB::raw('tokens_out + VALUES(tokens_out)'),
            'cache_read' => DB::raw('cache_read + VALUES(cache_read)'),
            'cache_write' => DB::raw('cache_write + VALUES(cache_write)'),
            'cost_usd' => DB::raw('cost_usd + VALUES(cost_usd)'),
        ]);
        UsageActivity::touch($workspaceId);
    }

    /** Gasto del mes en curso (USD). */
    public function spentThisMonth(string $workspaceId): float
    {
        return (float) DB::table('ai_usage')->where('workspace_id', $workspaceId)
            ->where('date', '>=', now()->startOfMonth()->toDateString())->sum('cost_usd');
    }

    public function withinBudget(string $workspaceId): bool
    {
        return $this->spentThisMonth($workspaceId) < $this->budget($workspaceId);
    }

    /** Tope del mes: el del plan (M21) o, sin plan, `igr-ai.monthly_budget_usd`. Con el módulo de IA apagado, 0. */
    public function budget(string $workspaceId): float
    {
        $workspace = Workspace::query()->find($workspaceId);
        $default = (float) config('igr-ai.monthly_budget_usd', 25);
        if ($workspace === null) {
            return $default;
        }
        $entitlements = app(Entitlements::class);
        if (! $entitlements->allows($workspace, 'ai')) {
            return 0.0;
        }

        return (float) ($entitlements->limit($workspace, 'ai_budget_usd') ?? $default);
    }
}
