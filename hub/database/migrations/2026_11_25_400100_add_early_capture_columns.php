<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Captura temprana del checkout (eventos.md §4.2, cumplimiento.md §3.2):
 * - `carts.contact_captured_at`: cuándo el plugin unió el carrito con el correo o teléfono que la persona escribió.
 * - `contacts.early_capture_at`: el contacto existe solo por esa captura. Su dato sirve para recuperar ese carrito:
 *   no entra en las audiencias de anuncios ni suma puntos. Se borra cuando la persona compra, se registra o deja
 *   un formulario.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('carts', function (Blueprint $table) {
            $table->timestamp('contact_captured_at')->nullable();
        });
        Schema::table('contacts', function (Blueprint $table) {
            $table->timestamp('early_capture_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn('early_capture_at');
        });
        Schema::table('carts', function (Blueprint $table) {
            $table->dropColumn('contact_captured_at');
        });
    }
};
