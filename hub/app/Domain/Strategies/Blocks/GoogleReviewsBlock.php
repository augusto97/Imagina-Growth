<?php

declare(strict_types=1);

namespace App\Domain\Strategies\Blocks;

use App\Domain\Reviews\Support\ReviewSettings;
use App\Domain\Strategies\Support\Previews;
use App\Domain\Strategies\Support\Requirements;
use App\Domain\Strategies\Support\StrategyContext;
use App\Domain\Strategies\Support\StrategyNotReady;
use App\Domain\Workspaces\Actions\RecordAuditLog;

/**
 * Reseñas de Google (M15): el enlace de Google Business Profile que la página de gracias ofrece a **todos** los
 * clientes por igual (filtrar a los satisfechos va contra las políticas de Google). Activar guarda el enlace (el que
 * llega en `google_review_url` o el de antes); pausar lo quita y lo recuerda para reanudar.
 */
final class GoogleReviewsBlock implements StrategyBlock
{
    public function kind(): string
    {
        return 'google_reviews';
    }

    public function requirements(StrategyContext $ctx, array $options = []): array
    {
        return [];
    }

    public function state(StrategyContext $ctx): BlockState
    {
        return ReviewSettings::for($ctx->workspace)['google_review_url'] !== null ? BlockState::on('resenas?tab=ajustes') : BlockState::off('resenas?tab=ajustes');
    }

    public function activate(StrategyContext $ctx, array $options, ?string $userId): array
    {
        $settings = ReviewSettings::for($ctx->workspace);
        $url = Requirements::googleUrl($ctx, $options) ?? throw new StrategyNotReady(__('strategies.requirements.google_url.missing'), [Requirements::check('google_url', $ctx, $options)], [], 'resenas?tab=ajustes');
        if ($settings['google_review_url'] === $url) {
            return [];
        }
        $settings['google_review_url'] = $url;
        ReviewSettings::save($ctx->workspace, $settings);
        app(RecordAuditLog::class)->handle('reviews.google_link_changed', $ctx->workspace, ['set' => true], $ctx->id(), null, $userId);

        return [];
    }

    public function pause(StrategyContext $ctx, ?string $userId): array
    {
        $settings = ReviewSettings::for($ctx->workspace);
        $url = $settings['google_review_url'];
        if ($url !== null) {
            $settings['google_review_url'] = null;
            ReviewSettings::save($ctx->workspace, $settings);
            app(RecordAuditLog::class)->handle('reviews.google_link_changed', $ctx->workspace, ['set' => false], $ctx->id(), null, $userId);
        }

        return $url !== null ? ['google_review_url' => $url] : [];
    }

    public function preview(StrategyContext $ctx): array
    {
        return Previews::summary([__('strategies.preview.google_thanks'), __('strategies.preview.google_everyone')]);
    }
}
