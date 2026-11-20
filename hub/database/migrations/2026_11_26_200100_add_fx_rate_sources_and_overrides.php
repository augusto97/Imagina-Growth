<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Tasas de cambio diarias (F11): el costo de los mensajes se estima en USD (tarifas de Meta, SES y Twilio) y se muestra
 * en la moneda del espacio de trabajo. Son de la plataforma, no de un espacio de trabajo:
 *
 * - `fx_rates` (modelo-de-datos.md §3, ya existía y nadie la llenaba): `igr:fx:update` guarda una fila por día con
 *   `base` = USD y `quote` = la moneda (unidades de la moneda por 1 USD). Se le suma de dónde salió la tasa y cuándo.
 *   Si un día la fuente falla, rige la última tasa conocida.
 * - `fx_rate_overrides`: la tasa que fija a mano el superadmin para una moneda (por ejemplo, la oficial del BCV para
 *   VES) desde una fecha; manda sobre la automática hasta que se quita.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fx_rates', function (Blueprint $table) {
            $table->string('source', 40)->nullable()->after('rate');
            $table->timestamp('fetched_at')->nullable()->after('source');
        });

        Schema::create('fx_rate_overrides', function (Blueprint $table) {
            $table->char('currency', 3)->primary();
            $table->decimal('rate', 20, 10);                // unidades de la moneda por 1 USD
            $table->date('effective_from');
            $table->string('note', 190)->nullable();
            $table->char('set_by', 26)->nullable();         // users.id
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fx_rate_overrides');
        Schema::table('fx_rates', function (Blueprint $table) {
            $table->dropColumn(['source', 'fetched_at']);
        });
    }
};
