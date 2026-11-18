<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * M19 API pública, webhooks y MCP (fork P), sobre 2026_11_23_000100 (modelo-de-datos.md §15):
 * - api_clients.token_prefix: los primeros caracteres del token, para reconocerlo en el panel
 *   sin guardarlo (Sanctum guarda solo el hash); preset: la integración del ecosistema que lo creó.
 * - webhook_subscriptions: nombre, si incluye datos personales (alcance contacts:pii) y la
 *   integración del ecosistema que la creó (preset).
 * - webhook_deliveries.payload: el cuerpo enviado, cifrado, para reenviarlo desde el registro;
 *   kind: event (primer intento), retry, replay (reenvío manual) o ping (prueba).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_clients', function (Blueprint $table) {
            $table->string('token_prefix', 16)->nullable()->after('scopes');
            $table->string('preset', 24)->nullable()->after('kind');
        });
        Schema::table('webhook_subscriptions', function (Blueprint $table) {
            $table->string('name', 120)->nullable()->after('workspace_id');
            $table->boolean('include_pii')->default(false)->after('events');
            $table->string('preset', 24)->nullable()->after('include_pii');
        });
        Schema::table('webhook_deliveries', function (Blueprint $table) {
            $table->longText('payload')->nullable()->after('event_id');
            $table->string('kind', 8)->default('event')->after('attempt');
        });
    }

    public function down(): void
    {
        Schema::table('webhook_deliveries', fn (Blueprint $table) => $table->dropColumn(['payload', 'kind']));
        Schema::table('webhook_subscriptions', fn (Blueprint $table) => $table->dropColumn(['name', 'include_pii', 'preset']));
        Schema::table('api_clients', fn (Blueprint $table) => $table->dropColumn(['token_prefix', 'preset']));
    }
};
