<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** ID público del píxel (no es secreto: va en el HTML del sitio) para el modo «completo» de M06. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ad_destinations', function (Blueprint $table) {
            $table->string('pixel_id', 64)->nullable()->after('mode');
        });
    }

    public function down(): void
    {
        Schema::table('ad_destinations', function (Blueprint $table) {
            $table->dropColumn('pixel_id');
        });
    }
};
