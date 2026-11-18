<?php

declare(strict_types=1);

namespace App\Domain\Measurement\Conversions;

use App\Analytics\Ch;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Cada intento de entrega en ClickHouse `conversion_deliveries`
 * (modelo-de-datos.md §10). Sin datos personales: solo qué claves de
 * coincidencia viajaron, no sus valores.
 */
final class ConversionLog
{
    public function __construct(private readonly Ch $ch) {}

    /**
     * @param  array{workspace_ch: int, destination_id: string, target: string, platform: string, conversion: string, source_event: string, source_event_id: string, match_keys: list<string>, attempt: int, test: bool}  $meta
     */
    public function record(array $meta, DeliveryResult|string $result, bool $final): void
    {
        $status = $result instanceof DeliveryResult ? ($result->isDelivered() ? 'delivered' : ($final ? 'failed' : 'retrying')) : $result;
        try {
            $this->ch->insert('conversion_deliveries', [[
                'workspace_id' => $meta['workspace_ch'],
                'event_id' => self::uuid($meta['source_event_id']),
                'platform' => $meta['platform'],
                'status' => $status,
                'http_status' => $result instanceof DeliveryResult ? $result->httpStatus : 0,
                'error' => $result instanceof DeliveryResult ? mb_substr($result->message, 0, 500) : '',
                'error_code' => $result instanceof DeliveryResult ? $result->errorCode : '',
                'match_keys' => $meta['match_keys'],
                'attempts' => min(255, $meta['attempt']),
                'delivered_at' => now()->utc()->format('Y-m-d H:i:s.v'),
                'destination_id' => $meta['destination_id'],
                'target' => $meta['target'],
                'conversion' => $meta['conversion'],
                'source_event' => $meta['source_event'],
                'final' => $final ? 1 : 0,
                'test' => $meta['test'] ? 1 : 0,
            ]], async: true);
        } catch (Throwable $e) {
            // El registro no debe tumbar la entrega: queda en el log de la aplicación.
            Log::warning('igr.conversions.log_failed', ['error' => mb_substr($e->getMessage(), 0, 200)]);
        }
    }

    /** ClickHouse exige un UUID; los eventos de prueba usan uno nulo. */
    private static function uuid(string $id): string
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id) === 1 ? strtolower($id) : '00000000-0000-0000-0000-000000000000';
    }
}
