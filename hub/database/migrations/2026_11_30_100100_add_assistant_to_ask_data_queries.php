<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * «Pregúntale a la IA» desde cualquier pantalla (inteligencia.md §6.3): de qué pantalla vino la pregunta (solo el tipo,
 * nunca los hechos que se le pasaron al modelo) y el siguiente paso que propuso (borrador con el copiloto o una pantalla).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ask_data_queries', function (Blueprint $table) {
            $table->json('context')->nullable()->after('validation');
            $table->json('actions')->nullable()->after('context');
        });
    }

    public function down(): void
    {
        Schema::table('ask_data_queries', function (Blueprint $table) {
            $table->dropColumn(['context', 'actions']);
        });
    }
};
