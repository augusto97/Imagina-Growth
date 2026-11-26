<?php

declare(strict_types=1);

namespace App\Domain\Strategies\Blocks;

use App\Domain\Strategies\Support\Previews;
use App\Domain\Strategies\Support\StrategyContext;
use App\Domain\Workspaces\Actions\RecordAuditLog;
use App\Domain\Workspaces\Models\Workspace;
use Illuminate\Support\Facades\DB;

/**
 * La bandeja con asignación automática (M11): cada conversación nueva le llega a alguien del equipo (por reglas y, si
 * no aplica ninguna, por turnos entre quienes están disponibles, AssignConversation). Viene encendida por defecto;
 * activar y pausar cambian `settings.inbox.auto_assign` sin tocar las reglas.
 */
final class InboxAssignmentBlock implements StrategyBlock
{
    public function kind(): string
    {
        return 'inbox_assignment';
    }

    public function requirements(StrategyContext $ctx, array $options = []): array
    {
        return [];
    }

    public function state(StrategyContext $ctx): BlockState
    {
        $on = $ctx->workspace->setting('inbox.auto_assign', true) !== false && $ctx->hasChannel('whatsapp', 'messenger', 'instagram');
        if (! $on) {
            return BlockState::off('bandeja');
        }
        $problem = $ctx->channelProblem(array_values(array_filter(['whatsapp', 'messenger', 'instagram'], fn ($c) => array_key_exists($c, $ctx->channels()))));

        return BlockState::on('bandeja', $problem === 'channel_down' ? [$problem] : []);
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
        return Previews::summary([__('strategies.preview.inbox_assign'), __('strategies.preview.inbox_rules')]);
    }

    private function set(StrategyContext $ctx, bool $enabled, ?string $userId): void
    {
        $changed = DB::transaction(function () use ($ctx, $enabled): bool {
            /** @var Workspace $locked */
            $locked = Workspace::query()->lockForUpdate()->findOrFail($ctx->id());
            $settings = is_array($locked->settings) ? $locked->settings : [];
            $inbox = (array) ($settings['inbox'] ?? []);
            if (($inbox['auto_assign'] ?? true) === $enabled && array_key_exists('auto_assign', $inbox)) {
                return false;
            }
            $settings['inbox'] = ['auto_assign' => $enabled, 'rules' => array_values((array) ($inbox['rules'] ?? []))];
            $locked->forceFill(['settings' => $settings])->save();
            $ctx->workspace->setRawAttributes($locked->getAttributes(), true);

            return true;
        });
        if ($changed) {
            app(RecordAuditLog::class)->handle('inbox.settings_updated', $ctx->workspace, ['auto_assign' => $enabled], $ctx->id(), null, $userId);
        }
    }
}
