<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * A qué se dedica el negocio (docs/diseno/rediseno-v2.md §11.1): `store`, `professional`, `health`, `education`,
 * `real_estate`, `hospitality`, `automotive`, `b2b` u `other` (config/igr-verticals.php). Lo elige el asistente de
 * configuración y se cambia después en Configuración (PATCH del espacio de trabajo). Cambia las estrategias
 * recomendadas, los textos y los KPI del Inicio. Null mientras no se elija.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->string('vertical', 24)->nullable()->after('industry');
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropColumn('vertical');
        });
    }
};
