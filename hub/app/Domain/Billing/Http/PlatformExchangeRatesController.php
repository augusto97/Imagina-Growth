<?php

declare(strict_types=1);

namespace App\Domain\Billing\Http;

use App\Domain\Workspaces\Actions\RecordAuditLog;
use App\Support\Money\ExchangeRates;
use App\Support\Money\UpdateExchangeRates;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Plataforma → Tasas de cambio (solo superadmin, F11): la tasa que rige por moneda contra el dólar, la última
 * automática, la manual si hay, y el resultado de la última actualización. La manual manda desde su fecha hasta que se
 * quita (por ejemplo, la oficial del BCV para VES, que no tiene API). Todo cambio queda en la auditoría.
 */
final class PlatformExchangeRatesController
{
    public function __construct(private readonly ExchangeRates $rates, private readonly RecordAuditLog $audit) {}

    /** GET /api/platform/fx */
    public function index(): JsonResponse
    {
        return response()->json($this->payload());
    }

    /** PUT /api/platform/fx/{currency}: fija la tasa a mano desde una fecha (hoy por defecto). */
    public function update(Request $request, string $currency): JsonResponse
    {
        $currency = $this->currency($currency);
        $data = $request->validate([
            'rate' => ['required', 'numeric', 'gt:0', 'max:1000000000'],
            'effective_from' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.now()->addDays(31)->toDateString()],
            'note' => ['nullable', 'string', 'max:190'],
        ]);
        $from = $data['effective_from'] ?? now('UTC')->toDateString();
        $exists = DB::table('fx_rate_overrides')->where('currency', $currency)->exists();
        DB::table('fx_rate_overrides')->updateOrInsert(['currency' => $currency], [
            'rate' => round((float) $data['rate'], 10),
            'effective_from' => $from,
            'note' => $data['note'] ?? null,
            'set_by' => $request->user()?->getAuthIdentifier(),
            'updated_at' => now(),
        ] + ($exists ? [] : ['created_at' => now()]));
        $this->audit->handle('platform.fx_override_set', null, ['currency' => $currency, 'rate' => (string) $data['rate'], 'effective_from' => $from]);
        $this->rates->forget();

        return response()->json($this->payload());
    }

    /** DELETE /api/platform/fx/{currency}: vuelve a la tasa automática. */
    public function destroy(string $currency): JsonResponse
    {
        $currency = $this->currency($currency);
        if (DB::table('fx_rate_overrides')->where('currency', $currency)->delete() > 0) {
            $this->audit->handle('platform.fx_override_removed', null, ['currency' => $currency]);
        }
        $this->rates->forget();

        return response()->json($this->payload());
    }

    /** POST /api/platform/fx/refresh: trae las tasas de hoy sin esperar al programador. */
    public function refresh(UpdateExchangeRates $update): JsonResponse
    {
        $status = $update->handle();
        $this->rates->forget();
        if (! $status['ok']) {
            return response()->json(['message' => __('No se pudieron traer las tasas: :error Rige la última tasa conocida.', ['error' => (string) $status['error']])] + $this->payload(), 502);
        }

        return response()->json($this->payload());
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        $currencies = [];
        foreach (ExchangeRates::currencies() as $currency) {
            $current = $this->rates->describe($currency);
            $override = $this->rates->override($currency);
            $currencies[] = [
                'currency' => $currency,
                'rate' => $current['rate'],
                'date' => $current['date'],
                'manual' => $current['manual'],
                'stale' => $current['stale'],
                'auto' => $this->rates->latestAuto($currency),
                'override' => $override === null ? null : [
                    'rate' => $override['rate'], 'effective_from' => $override['from'], 'note' => $override['note'], 'updated_at' => $override['updated_at'],
                ],
            ];
        }

        return [
            'base' => ExchangeRates::BASE,
            'source' => (string) config('igr.fx.source'),
            'attribution_url' => (string) config('igr.fx.attribution_url'),
            'stale_after_days' => (int) config('igr.fx.stale_after_days', 3),
            'last_update' => UpdateExchangeRates::status(),
            'currencies' => $currencies,
        ];
    }

    private function currency(string $currency): string
    {
        $currency = strtoupper($currency);
        validator(['currency' => $currency], ['currency' => ['required', Rule::in(ExchangeRates::currencies())]])->validate();

        return $currency;
    }
}
