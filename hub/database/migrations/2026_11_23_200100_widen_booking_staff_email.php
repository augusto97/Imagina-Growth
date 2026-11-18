<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * El correo de la persona de la agenda va cifrado (como el de los contactos): el
 * texto cifrado de un correo largo no cabe en 255 caracteres.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_staff', function (Blueprint $table) {
            $table->text('email')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('booking_staff', function (Blueprint $table) {
            $table->string('email', 255)->nullable()->change();
        });
    }
};
