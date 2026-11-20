<?php

declare(strict_types=1);

namespace App\Domain\Flows\Actions;

use App\Domain\Flows\Graph\NodeTypes;
use App\Domain\Flows\Models\Flow;
use App\Domain\Flows\Models\FlowRun;
use App\Domain\Flows\Support\InvalidFlow;
use App\Domain\Flows\Triggers\TriggerIndex;
use App\Domain\Messaging\Enums\Channel;
use App\Domain\Messaging\Models\ChannelAccount;
use App\Domain\Workspaces\Actions\RecordAuditLog;

/**
 * Activar, pausar o archivar. Pausar deja los runs donde van (al reactivar
 * siguen); archivar los termina con motivo `manual`.
 */
final class ChangeFlowStatus
{
    public function __construct(
        private readonly TriggerIndex $index,
        private readonly FinishRun $finish,
        private readonly RecordAuditLog $audit,
    ) {}

    /** @throws InvalidFlow */
    public function handle(Flow $flow, string $status): Flow
    {
        if ($status === Flow::ACTIVE) {
            if ($flow->current_version_id === null) {
                throw new InvalidFlow(['status' => __('Publica el flujo antes de activarlo.')]);
            }
            if (($flow->trigger['type'] ?? null) === null) {
                throw new InvalidFlow(['trigger' => __('Elige el disparador antes de activarlo.')]);
            }
            $missing = $this->missingChannels($flow);
            if ($missing !== []) {
                // Activo sin canal, cada envío salía denegado (`channel_unavailable`) sin que nadie lo notara.
                throw new InvalidFlow(['channel' => __('Conecta :channels en Canales antes de activar este flujo.', ['channels' => implode(' y ', $missing)])]);
            }
            $flow->forceFill(['status' => Flow::ACTIVE, 'activated_at' => $flow->activated_at ?? now()])->save();
        } elseif ($status === Flow::PAUSED) {
            $flow->forceFill(['status' => Flow::PAUSED])->save();
        } elseif ($status === Flow::ARCHIVED) {
            $flow->forceFill(['status' => Flow::ARCHIVED])->save();
            FlowRun::query()->where('flow_id', $flow->id)->whereIn('status', FlowRun::LIVE)->chunkById(200, function ($runs): void {
                foreach ($runs as $run) {
                    $this->finish->handle($run, FlowRun::EXITED, 'manual');
                }
            });
        }
        $this->index->forget($flow->workspace_id);
        $this->audit->handle(match ($status) {
            Flow::ACTIVE => 'flow.activated', Flow::PAUSED => 'flow.paused', default => 'flow.archived'
        }, $flow, [], $flow->workspace_id);

        return $flow;
    }

    /**
     * Canales que usa la versión publicada y no tienen una cuenta activa. Un reparto por canal necesita al menos uno.
     *
     * @return list<string> nombres para mostrar
     */
    private function missingChannels(Flow $flow): array
    {
        $graph = $flow->currentVersion()->graph ?? [];
        $needed = [];
        foreach ((array) ($graph['nodes'] ?? []) as $node) {
            $type = (string) ($node['type'] ?? '');
            $config = (array) ($node['config'] ?? []);
            $channel = match ($type) {
                NodeTypes::SEND_WHATSAPP => 'whatsapp',
                NodeTypes::SEND_EMAIL => 'email',
                NodeTypes::SEND_SMS => 'sms',
                NodeTypes::NOTIFY_TEAM => (string) ($config['channel'] ?? 'email'),
                default => null,
            };
            if ($channel !== null) {
                $needed[] = [$channel];
            } elseif (in_array($type, [NodeTypes::CHANNEL_SPLIT, NodeTypes::LEAD_FOLLOWUP, NodeTypes::AI_REACTIVATE], true) && (array) ($config['channels'] ?? []) !== []) {
                $needed[] = array_values(array_map('strval', (array) $config['channels']));
            }
        }
        if ($needed === []) {
            return [];
        }
        $connected = ChannelAccount::query()->where('status', 'active')->distinct()->pluck('channel')
            ->map(fn ($c) => $c instanceof Channel ? $c->value : (string) $c)->flip();
        $missing = [];
        foreach ($needed as $options) {
            $ok = false;
            foreach ($options as $c) {
                $ok = $ok || $connected->has($c);
            }
            if (! $ok) {
                $missing[$options[0]] = match ($options[0]) {
                    'whatsapp' => 'WhatsApp', 'sms' => 'SMS', 'messenger' => 'Messenger', 'instagram' => 'Instagram', default => __('el correo'),
                };
            }
        }

        return array_values($missing);
    }
}
