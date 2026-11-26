<?php

declare(strict_types=1);

namespace App\Domain\Strategies\Blocks;

use App\Domain\Reviews\Support\ReviewSettings;
use App\Domain\Strategies\Support\Previews;
use App\Domain\Strategies\Support\Requirement;
use App\Domain\Strategies\Support\StrategyContext;
use App\Domain\Workspaces\Actions\RecordAuditLog;

/**
 * La solicitud automática de reseñas (M15, flujo 8 de la biblioteca: la hace el propio módulo con su programador,
 * `igr:reviews:send`, y su cascada de canales). Activar la enciende en Reseñas → Ajustes por correo (y por WhatsApp
 * solo si ya hay una plantilla aprobada elegida); sigue siendo marketing, con consentimiento y horario legal.
 */
final class ReviewRequestBlock implements StrategyBlock
{
    public function kind(): string
    {
        return 'review_request';
    }

    public function requirements(StrategyContext $ctx, array $options = []): array
    {
        $settings = ReviewSettings::for($ctx->workspace)['request'];
        $whatsapp = $ctx->hasChannel('whatsapp') && $settings['whatsapp_template_id'] !== null;

        return [new Requirement('email', $ctx->hasChannel('email') || $whatsapp, true, 'canales')];
    }

    public function state(StrategyContext $ctx): BlockState
    {
        $request = ReviewSettings::for($ctx->workspace)['request'];
        if (! $request['enabled']) {
            return BlockState::off('resenas?tab=ajustes');
        }

        return BlockState::on('resenas?tab=ajustes', array_values(array_filter([$ctx->channelProblem($request['channels'])])));
    }

    public function activate(StrategyContext $ctx, array $options, ?string $userId): array
    {
        $settings = ReviewSettings::for($ctx->workspace);
        if ($settings['request']['enabled']) {
            return [];
        }
        $channels = [];
        if ($ctx->hasChannel('whatsapp') && $settings['request']['whatsapp_template_id'] !== null) {
            $channels[] = 'whatsapp';
        }
        if ($ctx->hasChannel('email')) {
            $channels[] = 'email';
        }
        $settings['request']['enabled'] = true;
        $settings['request']['channels'] = $channels;
        ReviewSettings::save($ctx->workspace, $settings);
        $this->audit($ctx, $settings, $userId);

        return [];
    }

    public function pause(StrategyContext $ctx, ?string $userId): array
    {
        $settings = ReviewSettings::for($ctx->workspace);
        if ($settings['request']['enabled']) {
            $settings['request']['enabled'] = false;
            ReviewSettings::save($ctx->workspace, $settings);
            $this->audit($ctx, $settings, $userId);
        }

        return [];
    }

    public function preview(StrategyContext $ctx): array
    {
        return ['kind' => 'messages', 'messages' => [Previews::email($ctx, 'review_request')]];
    }

    /** @param array<string, mixed> $settings */
    private function audit(StrategyContext $ctx, array $settings, ?string $userId): void
    {
        app(RecordAuditLog::class)->handle('reviews.settings_updated', $ctx->workspace, [
            'enabled' => $settings['request']['enabled'], 'moderation' => $settings['moderation']['mode'], 'channels' => $settings['request']['channels'],
        ], $ctx->id(), null, $userId);
    }
}
