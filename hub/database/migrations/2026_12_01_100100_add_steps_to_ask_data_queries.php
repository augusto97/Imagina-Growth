<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * «Pregúntale a la IA» (inteligencia.md §6.3): los pasos que acompañan la respuesta, cada uno con la pantalla donde se
 * hace (`{text, page, ref, path}`, de `AssistantPages`). Sin datos de contactos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ask_data_queries', function (Blueprint $table) {
            $table->json('steps')->nullable()->after('actions');
        });
    }

    public function down(): void
    {
        Schema::table('ask_data_queries', function (Blueprint $table) {
            $table->dropColumn('steps');
        });
    }
};
