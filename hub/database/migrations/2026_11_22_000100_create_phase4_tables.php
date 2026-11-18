<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Fase 4 (inteligencia avanzada e IA, bandeja y agente): modelo-de-datos.md §14.
 * Rango de los módulos: 2026_11_22_1xxxxx predicciones, 2xxxxx hallazgos, 3xxxxx
 * analista y «Pregúntale», 4xxxxx copiloto y contenido, 5xxxxx bandeja,
 * 6xxxxx agente de ventas.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── IA: costo, informes y borradores ────────────────────────────────────
        Schema::create('ai_usage', function (Blueprint $table) {
            $table->char('workspace_id', 26);
            $table->date('date');
            $table->string('feature', 32);
            $table->string('model', 48);
            $table->unsignedInteger('requests')->default(0);
            $table->unsignedBigInteger('tokens_in')->default(0);
            $table->unsignedBigInteger('tokens_out')->default(0);
            $table->unsignedBigInteger('cache_read')->default(0);
            $table->unsignedBigInteger('cache_write')->default(0);
            $table->decimal('cost_usd', 12, 6)->default(0);
            $table->primary(['workspace_id', 'date', 'feature', 'model']);
        });

        Schema::create('ai_reports', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26);
            $table->string('kind', 16);                     // weekly | monthly
            $table->date('period_start');
            $table->date('period_end');
            $table->json('facts');
            $table->text('body_markdown');
            $table->json('actions')->nullable();            // las 3 acciones con su enlace o «crear con 1 clic»
            $table->string('source', 12);                   // ai | fallback
            $table->string('model', 48)->nullable();
            $table->unsignedInteger('tokens_in')->default(0);
            $table->unsignedInteger('tokens_out')->default(0);
            $table->json('validation')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
            $table->unique(['workspace_id', 'kind', 'period_start']);
        });

        Schema::create('ai_drafts', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26);
            $table->char('user_id', 26)->nullable();
            $table->string('kind', 16);                     // campaign | flow | content
            $table->text('prompt');
            $table->json('proposal');
            $table->string('status', 16)->default('draft'); // draft | approved | applied | discarded
            $table->string('applied_type', 16)->nullable(); // campaign | flow | template
            $table->char('applied_id', 26)->nullable();
            $table->string('model', 48)->nullable();
            $table->json('validation')->nullable();
            $table->timestamps();
            $table->index(['workspace_id', 'kind', 'created_at']);
        });

        Schema::create('ask_data_queries', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26);
            $table->char('user_id', 26)->nullable();
            $table->text('question');
            $table->json('query')->nullable();              // el MetricQuery que armó el modelo
            $table->text('answer')->nullable();
            $table->json('result_meta')->nullable();        // filas, columnas, visual sugerido
            $table->string('status', 16);                   // answered | unsupported | failed
            $table->string('model', 48)->nullable();
            $table->json('validation')->nullable();
            $table->string('saved_as', 16)->nullable();     // segment | dashboard
            $table->char('saved_id', 26)->nullable();
            $table->timestamps();
            $table->index(['workspace_id', 'created_at']);
        });

        // ── Predicciones (M17) ──────────────────────────────────────────────────
        Schema::table('contacts', function (Blueprint $table) {
            $table->decimal('p_alive', 5, 4)->nullable()->after('churn_band');
            $table->decimal('expected_orders_90d', 8, 3)->nullable()->after('p_alive');
            $table->decimal('expected_orders_365d', 8, 3)->nullable()->after('expected_orders_90d');
            $table->decimal('propensity_30d', 5, 4)->nullable()->after('expected_orders_365d');
            $table->json('brand_affinity')->nullable()->after('category_affinity');
            $table->timestamp('predictions_at')->nullable()->after('propensity_30d');
        });

        Schema::create('product_transitions', function (Blueprint $table) {
            $table->id();
            $table->char('workspace_id', 26);
            $table->char('site_id', 26);
            $table->string('level', 12);                    // product | category
            $table->string('from_key', 64);
            $table->string('to_key', 64);
            $table->unsignedInteger('support');             // clientes con esa transición
            $table->decimal('probability', 6, 5);           // P(siguiente = to | anterior = from)
            $table->timestamp('computed_at');
            $table->index(['workspace_id', 'site_id', 'level', 'from_key'], 'product_transitions_lookup');
        });

        // ── Bandeja (M11) ───────────────────────────────────────────────────────
        Schema::table('conversations', function (Blueprint $table) {
            $table->unsignedSmallInteger('unread_count')->default(0)->after('status');
            $table->timestamp('last_message_at')->nullable()->after('last_outbound_at');
            $table->timestamp('resolved_at')->nullable()->after('first_response_seconds');
            $table->string('intent', 16)->nullable()->after('labels');       // la del último entrante
            $table->string('handoff_reason', 64)->nullable()->after('ai_mode');
            $table->index(['workspace_id', 'status', 'last_message_at'], 'conversations_inbox');
        });

        Schema::create('conversation_notes', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26);
            $table->char('conversation_id', 26);
            $table->char('user_id', 26)->nullable();
            $table->text('body');                           // cifrado
            $table->timestamps();
            $table->index(['conversation_id', 'created_at']);
        });

        Schema::create('conversation_events', function (Blueprint $table) {
            $table->id();
            $table->char('workspace_id', 26);
            $table->char('conversation_id', 26);
            $table->string('kind', 24);                     // assigned | status | label | ai_mode | handoff | sale
            $table->string('actor', 32);                    // id de usuario | ai | system
            $table->json('data')->nullable();
            $table->timestamp('created_at');
            $table->index(['conversation_id', 'created_at']);
            $table->index(['workspace_id', 'kind', 'created_at']);
        });

        Schema::create('quick_replies', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26);
            $table->string('shortcut', 32);
            $table->string('title', 120);
            $table->text('body');
            $table->char('created_by', 26)->nullable();
            $table->timestamps();
            $table->unique(['workspace_id', 'shortcut']);
        });

        Schema::create('message_attachments', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26);
            $table->char('message_id', 26);
            $table->string('kind', 16);                     // image | video | audio | document | sticker | location | product
            $table->string('mime', 100)->nullable();
            $table->unsignedInteger('size')->nullable();
            $table->string('disk_path', 255)->nullable();
            $table->string('provider_media_id', 128)->nullable();
            $table->text('caption')->nullable();            // cifrado
            $table->timestamp('created_at');
            $table->index('message_id');
        });

        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26);
            $table->char('user_id', 26);
            $table->text('endpoint');                       // cifrado
            $table->char('endpoint_hash', 64);
            $table->text('keys');                           // cifrado (p256dh, auth)
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'endpoint_hash']);
        });

        // ── Agente de ventas con IA (M11, inteligencia.md §6.7) ─────────────────
        Schema::create('ai_agent_settings', function (Blueprint $table) {
            $table->char('workspace_id', 26)->primary();
            $table->boolean('enabled')->default(false);
            $table->string('default_mode', 8)->default('off');   // off | assist | auto
            $table->text('scope')->nullable();              // de qué se puede hablar (el negocio)
            $table->string('tone', 255)->nullable();
            $table->json('policies')->nullable();           // envíos, cambios, medios de pago, horarios, otras
            $table->json('handoff_rules')->nullable();      // palabras, intenciones, montos, fuera de horario
            $table->json('discount_rules')->nullable();     // qué cupón puede ofrecer y cuándo (nunca otro)
            $table->unsignedTinyInteger('max_steps')->default(6);
            $table->char('updated_by', 26)->nullable();
            $table->timestamps();
        });

        Schema::create('ai_agent_turns', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26);
            $table->char('conversation_id', 26);
            $table->char('inbound_message_id', 26)->nullable();
            $table->string('mode', 8);                      // assist | auto
            $table->string('status', 16);                   // replied | suggested | handoff | blocked | failed
            $table->char('reply_message_id', 26)->nullable();
            $table->text('suggestion')->nullable();         // cifrado
            $table->json('tool_calls')->nullable();         // nombre, entrada y salida resumida
            $table->json('validation')->nullable();         // cifras de la respuesta frente a lo que devolvieron las herramientas
            $table->string('model', 48)->nullable();
            $table->unsignedInteger('tokens_in')->default(0);
            $table->unsignedInteger('tokens_out')->default(0);
            $table->unsignedInteger('latency_ms')->nullable();
            $table->boolean('flagged')->default(false);
            $table->string('review_status', 16)->nullable(); // ok | wrong | needs_fix
            $table->char('reviewed_by', 26)->nullable();
            $table->timestamps();
            $table->index(['workspace_id', 'created_at']);
            $table->index(['conversation_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_agent_turns');
        Schema::dropIfExists('ai_agent_settings');
        Schema::dropIfExists('push_subscriptions');
        Schema::dropIfExists('message_attachments');
        Schema::dropIfExists('quick_replies');
        Schema::dropIfExists('conversation_events');
        Schema::dropIfExists('conversation_notes');
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex('conversations_inbox');
            $table->dropColumn(['unread_count', 'last_message_at', 'resolved_at', 'intent', 'handoff_reason']);
        });
        Schema::dropIfExists('product_transitions');
        Schema::table('contacts', fn (Blueprint $table) => $table->dropColumn(['p_alive', 'expected_orders_90d', 'expected_orders_365d', 'propensity_30d', 'brand_affinity', 'predictions_at']));
        Schema::dropIfExists('ask_data_queries');
        Schema::dropIfExists('ai_drafts');
        Schema::dropIfExists('ai_reports');
        Schema::dropIfExists('ai_usage');
    }
};
