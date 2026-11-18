<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Fase 5 (servicios, agencia y escala): modelo-de-datos.md §15.
 * Rango de los módulos: 2026_11_23_1xxxxx leads, 2xxxxx agenda y cotizador,
 * 3xxxxx agencia y marca blanca, 4xxxxx planes y facturación, 5xxxxx API, MCP y
 * webhooks, 6xxxxx Instagram y Messenger.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Leads (M18) ────────────────────────────────────────────────────────
        Schema::create('lead_pipelines', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26);
            $table->string('name', 120);
            $table->json('stages');                         // [{key, label, kind: open|won|lost}]
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            $table->index('workspace_id');
        });

        Schema::create('leads', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26);
            $table->char('pipeline_id', 26)->nullable();
            $table->char('contact_id', 26);
            $table->char('site_id', 26)->nullable();
            $table->string('stage', 32)->default('new');    // new | contacted | qualified | proposal | won | lost
            $table->string('service', 160)->nullable();     // lo que pidió (del formulario o del cotizador)
            $table->decimal('value_ws', 18, 2)->nullable();
            $table->char('owner_id', 26)->nullable();       // vendedor (usuario)
            $table->json('source')->nullable();             // canal, campaña, formulario, anuncio (gclid/fbclid), página
            $table->unsignedSmallInteger('score')->default(0);
            $table->string('temperature', 8)->default('cold'); // cold | warm | hot
            $table->json('score_reasons')->nullable();       // [{signal, points, at}] (incluye la de IA con su razón)
            $table->string('origin', 16)->default('form');   // form | whatsapp | quote | booking | manual | api
            $table->string('origin_ref', 64)->nullable();    // formulario, cotización o cita que lo creó
            $table->timestamp('first_response_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('won_at')->nullable();
            $table->timestamp('lost_at')->nullable();
            $table->string('lost_reason', 120)->nullable();
            $table->string('external_crm_id', 64)->nullable(); // Imagina CRM / Base / Proposals
            $table->timestamps();
            $table->index(['workspace_id', 'stage', 'last_activity_at']);
            $table->index(['workspace_id', 'contact_id']);
            $table->index(['workspace_id', 'owner_id', 'stage']);
        });

        Schema::create('lead_activities', function (Blueprint $table) {
            $table->id();
            $table->char('workspace_id', 26);
            $table->char('lead_id', 26);
            $table->string('kind', 24);                     // created | stage | note | score | alert | call | message | owner
            $table->string('actor', 32);                    // usuario | system | ai
            $table->json('data')->nullable();               // notas cifradas en la acción que las escribe
            $table->timestamp('created_at');
            $table->index(['lead_id', 'created_at']);
        });

        // ── Agenda de citas y cotizador (M18) ─────────────────────────────────
        Schema::create('booking_staff', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26);
            $table->char('user_id', 26)->nullable();        // si la persona usa el panel
            $table->string('name', 120);
            $table->string('email', 255)->nullable();       // cifrado
            $table->string('timezone', 64)->default('America/Bogota');
            $table->json('calendar')->nullable();           // Google Calendar: tokens cifrados, calendar_id, sincronizado_en
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->index('workspace_id');
        });

        Schema::create('booking_services', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26);
            $table->string('name', 160);
            $table->string('slug', 80);
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('duration_min')->default(30);
            $table->unsignedSmallInteger('buffer_min')->default(0);
            $table->decimal('price', 18, 2)->nullable();
            $table->string('location', 16)->default('onsite'); // onsite | online | phone
            $table->json('staff_ids')->nullable();
            $table->unsignedSmallInteger('min_notice_hours')->default(2);
            $table->unsignedSmallInteger('max_days_ahead')->default(60);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['workspace_id', 'slug']);
        });

        Schema::create('booking_availability', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26);
            $table->char('staff_id', 26)->nullable();       // null: horario del negocio
            $table->unsignedTinyInteger('weekday');         // 1 = lunes … 7 = domingo (ISO)
            $table->time('start_time');
            $table->time('end_time');
            $table->json('exceptions')->nullable();         // [{date, closed|start,end}] (festivos incluidos)
            $table->timestamps();
            $table->index(['workspace_id', 'staff_id']);
        });

        Schema::create('appointments', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26);
            $table->char('contact_id', 26);
            $table->char('service_id', 26);
            $table->char('staff_id', 26)->nullable();
            $table->char('lead_id', 26)->nullable();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->string('status', 16)->default('booked'); // booked | confirmed | rescheduled | cancelled | completed | no_show
            $table->string('channel', 16)->default('page'); // page | inbox | agent | manual | api
            $table->string('manage_token', 64)->nullable(); // reprogramar o cancelar desde el enlace
            $table->string('external_calendar_event_id', 255)->nullable();
            $table->json('reminders')->nullable();          // {"24h": sent_at, "2h": sent_at}
            $table->text('notes')->nullable();              // cifrado
            $table->timestamps();
            $table->index(['workspace_id', 'starts_at']);
            $table->index(['workspace_id', 'staff_id', 'starts_at']);
            $table->index(['workspace_id', 'contact_id']);
        });

        Schema::create('quote_calculators', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26);
            $table->char('site_id', 26)->nullable();
            $table->string('name', 160);
            $table->string('kind', 16)->default('service'); // service | area | package
            $table->json('definition');                     // campos, precios por unidad, paquetes, reglas, mínimo
            $table->json('capture')->nullable();            // qué datos pide y consentimientos
            $table->string('status', 12)->default('draft'); // draft | active | paused
            $table->timestamps();
            $table->index(['workspace_id', 'status']);
        });

        Schema::create('quotes', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26);
            $table->char('calculator_id', 26);
            $table->char('contact_id', 26)->nullable();
            $table->char('lead_id', 26)->nullable();
            $table->json('answers');
            $table->decimal('estimate_min', 18, 2)->nullable();
            $table->decimal('estimate_max', 18, 2)->nullable();
            $table->string('currency', 3)->default('COP');
            $table->string('anonymous_id', 64)->nullable();
            $table->timestamps();
            $table->index(['workspace_id', 'created_at']);
        });

        // ── Agencia y marca blanca (M22) ──────────────────────────────────────
        Schema::create('account_branding', function (Blueprint $table) {
            $table->char('account_id', 26)->primary();
            $table->string('brand_name', 120)->nullable();
            $table->string('logo_path', 255)->nullable();
            $table->string('primary_color', 9)->nullable();
            $table->string('sender_name', 120)->nullable();
            $table->string('sender_email', 255)->nullable();
            $table->string('support_email', 255)->nullable();
            $table->boolean('white_label')->default(false); // oculta «Imagina Growth» en el panel, correos y reportes
            $table->timestamps();
        });

        Schema::create('custom_domains', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('account_id', 26);
            $table->char('workspace_id', 26)->nullable();   // null: dominio del panel de la agencia
            $table->string('host', 255)->unique();
            $table->string('purpose', 16);                  // panel | links | booking | tracking
            $table->string('status', 16)->default('pending'); // pending | verified | failed
            $table->string('verification_token', 64);
            $table->timestamp('verified_at')->nullable();
            $table->string('last_error', 255)->nullable();
            $table->timestamps();
        });

        Schema::create('agency_templates', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('account_id', 26);
            $table->string('kind', 16);                     // flow | segment | widget | template | campaign
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->json('payload');                        // la definición portable (sin IDs de un espacio de trabajo)
            $table->char('source_workspace_id', 26)->nullable();
            $table->char('created_by', 26)->nullable();
            $table->timestamps();
            $table->index(['account_id', 'kind']);
        });

        Schema::create('monthly_reports', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26);
            $table->date('period');                         // primer día del mes
            $table->json('facts');                          // dinero generado por módulo, KPI, hallazgos
            $table->text('summary')->nullable();            // texto (IA con cifras validadas o plantilla)
            $table->string('summary_source', 12)->nullable(); // ai | fallback
            $table->string('pdf_path', 255)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->json('recipients')->nullable();         // cifrado
            $table->timestamps();
            $table->unique(['workspace_id', 'period']);
        });

        Schema::create('support_sessions', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26);
            $table->char('user_id', 26);                    // quien entra (agencia o soporte)
            $table->string('reason', 255);
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->string('ip_hash', 64)->nullable();
            $table->index(['workspace_id', 'started_at']);
        });

        // ── Planes, uso y facturación (M21) ───────────────────────────────────
        Schema::create('plans', function (Blueprint $table) {
            $table->string('id', 40)->primary();            // starter, growth, pro, services_starter…
            $table->string('name', 120);
            $table->string('metric', 16);                   // orders | contacts
            $table->unsignedInteger('included');            // pedidos al mes o contactos activos
            $table->json('modules');                        // módulos activos (Pennant)
            $table->json('limits')->nullable();             // correos incluidos, presupuesto de IA, usuarios, API…
            $table->decimal('price_usd', 10, 2)->default(0);
            $table->decimal('price_cop', 14, 2)->nullable();
            $table->boolean('public')->default(true);
            $table->timestamps();
        });

        Schema::create('usage_counters', function (Blueprint $table) {
            $table->char('workspace_id', 26);
            $table->char('period', 7);                      // YYYY-MM
            $table->unsignedInteger('contacts')->default(0);
            $table->unsignedInteger('orders')->default(0);
            $table->unsignedBigInteger('events')->default(0);
            $table->unsignedInteger('emails')->default(0);
            $table->unsignedInteger('wa_marketing')->default(0);
            $table->unsignedInteger('wa_utility')->default(0);
            $table->unsignedInteger('wa_service')->default(0);
            $table->unsignedInteger('sms')->default(0);
            $table->unsignedBigInteger('ai_tokens')->default(0);
            $table->unsignedInteger('api_requests')->default(0);
            $table->timestamp('computed_at')->nullable();
            $table->json('alerts_sent')->nullable();        // {"80": at, "100": at}
            $table->primary(['workspace_id', 'period']);
        });

        Schema::create('billing_invoices', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('account_id', 26);
            $table->string('number', 32)->unique();
            $table->date('period');
            $table->json('lines');
            $table->decimal('total', 14, 2);
            $table->string('currency', 3);
            $table->string('status', 16)->default('draft'); // draft | sent | paid | void | overdue
            $table->string('payment_url', 500)->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->index(['account_id', 'status']);
        });

        Schema::table('accounts', function (Blueprint $table) {
            $table->string('billing_status', 16)->default('active')->after('status'); // trial | active | grace | suspended
            $table->timestamp('trial_ends_at')->nullable()->after('billing_status');
            $table->timestamp('grace_ends_at')->nullable()->after('trial_ends_at');
        });

        // ── API pública y webhooks (M19) ──────────────────────────────────────
        Schema::create('webhook_subscriptions', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26);
            $table->string('url', 500);
            $table->json('events');
            $table->text('secret');                         // cifrado (whsec_…)
            $table->string('status', 16)->default('active'); // active | paused | failing
            $table->unsignedSmallInteger('consecutive_failures')->default(0);
            $table->timestamp('last_success_at')->nullable();
            $table->char('created_by', 26)->nullable();
            $table->timestamps();
            $table->index(['workspace_id', 'status']);
        });

        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->char('workspace_id', 26);
            $table->char('subscription_id', 26);
            $table->string('event', 64);
            $table->char('event_id', 36);
            $table->unsignedTinyInteger('attempt')->default(1);
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('error', 255)->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('created_at');
            $table->index(['subscription_id', 'created_at']);
            $table->unique(['subscription_id', 'event_id', 'attempt']);
        });

        Schema::create('api_clients', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26);
            $table->string('name', 120);                    // «Zapier», «Imagina CRM», «Claude (MCP)»
            $table->string('kind', 16)->default('token');   // token | mcp
            $table->json('scopes');
            $table->char('created_by', 26)->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index('workspace_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_clients');
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhook_subscriptions');
        Schema::table('accounts', fn (Blueprint $table) => $table->dropColumn(['billing_status', 'trial_ends_at', 'grace_ends_at']));
        Schema::dropIfExists('billing_invoices');
        Schema::dropIfExists('usage_counters');
        Schema::dropIfExists('plans');
        Schema::dropIfExists('support_sessions');
        Schema::dropIfExists('monthly_reports');
        Schema::dropIfExists('agency_templates');
        Schema::dropIfExists('custom_domains');
        Schema::dropIfExists('account_branding');
        Schema::dropIfExists('quotes');
        Schema::dropIfExists('quote_calculators');
        Schema::dropIfExists('appointments');
        Schema::dropIfExists('booking_availability');
        Schema::dropIfExists('booking_services');
        Schema::dropIfExists('booking_staff');
        Schema::dropIfExists('lead_activities');
        Schema::dropIfExists('leads');
        Schema::dropIfExists('lead_pipelines');
    }
};
