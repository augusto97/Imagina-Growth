<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * M15 (reseñas, NPS y prueba social), sobre las tablas de 2026_11_21_000100
 * (modelo-de-datos.md §13):
 * - reviews: la solicitud de origen, publicación, motivo del rechazo, la
 *   respuesta del dueño en WooCommerce, el estado de la sincronización con la
 *   tienda y la clave de importación (CSV y Judge.me no se duplican).
 * - review_requests: sitio, motivo de omisión, intentos, vencimiento del enlace
 *   y la clase legal del mensaje (marketing por defecto, cumplimiento.md §4.1).
 * - survey_requests (nueva): los envíos de NPS y CSAT por pedido.
 * - survey_responses.comment: el comentario libre del NPS, cifrado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->char('request_id', 26)->nullable()->after('order_id');
            $table->timestamp('published_at')->nullable()->after('moderated_at');
            $table->string('reject_reason', 24)->nullable()->after('published_at');
            $table->string('external_reply_id', 40)->nullable()->after('replied_at');
            $table->string('sync_status', 16)->nullable()->after('external_reply_id'); // pending | synced | failed | skipped
            $table->string('sync_error', 300)->nullable()->after('sync_status');
            $table->string('import_key', 64)->nullable()->after('sync_error');
            $table->index(['workspace_id', 'contact_id'], 'reviews_contact_lookup');
            $table->index(['workspace_id', 'order_id'], 'reviews_order_lookup');
            $table->index(['workspace_id', 'site_id', 'external_product_id', 'status', 'created_at'], 'reviews_public_list');
            $table->unique(['workspace_id', 'import_key'], 'reviews_import_key_unique');
        });

        Schema::table('review_requests', function (Blueprint $table) {
            $table->char('site_id', 26)->nullable()->after('workspace_id');
            $table->string('kind', 16)->default('marketing')->after('channel');
            $table->string('skip_reason', 40)->nullable()->after('status');
            $table->unsignedSmallInteger('attempts')->default(0)->after('skip_reason');
            $table->timestamp('expires_at')->nullable()->after('completed_at');
        });

        Schema::create('survey_requests', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26);
            $table->char('survey_id', 26);
            $table->char('site_id', 26)->nullable();
            $table->char('contact_id', 26);
            $table->char('order_id', 26)->nullable();
            $table->string('channel', 16)->nullable();
            $table->string('status', 16)->default('scheduled');   // scheduled | sent | answered | skipped | expired
            $table->string('skip_reason', 40)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->char('message_id', 26)->nullable();
            $table->timestamp('scheduled_for')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->unique(['survey_id', 'order_id']);
            $table->index(['workspace_id', 'status', 'scheduled_for']);
            $table->index(['workspace_id', 'contact_id']);
        });

        Schema::table('survey_responses', function (Blueprint $table) {
            $table->text('comment')->nullable()->after('score');   // cifrado: puede tener datos personales
        });
    }

    public function down(): void
    {
        Schema::table('survey_responses', function (Blueprint $table) {
            $table->dropColumn('comment');
        });
        Schema::dropIfExists('survey_requests');
        Schema::table('review_requests', function (Blueprint $table) {
            $table->dropColumn(['site_id', 'kind', 'skip_reason', 'attempts', 'expires_at']);
        });
        Schema::table('reviews', function (Blueprint $table) {
            $table->dropUnique('reviews_import_key_unique');
            $table->dropIndex('reviews_public_list');
            $table->dropIndex('reviews_order_lookup');
            $table->dropIndex('reviews_contact_lookup');
            $table->dropColumn(['request_id', 'published_at', 'reject_reason', 'external_reply_id', 'sync_status', 'sync_error', 'import_key']);
        });
    }
};
