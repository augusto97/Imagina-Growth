<?php

use App\Domain\Campaigns\Support\WaRateCard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Tarifas de WhatsApp vigentes desde el 1 de octubre de 2026 (F11, tabla oficial en USD de Meta): México sube el
 * marketing a 0,0397, el servicio y la utilidad dentro de la ventana empiezan a cobrarse, y se suman los mercados de
 * Argentina, Brasil, Chile, Perú, Norteamérica y «Resto de América Latina» (`LA`, donde está Venezuela). Solo agrega las
 * filas que falten: una tarifa ya editada a mano no se pisa.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (WaRateCard::TABLES as $date => $markets) {
            foreach ($markets as $market => $categories) {
                foreach ($categories as $category => $rate) {
                    DB::table('wa_rates')->insertOrIgnore([
                        'country' => $market, 'category' => $category, 'effective_from' => $date, 'rate_usd' => $rate, 'source_url' => WaRateCard::SOURCE_URL,
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        DB::table('wa_rates')->where('effective_from', WaRateCard::EFFECTIVE_FROM)->delete();
    }
};
