<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Al proyectar un pedido nuevo se buscan las respuestas de encuesta que lo
 * esperaban (llegaron antes que el pedido): búsqueda por sitio y pedido externo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('survey_responses', function (Blueprint $table) {
            $table->index(['site_id', 'external_order_id']);
        });
    }

    public function down(): void
    {
        Schema::table('survey_responses', function (Blueprint $table) {
            $table->dropIndex(['site_id', 'external_order_id']);
        });
    }
};
