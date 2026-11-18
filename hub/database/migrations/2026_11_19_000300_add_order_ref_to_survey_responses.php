<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La respuesta de la página de gracias puede llegar antes que el pedido (el
 * outbox del plugin envía cada minuto): se guarda el pedido externo y se
 * vincula después.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('survey_responses', function (Blueprint $table) {
            $table->char('site_id', 26)->nullable()->after('survey_id');
            $table->string('external_order_id', 64)->nullable()->after('order_id');
            $table->string('answer', 64)->nullable()->after('answers');
            $table->unique(['survey_id', 'site_id', 'external_order_id']);
            $table->index(['workspace_id', 'answer']);
        });
    }

    public function down(): void
    {
        Schema::table('survey_responses', function (Blueprint $table) {
            $table->dropUnique(['survey_id', 'site_id', 'external_order_id']);
            $table->dropIndex(['workspace_id', 'answer']);
            $table->dropColumn(['site_id', 'external_order_id', 'answer']);
        });
    }
};
