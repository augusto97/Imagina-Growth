<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Auditoría F7: además del CSV, una «importación» puede ser la declaración del consentimiento del historial que
 * importó el plugin de un sitio (kind = history, con su site_id). Los registros que ya existen son CSV.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contact_imports', function (Blueprint $table) {
            $table->string('kind', 16)->default('csv')->after('user_id');
            $table->char('site_id', 26)->nullable()->after('kind');
        });
    }

    public function down(): void
    {
        Schema::table('contact_imports', function (Blueprint $table) {
            $table->dropColumn(['kind', 'site_id']);
        });
    }
};
