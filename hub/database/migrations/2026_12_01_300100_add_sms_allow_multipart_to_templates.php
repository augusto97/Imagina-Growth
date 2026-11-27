<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * SMS en un solo mensaje (modulos.md M08): por defecto una plantilla de SMS no puede pasar de un mensaje en el peor
 * caso, ni al guardarla ni al enviarla. El dueño puede permitir mensajes de 2 partes (cuestan el doble).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('templates', function (Blueprint $table) {
            $table->boolean('sms_allow_multipart')->default(false)->after('body_text');
        });
    }

    public function down(): void
    {
        Schema::table('templates', function (Blueprint $table) {
            $table->dropColumn('sms_allow_multipart');
        });
    }
};
