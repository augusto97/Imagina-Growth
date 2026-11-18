<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Predicciones (Fase 4, M17): `insufficient_data` no cabe en 16 caracteres, y la
 * pantalla lista la última corrida de cada modelo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prediction_runs', function (Blueprint $table) {
            $table->string('status', 24)->change();
            $table->index(['workspace_id', 'model', 'status'], 'prediction_runs_status');
        });
    }

    public function down(): void
    {
        Schema::table('prediction_runs', function (Blueprint $table) {
            $table->dropIndex('prediction_runs_status');
            $table->string('status', 16)->change();
        });
    }
};
