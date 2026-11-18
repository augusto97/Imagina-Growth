<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M12, atribución de captación (hub/CLAUDE.md §9, paso 4): el widget (y su variante)
 * que capturó al contacto en los 7 días antes del pedido. Lo llena WidgetAttributor
 * desde AttributeOrder y lo usan las estadísticas del widget.
 *
 * `widget_captures.updated_at`: el último paso del formulario en 2 pasos o el último
 * reenvío (captured_at queda como la primera captura).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->char('attributed_widget_id', 26)->nullable()->after('attributed_affiliate_id');
            $table->string('attributed_widget_variant', 8)->nullable()->after('attributed_widget_id');
            $table->index(['workspace_id', 'attributed_widget_id'], 'orders_attributed_widget');
        });
        Schema::table('widget_captures', function (Blueprint $table) {
            $table->timestamp('updated_at')->nullable()->after('captured_at');
            $table->index(['widget_id', 'captured_at'], 'widget_captures_widget_time');
        });
    }

    public function down(): void
    {
        Schema::table('widget_captures', function (Blueprint $table) {
            $table->dropIndex('widget_captures_widget_time');
            $table->dropColumn('updated_at');
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_attributed_widget');
            $table->dropColumn(['attributed_widget_id', 'attributed_widget_variant']);
        });
    }
};
