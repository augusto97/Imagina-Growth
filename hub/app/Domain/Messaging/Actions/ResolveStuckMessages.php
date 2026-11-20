<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Actions;

use App\Domain\Messaging\Jobs\SendMessageJob;
use App\Domain\Messaging\Models\Message;
use App\Domain\Messaging\Support\MessageEvents;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Mensajes que se quedaron en `sending` (F9): el proceso que los enviaba murió durante la llamada al proveedor
 * (tiempo agotado, falta de memoria, reinicio). No se sabe si el proveedor lo recibió, así que:
 *
 * - **marketing:** pasa a `failed` con el motivo `stuck_sending` y no se reintenta (un duplicado de marketing es peor
 *   que uno perdido, y el cupo de frecuencia queda usado por si sí salió);
 * - **transaccional, utilidad, servicio o autenticación:** se reintenta **una** vez (vuelve a `queued` y a la cola);
 *   si se vuelve a quedar, `failed` con el mismo motivo.
 *
 * Solo toca los que llevan más de `igr-messaging.stuck_sending_minutes` (15) en `sending`, muy por encima del tiempo
 * máximo del job (60 s). Cada cambio es atómico: si el job termina mientras tanto, no se pisa.
 */
final class ResolveStuckMessages
{
    public const CODE = 'stuck_sending';

    public function __construct(private readonly CurrentWorkspace $current, private readonly MessageEvents $events) {}

    /** @return array{retried: int, failed: int} */
    public function handle(?CarbonImmutable $now = null, int $limit = 500): array
    {
        $now ??= CarbonImmutable::now();
        $cutoff = $now->subMinutes(max(5, (int) config('igr-messaging.stuck_sending_minutes', 15)));
        $out = ['retried' => 0, 'failed' => 0];
        $stuck = Message::withoutWorkspaceScope()->where('status', 'sending')->where('updated_at', '<', $cutoff)
            ->orderBy('updated_at')->limit(max(1, $limit))->get();
        foreach ($stuck as $message) {
            $workspace = Workspace::query()->find($message->workspace_id);
            if ($workspace === null) {
                continue;
            }
            $this->current->run($workspace, function () use ($message, $workspace, $now, &$out): void {
                $payload = (array) ($message->payload ?? []);
                $retry = ! $message->kind->isCommercial() && (int) ($payload['stuck_recoveries'] ?? 0) === 0;
                $claimed = Message::query()->whereKey($message->id)->where('status', 'sending')->where('updated_at', $message->updated_at)
                    ->update(['status' => $retry ? 'queued' : 'failed', 'updated_at' => $now]);
                if ($claimed !== 1) {
                    return;
                }
                if ($retry) {
                    $payload['stuck_recoveries'] = 1;
                    $message->forceFill(['status' => 'queued', 'payload' => $payload, 'error_code' => self::CODE,
                        'failed_reason' => __('El envío no se confirmó; se reintenta una vez.')])->save();
                    SendMessageJob::dispatch($workspace->id, $message->id)->onQueue('messages:'.$message->channel->value);
                    $out['retried']++;
                } else {
                    $reason = $message->kind->isCommercial()
                        ? __('El envío no se confirmó (el proceso se detuvo); no se reintenta para no enviarlo dos veces.')
                        : __('El envío no se confirmó tras un reintento.');
                    $message->forceFill(['status' => 'failed', 'error_code' => self::CODE, 'failed_reason' => $reason])->save();
                    $this->events->emit($workspace, $message, 'message.failed', ['reason' => $reason, 'error_code' => self::CODE]);
                    $out['failed']++;
                }
            });
        }
        if ($out['retried'] + $out['failed'] > 0) {
            Log::warning('igr.messages.stuck_resolved', $out);
        }

        return $out;
    }
}
