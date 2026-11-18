<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tablas de la Fase 2 (mensajería y automatizaciones), según modelo-de-datos.md
 * §3 a §5. Las diferencias con el documento están en su §12.
 *
 * `messages` y `flow_run_steps` no se particionan por mes todavía: MariaDB exige
 * que la clave de partición esté en todas las claves únicas y aquí son ULID. La
 * purga a los 6 y 13 meses la hace un job (modelo-de-datos.md §12).
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Comercio ────────────────────────────────────────────────────────
        Schema::create('carts', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26);
            $table->char('site_id', 26);
            $table->string('token', 64);                       // cart_token del connector
            $table->char('contact_id', 26)->nullable();
            $table->string('anonymous_id', 64)->nullable();
            $table->json('items')->nullable();
            $table->unsignedInteger('items_count')->default(0);
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('subtotal_ws', 14, 2)->default(0);
            $table->char('currency', 3)->nullable();
            $table->json('coupons')->nullable();
            $table->string('status', 16)->default('active');   // active | abandoned | recovered | converted | expired
            $table->timestamp('checkout_started_at')->nullable();
            $table->timestamp('abandoned_at')->nullable();
            $table->timestamp('last_activity_at');
            $table->string('restore_url_token', 64)->nullable();
            $table->char('order_id', 26)->nullable();          // pedido que lo cerró (convertido o recuperado)
            $table->char('recovered_order_id', 26)->nullable(); // solo si se recuperó tras abandonarse
            $table->timestamps();
            $table->unique(['site_id', 'token']);
            $table->index(['workspace_id', 'status', 'last_activity_at']);
            $table->index(['workspace_id', 'contact_id', 'status']);
            $table->index(['workspace_id', 'anonymous_id']);
        });

        Schema::create('coupons_issued', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26);
            $table->char('site_id', 26);
            $table->string('code', 64);
            $table->char('contact_id', 26)->nullable();
            $table->string('source_type', 16);                 // flow | campaign | loyalty | referral | widget | wallet
            $table->string('source_id', 40)->nullable();
            $table->string('kind', 16);                        // percent | fixed_cart | fixed_product | free_shipping
            $table->decimal('amount', 14, 2)->default(0);
            $table->json('rules')->nullable();                 // mínimo, productos, categorías, uso individual
            $table->timestamp('expires_at')->nullable();
            $table->string('external_coupon_id', 40)->nullable();
            $table->char('redeemed_order_id', 26)->nullable();
            $table->timestamp('redeemed_at')->nullable();
            $table->string('status', 16)->default('pending');  // pending | active | redeemed | expired | disabled | failed
            $table->string('last_error', 500)->nullable();
            $table->timestamps();
            $table->unique(['site_id', 'code']);
            $table->index(['workspace_id', 'contact_id']);
            $table->index(['workspace_id', 'source_type', 'source_id']);
        });

        // ── Canales y contenido ─────────────────────────────────────────────
        Schema::create('channel_accounts', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26);
            $table->string('channel', 16);                     // email | whatsapp | sms
            $table->string('provider', 16);                    // ses | smtp | meta_cloud | zender | infobip | twilio
            $table->string('display_name', 120);
            $table->text('config')->nullable();                // cifrado: tokens, waba_id, phone_number_id, dominio, DKIM
            $table->string('identity', 190)->nullable();        // dominio o número visible (no secreto)
            $table->string('quality_rating', 16)->nullable();  // GREEN | YELLOW | RED (WhatsApp)
            $table->string('messaging_limit_tier', 24)->nullable();
            $table->boolean('is_default')->default(false);
            $table->string('status', 16)->default('pending');  // pending | active | paused | error | disconnected
            $table->string('last_error', 500)->nullable();
            $table->timestamp('risk_accepted_at')->nullable(); // Zender: el owner aceptó el aviso (cumplimiento.md §5.2)
            $table->char('risk_accepted_by', 26)->nullable();
            $table->timestamps();
            $table->index(['workspace_id', 'channel', 'status']);
        });

        Schema::create('templates', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26);
            $table->string('channel', 16);                     // email | whatsapp | sms
            $table->string('name', 160);
            $table->string('category', 16)->default('marketing'); // marketing | utility | transactional
            $table->string('subject', 250)->nullable();
            $table->string('preheader', 250)->nullable();
            $table->longText('body_mjml')->nullable();
            $table->longText('body_html')->nullable();
            $table->text('body_text')->nullable();
            $table->longText('editor_state')->nullable();      // proyecto de GrapesJS para volver a editar
            $table->char('wa_template_id', 26)->nullable();
            $table->json('variables')->nullable();             // mapeo de variables de WhatsApp → Liquid
            $table->string('library_key', 64)->nullable();     // plantilla de la biblioteca de la que salió
            $table->string('status', 16)->default('draft');    // draft | ready | archived
            $table->char('created_by', 26)->nullable();
            $table->timestamps();
            $table->index(['workspace_id', 'channel', 'status']);
        });

        Schema::create('wa_templates', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26);
            $table->char('channel_account_id', 26);
            $table->string('name', 512);
            $table->string('language', 16);
            $table->string('category', 16);                    // MARKETING | UTILITY | AUTHENTICATION
            $table->string('status', 16);                      // APPROVED | PENDING | REJECTED | PAUSED | DISABLED
            $table->string('quality', 16)->nullable();
            $table->json('components');
            $table->string('meta_template_id', 40)->nullable();
            $table->string('rejected_reason', 500)->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
            $table->unique(['channel_account_id', 'name', 'language']);
            $table->index(['workspace_id', 'status']);
        });

        Schema::create('wa_rates', function (Blueprint $table) {
            $table->char('country', 2);
            $table->string('category', 16);                    // marketing | utility | authentication | service
            $table->decimal('rate_usd', 10, 5);
            $table->date('effective_from');
            $table->string('source_url', 500)->nullable();
            $table->primary(['country', 'category', 'effective_from']);
        });

        // ── Mensajes ────────────────────────────────────────────────────────
        Schema::create('messages', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26);
            $table->char('contact_id', 26)->nullable();        // null: aviso al equipo
            $table->char('channel_account_id', 26)->nullable();
            $table->string('channel', 16);
            $table->string('provider', 16)->nullable();
            $table->string('provider_message_id', 190)->nullable();
            $table->string('direction', 3)->default('out');    // out | in
            $table->string('kind', 16);                        // marketing | utility | service | authentication | transactional
            $table->string('source_type', 16);                 // flow | campaign | inbox | api | system
            $table->string('source_id', 40)->nullable();
            $table->char('flow_id', 26)->nullable();
            $table->char('campaign_id', 26)->nullable();
            $table->char('template_id', 26)->nullable();
            $table->string('idempotency_key', 190)->nullable(); // run:nodo:intento o campaña:contacto: un reintento nunca reenvía
            $table->string('status', 16)->default('queued');   // deferred | queued | sending | sent | delivered | read | failed | bounced | denied | skipped
            $table->string('policy_reason', 64)->nullable();   // por qué se aplazó o negó (SendPolicy)
            $table->timestamp('deferred_until')->nullable();
            $table->decimal('cost_estimated', 10, 5)->nullable();
            $table->char('cost_currency', 3)->nullable();
            $table->text('payload')->nullable();               // cifrado: asunto y vista previa renderizada (auditoría)
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('clicked_at')->nullable();
            $table->timestamp('replied_at')->nullable();
            $table->string('failed_reason', 500)->nullable();
            $table->string('error_code', 40)->nullable();
            $table->char('conversation_id', 26)->nullable();
            $table->timestamps();
            $table->unique(['workspace_id', 'idempotency_key']);
            $table->index(['workspace_id', 'contact_id', 'created_at']);
            $table->index(['workspace_id', 'source_type', 'source_id']);
            $table->index(['workspace_id', 'status', 'deferred_until']);
            $table->index('provider_message_id');
        });

        Schema::create('tracked_links', function (Blueprint $table) {
            $table->string('code', 16)->primary();             // 8+ caracteres aleatorios, no secuenciales
            $table->char('workspace_id', 26);
            $table->char('message_id', 26);
            $table->char('contact_id', 26)->nullable();
            $table->text('url');
            $table->unsignedInteger('clicks')->default(0);
            $table->timestamp('first_clicked_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['workspace_id', 'message_id']);
        });

        Schema::create('conversations', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26);
            $table->char('contact_id', 26);
            $table->char('channel_account_id', 26);
            $table->string('status', 16)->default('open');     // open | pending | closed
            $table->char('assignee_id', 26)->nullable();
            $table->json('labels')->nullable();
            $table->string('ai_mode', 8)->default('off');      // off | assist | auto (Fase 4)
            $table->timestamp('window_expires_at')->nullable(); // ventana de 24 h de WhatsApp
            $table->timestamp('last_inbound_at')->nullable();
            $table->timestamp('last_outbound_at')->nullable();
            $table->unsignedInteger('first_response_seconds')->nullable();
            $table->timestamps();
            $table->unique(['channel_account_id', 'contact_id']);
            $table->index(['workspace_id', 'status']);
        });

        // ── Flujos ──────────────────────────────────────────────────────────
        Schema::create('flows', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26);
            $table->string('name', 160);
            $table->string('description', 500)->nullable();
            $table->string('status', 16)->default('draft');    // draft | active | paused | archived
            $table->json('trigger');                           // {type: event|segment|date|threshold|manual|webhook, …, filters}
            $table->char('current_version_id', 26)->nullable();
            $table->string('template_key', 64)->nullable();    // flujo de la biblioteca del que salió
            $table->json('goal')->nullable();
            $table->json('reentry_policy')->nullable();        // {mode: once|every_days|always, days}
            $table->json('exclusions')->nullable();            // flujos excluyentes
            $table->unsignedTinyInteger('holdout_pct')->default(0);
            $table->json('test_recipients')->nullable();       // modo prueba: a quién van los mensajes
            $table->json('stats')->nullable();
            $table->char('created_by', 26)->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamps();
            $table->index(['workspace_id', 'status']);
        });

        Schema::create('flow_versions', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26);
            $table->char('flow_id', 26);
            $table->unsignedInteger('version');
            $table->json('graph');                             // {nodes: [{id, type, config}], edges: [{from, to, branch?}]}
            $table->timestamp('published_at')->nullable();
            $table->char('published_by', 26)->nullable();
            $table->timestamps();
            $table->unique(['flow_id', 'version']);
        });

        Schema::create('flow_runs', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26);
            $table->char('flow_id', 26);
            $table->char('flow_version_id', 26);
            $table->char('contact_id', 26);
            $table->string('status', 16)->default('active');   // active | waiting | completed | exited | failed
            $table->string('current_node_id', 64)->nullable();
            $table->timestamp('next_run_at')->nullable();
            $table->json('context')->nullable();               // datos del disparador (carrito, pedido…)
            $table->string('trigger_key', 190)->nullable();    // evento que lo disparó: el mismo nunca crea dos runs
            $table->string('waiting_for', 64)->nullable();     // evento esperado por un nodo «esperar hasta»
            $table->boolean('is_holdout')->default(false);
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->string('exit_reason', 32)->nullable();
            $table->timestamps();
            $table->unique(['flow_id', 'trigger_key']);
            $table->index(['status', 'next_run_at']);
            $table->index(['workspace_id', 'flow_id', 'contact_id']);
            $table->index(['workspace_id', 'contact_id', 'status']);
        });

        Schema::create('flow_run_steps', function (Blueprint $table) {
            $table->id();
            $table->char('workspace_id', 26);
            $table->char('run_id', 26);
            $table->string('node_id', 64);
            $table->unsignedSmallInteger('attempt')->default(1); // intento lógico (un reintento del job no lo sube)
            $table->string('outcome', 24);                     // continue | wait | wait_event | exit | deferred | sent | skipped_holdout | failed
            $table->json('details')->nullable();
            $table->timestamp('executed_at');
            $table->unique(['run_id', 'node_id', 'attempt']);
            $table->index(['workspace_id', 'executed_at']);
        });

        // ── Campañas ────────────────────────────────────────────────────────
        Schema::create('campaigns', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26);
            $table->string('name', 160);
            $table->string('channel', 16);
            $table->string('status', 16)->default('draft');    // draft | scheduled | sending | sent | cancelled
            $table->json('audience');                          // segmentos incluidos y excluidos, reglas
            $table->char('template_id', 26)->nullable();
            $table->json('variants')->nullable();              // A/B: [{key, template_id, weight}]
            $table->json('ab_config')->nullable();             // {sample_pct, metric: clicks|revenue, wait_hours}
            $table->string('winner_variant', 8)->nullable();
            $table->unsignedTinyInteger('holdout_pct')->default(0);
            $table->timestamp('send_at')->nullable();
            $table->string('send_mode', 20)->default('now');   // now | scheduled | contact_timezone | best_time
            $table->json('cost_estimate')->nullable();
            $table->json('compliance_check')->nullable();
            $table->json('stats')->nullable();
            $table->char('created_by', 26)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['workspace_id', 'status', 'send_at']);
        });

        Schema::create('campaign_recipients', function (Blueprint $table) {
            $table->id();
            $table->char('workspace_id', 26);
            $table->char('campaign_id', 26);
            $table->char('contact_id', 26);
            $table->string('variant', 8)->nullable();
            $table->boolean('is_holdout')->default(false);
            $table->string('status', 16)->default('pending');  // pending | queued | deferred | sent | skipped | denied | failed
            $table->string('reason', 64)->nullable();
            $table->timestamp('scheduled_for')->nullable();
            $table->char('message_id', 26)->nullable();
            $table->timestamps();
            $table->unique(['campaign_id', 'contact_id']);
            $table->index(['campaign_id', 'status', 'scheduled_for']);
        });

        // ── Cumplimiento ────────────────────────────────────────────────────
        Schema::create('contact_preferences', function (Blueprint $table) {
            $table->char('contact_id', 26)->primary();
            $table->char('workspace_id', 26);
            $table->json('topics')->nullable();                // temas que quiere recibir
            $table->string('frequency', 16)->default('normal'); // normal | weekly | monthly
            $table->timestamp('updated_at')->nullable();
        });

        Schema::create('holiday_overrides', function (Blueprint $table) {
            $table->id();
            $table->char('country', 2);
            $table->date('date');
            $table->boolean('is_holiday');                     // true: festivo extra; false: no es festivo
            $table->string('name', 120)->nullable();
            $table->char('created_by', 26)->nullable();
            $table->timestamps();
            $table->unique(['country', 'date']);
        });

        Schema::create('excluded_registry_imports', function (Blueprint $table) {
            $table->id();
            $table->char('workspace_id', 26);
            $table->char('country', 2);                        // CO: RNE de la CRC · MX: REPEP
            $table->char('file_hash', 64);
            $table->unsignedInteger('rows')->default(0);
            $table->unsignedInteger('added')->default(0);
            $table->char('imported_by', 26)->nullable();
            $table->timestamp('imported_at');
            $table->index(['workspace_id', 'country']);
        });
    }

    public function down(): void
    {
        foreach (['excluded_registry_imports', 'holiday_overrides', 'contact_preferences', 'campaign_recipients', 'campaigns',
            'flow_run_steps', 'flow_runs', 'flow_versions', 'flows', 'conversations', 'tracked_links', 'messages',
            'wa_rates', 'wa_templates', 'templates', 'channel_accounts', 'coupons_issued', 'carts'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
