<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Índices para las pantallas principales (docs/rendimiento.md §2), medidos con la tienda demo (2.000 clientes, 10.000
 * pedidos) y 33.600 mensajes. En MariaDB 11 se crean en línea (InnoDB, sin bloquear escrituras).
 *
 * - Bandeja y agente de ventas: los mensajes de una conversación (la lista, el chat, el panel, el contexto de la IA).
 *   Sin índice cada consulta recorría todos los mensajes del espacio de trabajo: 130 ms por conversación y 4,3 s la
 *   lista de 30.
 * - Reporte de campaña, analítica de un flujo y tablero de mensajería por flujo o campaña: los mensajes y los pedidos
 *   atribuidos a un flujo o una campaña.
 * - Tableros de clientes y canales (cohortes, valor a 90 y 365 días): el primer pedido de cada contacto sale del índice
 *   sin leer la fila (310 → 75 ms y 240 → 40 ms). Reemplaza a (workspace_id, contact_id), que es su prefijo.
 * - Lista de contactos: el orden por defecto (última visita) sin ordenar todos los contactos del espacio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->index(['workspace_id', 'conversation_id', 'created_at'], 'messages_ws_conversation_created');
            $table->index(['workspace_id', 'campaign_id', 'created_at'], 'messages_ws_campaign_created');
            $table->index(['workspace_id', 'flow_id', 'created_at'], 'messages_ws_flow_created');
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->index(['workspace_id', 'attributed_flow_id'], 'orders_ws_attributed_flow');
            $table->index(['workspace_id', 'attributed_campaign_id'], 'orders_ws_attributed_campaign');
            $table->index(['workspace_id', 'contact_id', 'is_first_order', 'created_at'], 'orders_ws_contact_first_created');
        });
        Schema::table('orders', fn (Blueprint $table) => $table->dropIndex('orders_workspace_id_contact_id_index'));
        Schema::table('contacts', function (Blueprint $table) {
            $table->index(['workspace_id', 'last_seen_at'], 'contacts_ws_last_seen');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', fn (Blueprint $table) => $table->dropIndex('contacts_ws_last_seen'));
        Schema::table('orders', fn (Blueprint $table) => $table->index(['workspace_id', 'contact_id'], 'orders_workspace_id_contact_id_index'));
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_ws_contact_first_created');
            $table->dropIndex('orders_ws_attributed_campaign');
            $table->dropIndex('orders_ws_attributed_flow');
        });
        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex('messages_ws_flow_created');
            $table->dropIndex('messages_ws_campaign_created');
            $table->dropIndex('messages_ws_conversation_created');
        });
    }
};
