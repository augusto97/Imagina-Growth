<?php

use App\Domain\Campaigns\Support\WaRateCard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Tarifas de WhatsApp (F15): sin ellas las campañas no estiman su costo, y antes solo se cargaban con
 * `igr:wa-rates --seed`, que ni el instalador ni el actualizador corrían. Se cargan si la tabla está vacía; una
 * tabla ya cargada (o actualizada a mano) no se toca.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('wa_rates')->count() === 0) {
            WaRateCard::seed();
        }
    }

    public function down(): void
    {
        // Las tarifas se quedan: las usan las estimaciones de costo.
    }
};
