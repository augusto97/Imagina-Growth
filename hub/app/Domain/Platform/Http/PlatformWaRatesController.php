<?php

declare(strict_types=1);

namespace App\Domain\Platform\Http;

use App\Domain\Campaigns\Support\WaRateCard;
use App\Domain\Workspaces\Actions\RecordAuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Plataforma → Ajustes → Tarifas de WhatsApp (F17): la tabla de Meta en USD por mercado y categoría con la que se
 * estima el costo de cada mensaje y de cada campaña (cumplimiento.md §5.1). Meta la cambia cada trimestre: el
 * superadmin la actualiza aquí con su fecha de vigencia, sin consola ni versión nueva (antes, `igr:wa-rates --seed`
 * y editar WaRateCard). Las de antes se quedan para los mensajes viejos.
 */
final class PlatformWaRatesController
{
    public const CATEGORIES = ['marketing', 'utility', 'authentication', 'service'];

    public function __construct(private readonly RecordAuditLog $audit) {}

    /** GET /api/platform/wa-rates */
    public function index(): JsonResponse
    {
        return response()->json($this->payload());
    }

    /**
     * PUT /api/platform/wa-rates {effective_from, rates: {mercado: {categoría: USD}}}: guarda la tabla que rige desde esa
     * fecha. Se escriben los mercados que vengan, con sus cuatro categorías.
     */
    public function update(Request $request): JsonResponse
    {
        $markets = self::markets();
        $data = $request->validate([
            'effective_from' => ['required', 'date_format:Y-m-d', 'after_or_equal:2024-01-01', 'before_or_equal:'.now()->addYear()->toDateString()],
            'rates' => ['required', 'array', 'min:1'],
            'rates.*' => ['required', 'array:'.implode(',', self::CATEGORIES)],
            'rates.*.*' => ['required', 'numeric', 'min:0', 'max:1'],
        ], [], ['rates.*.*' => __('la tarifa')]);
        $unknown = array_diff(array_keys((array) $data['rates']), $markets);
        if ($unknown !== []) {
            return response()->json(['message' => __('Hay mercados que no existen.'), 'errors' => ['rates' => [__('Hay mercados que no existen.')]]], 422);
        }
        foreach ((array) $data['rates'] as $market => $categories) {
            $missing = array_diff(self::CATEGORIES, array_keys((array) $categories));
            if ($missing !== []) {
                return response()->json(['message' => __('Falta alguna categoría de :market.', ['market' => $market]), 'errors' => ['rates.'.$market => [__('Falta alguna categoría.')]]], 422);
            }
        }

        DB::transaction(function () use ($data): void {
            foreach ((array) $data['rates'] as $market => $categories) {
                foreach ((array) $categories as $category => $rate) {
                    DB::table('wa_rates')->updateOrInsert(
                        ['country' => (string) $market, 'category' => (string) $category, 'effective_from' => (string) $data['effective_from']],
                        ['rate_usd' => round((float) $rate, 5), 'source_url' => WaRateCard::SOURCE_URL],
                    );
                }
            }
        });
        $this->audit->handle('platform.wa_rates_set', null, ['effective_from' => (string) $data['effective_from'], 'markets' => array_keys((array) $data['rates'])]);

        return response()->json($this->payload());
    }

    /** POST /api/platform/wa-rates/seed: vuelve a cargar las tablas que trae esta versión (lo de `igr:wa-rates --seed`). */
    public function seed(): JsonResponse
    {
        $rows = WaRateCard::seed();
        $this->audit->handle('platform.wa_rates_seeded', null, ['rows' => $rows, 'effective_from' => WaRateCard::EFFECTIVE_FROM]);

        return response()->json($this->payload());
    }

    /** @return list<string> */
    public static function markets(): array
    {
        return array_keys(WaRateCard::current());
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        $latest = WaRateCard::latestEffectiveFrom();
        $rates = [];
        foreach (self::markets() as $market) {
            foreach (self::CATEGORIES as $category) {
                $rate = DB::table('wa_rates')->where('country', $market)->where('category', $category)->orderByDesc('effective_from')->value('rate_usd');
                $rates[$market][$category] = $rate === null ? null : (float) $rate;
            }
        }

        return [
            'effective_from' => $latest?->toDateString(),
            'stale' => WaRateCard::isStale(),
            'stale_after_days' => WaRateCard::STALE_AFTER_DAYS,
            'bundled_from' => WaRateCard::EFFECTIVE_FROM,
            'source_url' => WaRateCard::SOURCE_URL,
            'markets' => self::markets(),
            'categories' => self::CATEGORIES,
            'rates' => $rates,
            'dates' => array_values(array_map('strval', DB::table('wa_rates')->distinct()->orderByDesc('effective_from')->limit(12)->pluck('effective_from')->map(fn ($d) => substr((string) $d, 0, 10))->all())),
        ];
    }
}
