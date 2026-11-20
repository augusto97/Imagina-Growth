<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Conexiones con un clic (docs/auditoria-2026-11.md §6, modelo-de-datos.md §16):
 * - oauth_connections (prefijo `oac`): una conexión OAuth por espacio de trabajo, proveedor y sujeto
 *   (`''` = el espacio de trabajo; `staff:{id}` = una persona de la agenda; `waba:{id}` = una cuenta de
 *   WhatsApp por Embedded Signup). `credentials` va cifrado (tokens); `assets`, las cuentas que la persona
 *   autorizó (solo IDs y nombres, sin secretos).
 * - oauth_states: el `state` de cada autorización, de un solo uso (se borra al usarlo), ligado a la persona
 *   y al espacio de trabajo, con el verificador de PKCE cifrado. La clave es el hash del nonce.
 * - ad_destinations.oauth_connection_id y channel_accounts.oauth_connection_id: el puente para que los
 *   destinos de anuncios, las audiencias y los canales usen la conexión en lugar de un token pegado a mano.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oauth_connections', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26);
            $table->string('provider', 24);                 // meta | meta_whatsapp | google | tiktok
            $table->string('subject', 64)->default('');     // '' | staff:{id} | waba:{id}
            $table->string('status', 16)->default('active'); // active | expired | revoked | error
            $table->string('external_id', 64)->nullable();  // usuario, negocio o anunciante en el proveedor
            $table->string('account_name', 190)->nullable();
            $table->json('scopes')->nullable();
            $table->text('credentials')->nullable();        // cifrado: access_token, refresh_token
            $table->json('assets')->nullable();
            $table->timestamp('expires_at')->nullable();    // del access token; null = no vence
            $table->timestamp('refreshed_at')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->char('connected_by', 26)->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->unique(['workspace_id', 'provider', 'subject']);
            $table->index(['status', 'checked_at']);
        });

        Schema::create('oauth_states', function (Blueprint $table) {
            $table->char('id', 64)->primary();              // sha256 del nonce
            $table->char('workspace_id', 26);
            $table->char('user_id', 26);
            $table->string('provider', 24);
            $table->text('payload');                         // cifrado: sujeto, uso, retorno, verificador PKCE
            $table->timestamp('expires_at');
            $table->index('expires_at');
        });

        Schema::table('ad_destinations', function (Blueprint $table) {
            $table->char('oauth_connection_id', 26)->nullable()->after('config');
            $table->index('oauth_connection_id');
        });
        Schema::table('channel_accounts', function (Blueprint $table) {
            $table->char('oauth_connection_id', 26)->nullable()->after('config');
            $table->index('oauth_connection_id');
        });
    }

    public function down(): void
    {
        Schema::table('channel_accounts', function (Blueprint $table) {
            $table->dropIndex(['oauth_connection_id']);
            $table->dropColumn('oauth_connection_id');
        });
        Schema::table('ad_destinations', function (Blueprint $table) {
            $table->dropIndex(['oauth_connection_id']);
            $table->dropColumn('oauth_connection_id');
        });
        Schema::dropIfExists('oauth_states');
        Schema::dropIfExists('oauth_connections');
    }
};
