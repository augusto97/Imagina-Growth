<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * M13 (fork L): lo que el libro de puntos necesita además de las tablas de la
 * Fase 3 (modelo-de-datos.md §13).
 *
 * - `loyalty_settlements`: cuándo se confirman los puntos pendientes de una
 *   referencia (pedido completado + días de devolución). El libro es solo de
 *   inserción, así que la fecha prevista vive aquí y la revisa un job cada hora.
 * - `contacts.points_expiring` / `points_expiring_at`: los próximos puntos por
 *   vencer, para el disparador por fecha del flujo 15 «Puntos por vencer».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_settlements', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26);
            $table->string('ref_type', 20);
            $table->string('ref_id', 60);
            $table->timestamp('due_at');
            $table->string('status', 12)->default('scheduled');   // scheduled | done | cancelled
            $table->timestamps();
            $table->unique(['workspace_id', 'ref_type', 'ref_id']);
            $table->index(['status', 'due_at']);
        });

        Schema::table('contacts', function (Blueprint $table) {
            $table->integer('points_expiring')->default(0)->after('points_balance');
            $table->date('points_expiring_at')->nullable()->after('points_expiring');
            $table->index(['workspace_id', 'points_expiring_at'], 'contacts_points_expiring_lookup');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropIndex('contacts_points_expiring_lookup');
            $table->dropColumn(['points_expiring', 'points_expiring_at']);
        });
        Schema::dropIfExists('loyalty_settlements');
    }
};
