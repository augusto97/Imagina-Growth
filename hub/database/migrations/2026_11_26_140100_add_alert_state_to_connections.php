<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Avisos de conexiones por vencer o caídas (lote 4, docs/auditoria-2026-11.md §6, modelo-de-datos.md §16):
 * `alert_state` guarda el último estado avisado por correo a dueños y administradores (`expires_7d`, `expires_1d`,
 * `expired` en las conexiones; `disconnected` en las cuentas de canal que Meta desconectó) y `alert_sent_at` cuándo.
 * Así cada cambio de estado se avisa una sola vez; cuando la conexión vuelve a estar bien se limpia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('oauth_connections', function (Blueprint $table) {
            $table->string('alert_state', 24)->nullable()->after('last_error');
            $table->timestamp('alert_sent_at')->nullable()->after('alert_state');
        });
        Schema::table('channel_accounts', function (Blueprint $table) {
            $table->string('alert_state', 24)->nullable()->after('last_error');
            $table->timestamp('alert_sent_at')->nullable()->after('alert_state');
            $table->index(['workspace_id', 'alert_state']);
        });
    }

    public function down(): void
    {
        Schema::table('channel_accounts', function (Blueprint $table) {
            $table->dropIndex(['workspace_id', 'alert_state']);
            $table->dropColumn(['alert_state', 'alert_sent_at']);
        });
        Schema::table('oauth_connections', function (Blueprint $table) {
            $table->dropColumn(['alert_state', 'alert_sent_at']);
        });
    }
};
