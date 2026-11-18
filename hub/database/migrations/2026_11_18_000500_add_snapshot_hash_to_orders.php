<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hash de la última foto aplicada: si llega la misma foto otra vez (por
 * ejemplo order.created y order.paid de una importación), no se reescribe el
 * pedido ni sus ítems.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->char('snapshot_hash', 32)->nullable()->after('projected_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('snapshot_hash');
        });
    }
};
