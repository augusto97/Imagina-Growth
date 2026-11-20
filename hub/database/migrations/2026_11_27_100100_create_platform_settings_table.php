<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Ajustes de la plataforma (F17, hub/CLAUDE.md §16): lo que antes se configuraba editando el .env del servidor (correo,
 * Amazon SES, las apps de Meta, Google y TikTok, la IA, el registro, la facturación, el monitoreo, la retención…) se
 * guarda aquí desde Plataforma → Ajustes. Una fila por clave del registro `PlatformSettingsRegistry`; los secretos van
 * cifrados con la llave de la app (`encrypted`) y nunca vuelven a la SPA. Sin fila, rige el .env.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_settings', function (Blueprint $table) {
            $table->string('key', 80)->primary();
            $table->text('value')->nullable();               // JSON; si `encrypted`, cifrado con Crypt::encryptString
            $table->boolean('encrypted')->default(false);
            $table->char('updated_by', 26)->nullable();      // users.id
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_settings');
    }
};
