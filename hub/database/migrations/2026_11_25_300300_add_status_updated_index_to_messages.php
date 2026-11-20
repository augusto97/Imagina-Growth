<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * F9: `igr:messages:stuck` busca cada 5 minutos los mensajes en `sending` por antigüedad, en todos los espacios de
 * trabajo. Sin este índice recorría la tabla entera.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->index(['status', 'updated_at'], 'messages_status_updated_index');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex('messages_status_updated_index');
        });
    }
};
