<?php

declare(strict_types=1);

namespace App\Domain\Strategies\Blocks;

use App\Domain\Recommendations\Models\CommerceSettings;
use App\Domain\Strategies\Support\Previews;
use App\Domain\Strategies\Support\StrategyContext;
use App\Domain\Workspaces\Actions\RecordAuditLog;

/**
 * «Avísame cuando vuelva» y «avísame si baja de precio» (M16, flujo 14: los envía el propio módulo al momento, con su
 * tope de un aviso por producto cada 7 días). Viene encendido por defecto en una tienda; activar y pausar cambian
 * `watches.enabled` de En el sitio → Avisos.
 */
final class ProductWatchesBlock implements StrategyBlock
{
    public function kind(): string
    {
        return 'product_watches';
    }

    public function requirements(StrategyContext $ctx, array $options = []): array
    {
        return [];
    }

    public function state(StrategyContext $ctx): BlockState
    {
        $settings = $this->settings($ctx)->watchSettings();
        if (! $settings['enabled'] || ! $ctx->hasStore()) {
            return BlockState::off('en-el-sitio/avisos');
        }

        return BlockState::on('en-el-sitio/avisos', array_values(array_filter([$ctx->channelProblem($settings['channels'])])));
    }

    public function activate(StrategyContext $ctx, array $options, ?string $userId): array
    {
        $this->set($ctx, true, $userId);

        return [];
    }

    public function pause(StrategyContext $ctx, ?string $userId): array
    {
        $this->set($ctx, false, $userId);

        return [];
    }

    public function preview(StrategyContext $ctx): array
    {
        return ['kind' => 'messages', 'messages' => [Previews::email($ctx, 'back_in_stock')]];
    }

    private function settings(StrategyContext $ctx): CommerceSettings
    {
        return $ctx->remember('commerce_settings', fn () => CommerceSettings::current());
    }

    private function set(StrategyContext $ctx, bool $enabled, ?string $userId): void
    {
        $model = CommerceSettings::current();
        $current = $model->watchSettings();
        if ($current['enabled'] === $enabled && is_array($model->watches) && array_key_exists('enabled', $model->watches)) {
            return;
        }
        $model->watches = ['enabled' => $enabled, 'channels' => $current['channels'], 'templates' => $current['templates']];
        $model->save();
        app(RecordAuditLog::class)->handle('onsite_commerce.watches_updated', $model, ['enabled' => $enabled, 'channels' => $current['channels']], $ctx->id(), null, $userId);
    }
}
