<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M07: los webhooks de los proveedores llegan sin espacio de trabajo y la cuenta
 * se busca por su ID en el proveedor (phone_number_id de Meta, identidad de SES,
 * dispositivo de Zender, número de Twilio): `external_id`, no secreto. El
 * contenido renderizado de un correo puede pasar de 64 KB: `payload` va en
 * LONGTEXT (sigue cifrado).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channel_accounts', function (Blueprint $table) {
            $table->string('external_id', 190)->nullable()->after('identity');
            $table->timestamp('warmup_started_at')->nullable()->after('messaging_limit_tier');
            $table->index(['provider', 'external_id']);
        });
        Schema::table('messages', function (Blueprint $table) {
            $table->longText('payload')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('channel_accounts', function (Blueprint $table) {
            $table->dropIndex(['provider', 'external_id']);
            $table->dropColumn(['external_id', 'warmup_started_at']);
        });
        Schema::table('messages', function (Blueprint $table) {
            $table->text('payload')->nullable()->change();
        });
    }
};
