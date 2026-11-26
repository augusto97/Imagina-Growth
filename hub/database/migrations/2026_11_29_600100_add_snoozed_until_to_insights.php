<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Recomendaciones pospuestas (rediseño v2, Resultados → Recomendaciones): `status = snoozed` con `snoozed_until`. Mientras
 * tanto no sale en la lista, el Inicio ni el informe; al vencer vuelve a «abierta» (`Insight::wakeSnoozed`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('insights', function (Blueprint $table) {
            $table->timestamp('snoozed_until')->nullable()->after('dismissed_at');
        });
    }

    public function down(): void
    {
        Schema::table('insights', function (Blueprint $table) {
            $table->dropColumn('snoozed_until');
        });
    }
};
