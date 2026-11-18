<?php

declare(strict_types=1);

namespace App\Domain\Measurement\Jobs;

use App\Domain\Measurement\Conversions\ConversionLog;
use App\Domain\Measurement\Conversions\DeliveryResult;
use App\Domain\Measurement\Conversions\TargetRegistry;
use App\Domain\Measurement\Models\AdDestination;
use App\Domain\Workspaces\Models\Workspace;
use App\Ingest\HubEvents;
use App\Support\Tenancy\SetWorkspaceContext;
use App\Support\Tenancy\WorkspaceAware;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Entrega de una conversión a un endpoint (hub/CLAUDE.md §11). Va cifrado en
 * la cola porque lleva datos con hash, IP y user agent en tránsito.
 *
 * Reintentos propios (1 m, 5 m, 30 m, 2 h y luego cada 2 h) hasta 24 h después
 * del primer intento: las plataformas descartan conversiones viejas. Cada
 * intento queda en conversion_deliveries; el resultado final, además, como
 * evento del hub conversion.delivered.
 */
final class DeliverConversion implements ShouldBeEncrypted, ShouldQueue, WorkspaceAware
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    /**
     * @param  array<string, mixed>  $payload
     * @param  array{source_event_id: string, source_event: string, conversion: string, contact_id: string|null, match_keys: list<string>, first_attempt_at: int, attempt: int}  $meta
     */
    public function __construct(
        public string $workspaceIdValue,
        public string $destinationId,
        public string $target,
        public array $payload,
        public array $meta,
    ) {}

    public function workspaceId(): string
    {
        return $this->workspaceIdValue;
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [new SetWorkspaceContext];
    }

    public function handle(TargetRegistry $targets, ConversionLog $log, HubEvents $hubEvents): void
    {
        $target = $targets->get($this->target);
        $workspace = Workspace::query()->findOrFail($this->workspaceIdValue);
        $destination = AdDestination::query()->find($this->destinationId);
        $logMeta = [
            'workspace_ch' => $workspace->ch_id,
            'destination_id' => $this->destinationId,
            'target' => $this->target,
            'platform' => $target->platform()->value,
            'conversion' => $this->meta['conversion'],
            'source_event' => $this->meta['source_event'],
            'source_event_id' => $this->meta['source_event_id'],
            'match_keys' => $this->meta['match_keys'],
            'attempt' => $this->meta['attempt'],
            'test' => $destination !== null && ($destination->test_event_code ?? '') !== '',
        ];

        if ($destination === null || ! $destination->isActive()) {
            $log->record($logMeta, 'skipped', true);

            return;
        }

        $result = $target->send($destination, $this->payload);

        if ($result->isDelivered()) {
            $log->record($logMeta, $result, true);
            $destination->forceFill(['last_delivered_at' => now(), 'last_error' => null])->save();
            $this->emit($hubEvents, $workspace, $target->platform()->value, 'delivered');

            return;
        }

        $delay = $this->nextDelay();
        $deadline = $this->meta['first_attempt_at'] + (int) config('igr-measurement.give_up_after_hours') * 3600;
        if ($result->status === DeliveryResult::RETRY && time() + $delay <= $deadline) {
            $log->record($logMeta, $result, false);
            $next = $this->meta;
            $next['attempt']++;
            self::dispatch($this->workspaceIdValue, $this->destinationId, $this->target, $this->payload, $next)
                ->onQueue($target->platform()->queue())
                ->delay(now()->addSeconds($delay));

            return;
        }

        $final = $result->status === DeliveryResult::RETRY
            ? new DeliveryResult(DeliveryResult::FAILED, $result->httpStatus, $result->errorCode, __('Se agotaron los reintentos durante :h h. Último error: :msg', ['h' => config('igr-measurement.give_up_after_hours'), 'msg' => $result->message]))
            : $result;
        $log->record($logMeta, $final, true);
        $destination->forceFill(['last_error' => $final->message])->save();
        $this->emit($hubEvents, $workspace, $target->platform()->value, 'failed');
    }

    /** Segundos hasta el siguiente intento según el número de intento actual. */
    public function nextDelay(): int
    {
        /** @var list<int> $delays */
        $delays = config('igr-measurement.retry_delays');

        return $delays[min($this->meta['attempt'] - 1, count($delays) - 1)];
    }

    private function emit(HubEvents $hubEvents, Workspace $workspace, string $platform, string $status): void
    {
        $hubEvents->emit($workspace, 'conversion.delivered', [
            'platform' => $platform,
            'status' => $status,
            'source_event_id' => mb_substr($this->meta['source_event_id'], 0, 36),
            'conversion' => mb_substr($this->meta['conversion'], 0, 60),
        ], $this->meta['contact_id']);
    }
}
