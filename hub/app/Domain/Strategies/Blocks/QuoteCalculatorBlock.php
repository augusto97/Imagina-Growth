<?php

declare(strict_types=1);

namespace App\Domain\Strategies\Blocks;

use App\Domain\Quotes\Models\QuoteCalculator;
use App\Domain\Strategies\Support\Previews;
use App\Domain\Strategies\Support\StrategyContext;
use App\Domain\Workspaces\Actions\RecordAuditLog;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * El cotizador (M18): cada calculadora es propia del negocio (precios, metros, paquetes), así que no se crea una
 * genérica. Activar reanuda los cotizadores en pausa; sin ninguno listo, la estrategia lleva a armar el primero.
 */
final class QuoteCalculatorBlock implements StrategyBlock
{
    public function kind(): string
    {
        return 'quote_calculators';
    }

    public function requirements(StrategyContext $ctx, array $options = []): array
    {
        return [];
    }

    public function state(StrategyContext $ctx): BlockState
    {
        $rows = $this->calculators($ctx);
        if ($rows->contains('status', 'active')) {
            return BlockState::on('en-el-sitio/cotizador', $ctx->hasSite() ? [] : ['site_down']);
        }

        return $rows->contains('status', 'paused') ? BlockState::paused('en-el-sitio/cotizador') : BlockState::off('en-el-sitio/cotizador');
    }

    public function activate(StrategyContext $ctx, array $options, ?string $userId): array
    {
        $this->set($ctx, 'paused', 'active', $userId);

        return [];
    }

    public function pause(StrategyContext $ctx, ?string $userId): array
    {
        $this->set($ctx, 'active', 'paused', $userId);

        return [];
    }

    public function preview(StrategyContext $ctx): array
    {
        return Previews::summary([__('strategies.preview.quote_price'), __('strategies.preview.quote_lead')]);
    }

    /** @return Collection<int, \stdClass> */
    private function calculators(StrategyContext $ctx): Collection
    {
        return $ctx->remember('quote_calculators', fn () => DB::table('quote_calculators')->where('workspace_id', $ctx->id())->get(['id', 'status']));
    }

    private function set(StrategyContext $ctx, string $from, string $to, ?string $userId): void
    {
        foreach (QuoteCalculator::query()->where('status', $from)->get() as $calculator) {
            $calculator->forceFill(['status' => $to])->save();
            app(RecordAuditLog::class)->handle('quote_calculator.updated', $calculator, ['fields' => ['status'], 'status' => $to], $ctx->id(), null, $userId);
        }
    }
}
