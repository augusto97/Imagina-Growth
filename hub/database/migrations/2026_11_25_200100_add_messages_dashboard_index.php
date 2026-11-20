<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Tablero de mensajería (docs/auditoria-2026-11.md F11): los mensajes enviados de un espacio de trabajo en un rango
 * de fechas, sin recorrer todos los mensajes del espacio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->index(['workspace_id', 'direction', 'created_at'], 'messages_ws_direction_created_index');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex('messages_ws_direction_created_index');
        });
    }
};
