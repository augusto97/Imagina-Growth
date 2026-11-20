<?php

declare(strict_types=1);

namespace App\Support\Money;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Trae las tasas del día de la fuente automática y las guarda (`igr:fx:update` y «Actualizar ahora» en Plataforma).
 * Si la fuente falla no se borra nada: rige la última tasa conocida y queda el motivo para Plataforma e igr:doctor.
 */
final class UpdateExchangeRates
{
    public const STATUS_KEY = 'igr:fx:status';

    public function __construct(private readonly ExchangeRateSource $source) {}

    /** @return array{ok: bool, at: string, date: string|null, stored: int, missing: list<string>, error: string|null} */
    public function handle(): array
    {
        try {
            $data = $this->source->fetch();
            $stored = ExchangeRates::store($data['date'], $data['rates'], (string) config('igr.fx.source', 'open.er-api.com'));
            $status = ['ok' => true, 'at' => now()->toIso8601String(), 'date' => $data['date'], 'stored' => $stored, 'missing' => $data['missing'], 'error' => null];
            if ($data['missing'] !== []) {
                Log::warning('igr.fx.missing_currencies', ['currencies' => $data['missing']]);
            }
        } catch (RuntimeException $e) {
            Log::warning('igr.fx.fetch_failed', ['error' => $e->getMessage()]);
            $previous = self::status();
            $status = ['ok' => false, 'at' => now()->toIso8601String(), 'date' => $previous['date'] ?? null, 'stored' => 0, 'missing' => [], 'error' => $e->getMessage()];
        }
        Cache::forever(self::STATUS_KEY, $status);

        return $status;
    }

    /**
     * Resultado de la última actualización, o null si nunca corrió.
     *
     * @return array<string, mixed>|null
     */
    public static function status(): ?array
    {
        $status = Cache::get(self::STATUS_KEY);

        return is_array($status) ? $status : null;
    }
}
