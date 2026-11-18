<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Fecha de renovación del contacto (flujo 20 de la biblioteca, M09): llega por
 * importación, por la API pública o desde el perfil. El disparador por fecha la
 * busca por espacio de trabajo y día.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->date('renewal_at')->nullable()->after('birthday');
            $table->index(['workspace_id', 'renewal_at']);
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropIndex(['workspace_id', 'renewal_at']);
            $table->dropColumn('renewal_at');
        });
    }
};
