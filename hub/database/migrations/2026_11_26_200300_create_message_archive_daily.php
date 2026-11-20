<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Archivo agregado de mensajes (R14, cumplimiento.md §10: los mensajes se guardan 24 meses y después queda el archivo
 * agregado). Antes de borrar un lote de `messages`, `igr:retention:purge` suma sus conteos y su costo aquí, por día
 * (UTC), canal, dirección, categoría, origen, flujo y campaña: sin contacto, destino ni contenido. El tablero de
 * Mensajería y los informes de campañas y flujos lo suman a lo que sigue en `messages`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_archive_daily', function (Blueprint $table) {
            $table->id();
            $table->char('workspace_id', 26);
            $table->date('date');
            $table->string('channel', 16);
            $table->string('direction', 3);
            $table->string('kind', 16);
            $table->string('source_type', 16);
            $table->char('flow_id', 26)->default('');      // '' sin flujo (la clave única no admite NULL repetidos)
            $table->char('campaign_id', 26)->default('');
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('sent')->default(0);
            $table->unsignedInteger('delivered')->default(0);
            $table->unsignedInteger('read_count')->default(0);
            $table->unsignedInteger('opened')->default(0);
            $table->unsignedInteger('clicked')->default(0);
            $table->unsignedInteger('replied')->default(0);
            $table->unsignedInteger('bounced')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->decimal('cost_usd', 14, 5)->default(0);   // lo que cobra el proveedor (MessageCosts::BILLABLE), en USD
            $table->timestamps();
            $table->unique(['workspace_id', 'date', 'channel', 'direction', 'kind', 'source_type', 'flow_id', 'campaign_id'], 'message_archive_daily_key');
            $table->index(['workspace_id', 'campaign_id']);
            $table->index(['workspace_id', 'flow_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_archive_daily');
    }
};
