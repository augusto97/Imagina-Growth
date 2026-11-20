<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * R12 de la auditoría: índices para las tareas programadas que buscan en todos los espacios de trabajo a la vez
 * (cada minuto o cada 5 minutos). Los índices que había empiezan por workspace_id y esas consultas no lo filtran:
 * recorrían la tabla completa (mensajes, entregas de webhooks) o el índice completo.
 */
return new class extends Migration
{
    public function up(): void
    {
        // igr:carts:abandon (cada minuto): carritos activos o abandonados por estado y última actividad.
        Schema::table('carts', function (Blueprint $table) {
            $table->index(['status', 'last_activity_at'], 'carts_status_activity');
        });
        // igr:messages:requeue (cada minuto): aplazados cuya hora llegó.
        Schema::table('messages', function (Blueprint $table) {
            $table->index(['status', 'deferred_until'], 'messages_status_deferred');
        });
        // igr:webhooks:retry (cada minuto) e igr:webhooks:prune (cada noche).
        Schema::table('webhook_deliveries', function (Blueprint $table) {
            $table->index('next_attempt_at', 'webhook_deliveries_next_attempt');
            $table->index('created_at', 'webhook_deliveries_created');
        });
        // igr:reviews:send y las encuestas (cada 5 minutos).
        Schema::table('review_requests', function (Blueprint $table) {
            $table->index(['status', 'scheduled_for'], 'review_requests_due');
        });
        Schema::table('survey_requests', function (Blueprint $table) {
            $table->index(['status', 'scheduled_for'], 'survey_requests_due');
        });
        // igr:bookings:tick (cada 5 minutos).
        Schema::table('appointments', function (Blueprint $table) {
            $table->index(['status', 'starts_at'], 'appointments_status_starts');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', fn (Blueprint $table) => $table->dropIndex('appointments_status_starts'));
        Schema::table('survey_requests', fn (Blueprint $table) => $table->dropIndex('survey_requests_due'));
        Schema::table('review_requests', fn (Blueprint $table) => $table->dropIndex('review_requests_due'));
        Schema::table('webhook_deliveries', function (Blueprint $table) {
            $table->dropIndex('webhook_deliveries_created');
            $table->dropIndex('webhook_deliveries_next_attempt');
        });
        Schema::table('messages', fn (Blueprint $table) => $table->dropIndex('messages_status_deferred'));
        Schema::table('carts', fn (Blueprint $table) => $table->dropIndex('carts_status_activity'));
    }
};
