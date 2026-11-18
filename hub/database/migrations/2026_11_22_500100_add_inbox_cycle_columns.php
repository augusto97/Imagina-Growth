<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * M11 Bandeja (fork B), sobre 2026_11_22_000100 (modelo-de-datos.md §14):
 * una conversación es única por contacto y número y se abre y cierra muchas
 * veces. `opened_at` es el inicio del ciclo actual (para la primera respuesta y
 * la resolución) y `awaiting_since` el primer entrante sin responder (para
 * ordenar por espera y medir el tiempo de respuesta).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->timestamp('opened_at')->nullable()->after('last_message_at');
            $table->timestamp('awaiting_since')->nullable()->after('opened_at');
            $table->index(['workspace_id', 'assignee_id', 'status'], 'conversations_assignee');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex('conversations_assignee');
            $table->dropColumn(['opened_at', 'awaiting_since']);
        });
    }
};
