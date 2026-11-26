<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Tamaño diario de cada grupo de clientes (segmento, modulos.md M04): la lista de grupos muestra la tendencia de los
 * últimos 30 días. Una fila por segmento y día, que escribe cada cálculo de las cifras (RecomputeSegment::refreshStats);
 * el último cálculo del día gana. Se borra con el segmento.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('segment_daily_counts', function (Blueprint $table) {
            $table->char('segment_id', 26);
            $table->date('day');
            $table->char('workspace_id', 26);
            $table->unsignedInteger('member_count')->default(0);
            $table->unsignedInteger('whatsapp_count')->default(0);
            $table->unsignedInteger('email_count')->default(0);
            $table->timestamp('updated_at')->nullable();
            $table->primary(['segment_id', 'day']);
            $table->index(['workspace_id', 'day']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('segment_daily_counts');
    }
};
