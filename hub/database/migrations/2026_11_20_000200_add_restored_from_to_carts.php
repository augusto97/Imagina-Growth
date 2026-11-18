<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El enlace de carrito restaurable (api-connector.md §6.1) arma un carrito nuevo
 * en el sitio, con otro cart_token. `restored_from_id` lo une al carrito
 * abandonado para marcar ese como recuperado cuando llega el pedido.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('carts', function (Blueprint $table) {
            $table->char('restored_from_id', 26)->nullable()->after('recovered_order_id');
        });
    }

    public function down(): void
    {
        Schema::table('carts', function (Blueprint $table) {
            $table->dropColumn('restored_from_id');
        });
    }
};
