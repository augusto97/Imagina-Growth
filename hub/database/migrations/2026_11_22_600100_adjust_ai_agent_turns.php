<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Agente de ventas (M11, fork G): los turnos de «Probar el agente» no tienen
 * conversación y se marcan como prueba; `handoff_reason` dice por qué pasó a un
 * humano y `cart_id` enlaza el carrito que armó (para las ventas atribuidas).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_agent_turns', function (Blueprint $table) {
            $table->char('conversation_id', 26)->nullable()->change();
            $table->boolean('is_test')->default(false)->after('mode');
            $table->string('handoff_reason', 64)->nullable()->after('status');
            $table->char('cart_id', 26)->nullable()->after('reply_message_id');
        });
    }

    public function down(): void
    {
        Schema::table('ai_agent_turns', function (Blueprint $table) {
            $table->dropColumn(['is_test', 'handoff_reason', 'cart_id']);
        });
    }
};
