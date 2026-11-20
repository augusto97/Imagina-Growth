<?php

declare(strict_types=1);

namespace App\Support\Money;

use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Fuente automática de las tasas (config `igr.fx`): el acceso libre de ExchangeRate-API,
 * `GET https://open.er-api.com/v6/latest/USD` (https://www.exchangerate-api.com/docs/free, verificado el 20 nov 2026):
 * sin llave, una actualización al día (`time_last_update_unix`), `result` = `success`, `base_code` = `USD` y `rates`
 * con COP, MXN, VES y el resto de las monedas ISO. Si se pide demasiado responde 429 por 20 minutos; una o dos
 * consultas al día no llegan a eso. Pide citar la fuente donde se muestran las tasas (la SPA lo hace junto al costo).
 *
 * VES: la referencia legal en Venezuela es la tasa oficial del BCV (bcv.org.ve), que no tiene API; esta fuente la sigue
 * de cerca. Quien la necesite exacta la fija a mano en Plataforma → Tasas de cambio.
 */
class ExchangeRateSource
{
    /**
     * Las tasas del día para las monedas configuradas.
     *
     * @return array{date: string, rates: array<string, float>, missing: list<string>}
     *
     * @throws RuntimeException con un motivo que se puede mostrar
     */
    public function fetch(): array
    {
        $url = (string) config('igr.fx.url');
        try {
            $response = Http::timeout(15)->connectTimeout(5)->acceptJson()->get($url);
        } catch (Throwable $e) {
            throw new RuntimeException(__('No se pudo consultar la fuente de tasas de cambio: :error', ['error' => $e->getMessage()]), 0, $e);
        }
        if (! $response->successful()) {
            throw new RuntimeException(__('La fuente de tasas de cambio respondió con el código :status.', ['status' => $response->status()]));
        }
        $json = $response->json();
        if (! is_array($json) || ($json['result'] ?? null) !== 'success' || strtoupper((string) ($json['base_code'] ?? '')) !== ExchangeRates::BASE || ! is_array($json['rates'] ?? null)) {
            throw new RuntimeException(__('La fuente de tasas de cambio devolvió una respuesta que no se entiende.'));
        }
        $updated = $json['time_last_update_unix'] ?? null;
        $date = is_int($updated) && $updated > 0 ? gmdate('Y-m-d', $updated) : gmdate('Y-m-d');

        $rates = [];
        $missing = [];
        foreach (ExchangeRates::currencies() as $currency) {
            $value = $json['rates'][$currency] ?? null;
            if (is_numeric($value) && (float) $value > 0.0) {
                $rates[$currency] = (float) $value;
            } else {
                $missing[] = $currency;
            }
        }
        if ($rates === []) {
            throw new RuntimeException(__('La fuente de tasas de cambio no trajo ninguna de las monedas.'));
        }

        return ['date' => $date, 'rates' => $rates, 'missing' => $missing];
    }
}
