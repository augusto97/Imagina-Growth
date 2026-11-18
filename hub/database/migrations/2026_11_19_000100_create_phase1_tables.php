<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tablas de la Fase 1 según docs/modelo-de-datos.md (§2, §3, §5, §6, §7 y §9).
 * Se crean juntas para que los módulos (perfil, segmentos, analítica,
 * medición) trabajen sobre el mismo esquema; cada módulo agrega columnas con
 * sus propias migraciones si las necesita.
 */
return new class extends Migration
{
    public function up(): void
    {
        // §2: campos calculados del contacto (inteligencia.md §3 y §4).
        Schema::table('contacts', function (Blueprint $table) {
            $table->unsignedTinyInteger('rfm_r')->nullable()->after('coupon_orders_ratio');
            $table->unsignedTinyInteger('rfm_f')->nullable()->after('rfm_r');
            $table->unsignedTinyInteger('rfm_m')->nullable()->after('rfm_f');
            $table->string('rfm_segment', 32)->nullable()->after('rfm_m');
            $table->timestamp('rfm_computed_at')->nullable()->after('rfm_segment');
            $table->decimal('predicted_clv_12m_ws', 18, 2)->nullable()->after('rfm_computed_at');
            $table->decimal('churn_risk', 5, 4)->nullable()->after('predicted_clv_12m_ws');
            $table->string('churn_band', 8)->nullable()->after('churn_risk');
            $table->timestamp('expected_next_order_at')->nullable()->after('churn_band');
            $table->unsignedTinyInteger('engagement_score')->nullable()->after('expected_next_order_at');
            $table->unsignedTinyInteger('lead_score')->nullable()->after('engagement_score');
            $table->unsignedTinyInteger('preferred_hour')->nullable()->after('lead_score');
            $table->string('preferred_channel', 16)->nullable()->after('preferred_hour');
            $table->json('category_affinity')->nullable()->after('preferred_channel');
            $table->string('price_band', 8)->nullable()->after('category_affinity');
            $table->integer('points_balance')->default(0)->after('price_band');
            $table->char('tier_id', 26)->nullable()->after('points_balance');
            $table->decimal('wallet_balance_ws', 18, 2)->default(0)->after('tier_id');
            $table->string('referral_code', 32)->nullable()->after('wallet_balance_ws');
            $table->index(['workspace_id', 'rfm_segment']);
            $table->index(['workspace_id', 'expected_next_order_at']);
            $table->index(['workspace_id', 'orders_count']);
        });

        Schema::create('contact_notes', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26);
            $table->char('contact_id', 26);
            $table->char('user_id', 26)->nullable();
            $table->text('body'); // cifrado: puede tener datos personales
            $table->timestamps();
            $table->index(['workspace_id', 'contact_id']);
        });

        Schema::create('custom_attribute_defs', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26);
            $table->string('key', 64);
            $table->string('label', 120);
            $table->string('type', 8); // text | number | date | bool | enum
            $table->json('options')->nullable();
            $table->string('source', 40)->nullable();
            $table->boolean('is_sensitive')->default(false); // cumplimiento.md §2: no se usa en segmentos de marketing
            $table->timestamps();
            $table->unique(['workspace_id', 'key']);
        });

        // §3: consentimiento y supresión.
        Schema::create('contact_consents', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26);
            $table->char('contact_id', 26);
            $table->string('channel', 16);   // email | whatsapp | sms | push | ads
            $table->string('purpose', 16);   // marketing | transactional
            $table->string('status', 24);    // granted | revoked | pending_double_optin
            $table->string('source', 64);    // checkout | form:<id> | widget:<id> | import | whatsapp_reply | admin | api
            $table->string('legal_text_version', 32)->nullable();
            $table->timestamp('granted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->unique(['workspace_id', 'contact_id', 'channel', 'purpose']);
            $table->index(['workspace_id', 'channel', 'purpose', 'status']);
        });

        Schema::create('consent_events', function (Blueprint $table) {
            $table->id();
            $table->char('workspace_id', 26);
            $table->char('contact_id', 26);
            $table->string('channel', 16);
            $table->string('purpose', 16);
            $table->string('action', 16);    // granted | revoked
            $table->string('source', 64);
            $table->string('legal_text_version', 32)->nullable();
            $table->json('evidence')->nullable(); // page_url, ip_hash, ua_hash, form_snapshot, event_id
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['workspace_id', 'contact_id', 'occurred_at']);
        });

        Schema::create('legal_texts', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26);
            $table->string('kind', 32);      // marketing_consent | privacy_notice
            $table->string('channel', 16)->nullable();
            $table->string('version', 32);
            $table->text('body');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->unique(['workspace_id', 'kind', 'channel', 'version']);
        });

        Schema::create('suppressions', function (Blueprint $table) {
            $table->id();
            $table->char('workspace_id', 26);
            $table->string('channel', 16);
            $table->char('value_bidx', 64);
            $table->string('reason', 16);    // bounce | complaint | unsubscribe | rne | repep | manual | wa_block
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['workspace_id', 'channel', 'value_bidx']);
        });

        // §5: segmentos.
        Schema::create('segments', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26);
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->json('definition')->nullable(); // AST (hub/CLAUDE.md §6); null en los estáticos
            $table->string('kind', 8)->default('dynamic'); // dynamic | static
            $table->boolean('is_system')->default(false);
            $table->string('system_key', 64)->nullable();
            $table->unsignedInteger('member_count')->default(0);
            $table->decimal('revenue_ws', 18, 2)->default(0);
            $table->json('channel_counts')->nullable();
            $table->timestamp('last_computed_at')->nullable();
            $table->string('folder', 80)->nullable();
            $table->char('created_by', 26)->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['workspace_id', 'is_system']);
            $table->unique(['workspace_id', 'system_key']);
        });

        Schema::create('segment_memberships', function (Blueprint $table) {
            $table->char('segment_id', 26);
            $table->char('contact_id', 26);
            $table->char('workspace_id', 26);
            $table->timestamp('entered_at');
            $table->primary(['segment_id', 'contact_id']);
            $table->index(['workspace_id', 'contact_id']);
        });

        // §6: encuestas (la de «¿cómo nos conociste?» llega en la Fase 1).
        Schema::create('survey_definitions', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26);
            $table->string('kind', 16);      // hdyhau | nps | csat | custom
            $table->string('status', 16)->default('active');
            $table->json('questions');
            $table->json('trigger')->nullable();
            $table->timestamps();
            $table->index(['workspace_id', 'kind']);
        });

        Schema::create('survey_responses', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26);
            $table->char('survey_id', 26);
            $table->char('contact_id', 26)->nullable();
            $table->char('order_id', 26)->nullable();
            $table->json('answers');
            $table->unsignedTinyInteger('score')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['survey_id', 'order_id']);
            $table->index(['workspace_id', 'survey_id', 'created_at']);
        });

        // §7: medición.
        Schema::create('ad_destinations', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26);
            $table->string('platform', 16);  // meta | google | tiktok | pinterest
            $table->string('name', 120)->nullable();
            $table->text('config');          // cifrado: pixel/dataset, tokens, cuenta de Data Manager, ids de conversión
            $table->json('events_enabled')->nullable();
            $table->string('mode', 16)->default('server_only'); // server_only | with_pixel | full
            $table->string('test_event_code', 64)->nullable();
            $table->string('status', 16)->default('active');
            $table->text('last_error')->nullable();
            $table->timestamp('last_delivered_at')->nullable();
            $table->timestamps();
            $table->index(['workspace_id', 'platform']);
        });

        Schema::create('ad_spend_daily', function (Blueprint $table) {
            $table->id();
            $table->char('workspace_id', 26);
            $table->string('platform', 16);
            $table->date('date');
            $table->string('campaign_id', 64);
            $table->string('campaign_name', 255)->nullable();
            $table->string('adset_id', 64)->nullable();
            $table->string('ad_id', 64)->nullable();
            $table->decimal('spend', 18, 2)->default(0);
            $table->char('currency', 3);
            $table->unsignedBigInteger('impressions')->default(0);
            $table->unsignedBigInteger('clicks')->default(0);
            $table->timestamps();
            $table->unique(['workspace_id', 'platform', 'date', 'campaign_id', 'adset_id', 'ad_id'], 'ad_spend_daily_unique');
        });

        // §9: inteligencia y privacidad.
        Schema::create('insights', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26);
            $table->string('detector_key', 64);
            $table->string('severity', 16);  // info | opportunity | warning | critical
            $table->string('title', 255);
            $table->json('facts');
            $table->decimal('estimated_impact_ws', 18, 2)->nullable();
            $table->json('impact_assumptions')->nullable();
            $table->json('recommended_action')->nullable();
            $table->string('status', 16)->default('new'); // new | seen | actioned | dismissed | expired
            $table->string('fingerprint', 64)->nullable();
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->timestamp('dismissed_at')->nullable();
            $table->timestamps();
            $table->index(['workspace_id', 'status', 'severity']);
            $table->index(['workspace_id', 'detector_key', 'fingerprint']);
        });

        Schema::create('prediction_runs', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26);
            $table->string('model', 16);     // rfm | edno | churn | clv | affinity
            $table->timestamp('trained_at');
            $table->json('metrics')->nullable();
            $table->json('params')->nullable();
            $table->string('status', 16);
            $table->timestamps();
            $table->index(['workspace_id', 'model', 'trained_at']);
        });

        Schema::create('data_requests', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26);
            $table->char('contact_id', 26)->nullable();
            $table->string('kind', 16);      // access | rectify | delete | revoke
            $table->text('requester')->nullable(); // cifrado
            $table->string('status', 16)->default('pending');
            $table->timestamp('due_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->json('evidence')->nullable();
            $table->char('created_by', 26)->nullable();
            $table->timestamps();
            $table->index(['workspace_id', 'status']);
        });

        Schema::create('report_deliveries', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26);
            $table->string('kind', 16);      // weekly | monthly
            $table->date('period_start');
            $table->date('period_end');
            $table->json('facts');
            $table->json('recipients')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
            $table->unique(['workspace_id', 'kind', 'period_start']);
        });
    }

    public function down(): void
    {
        foreach (['report_deliveries', 'data_requests', 'prediction_runs', 'insights', 'ad_spend_daily', 'ad_destinations', 'survey_responses', 'survey_definitions', 'segment_memberships', 'segments', 'suppressions', 'legal_texts', 'consent_events', 'contact_consents', 'custom_attribute_defs', 'contact_notes'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropIndex(['workspace_id', 'rfm_segment']);
            $table->dropIndex(['workspace_id', 'expected_next_order_at']);
            $table->dropIndex(['workspace_id', 'orders_count']);
            $table->dropColumn(['rfm_r', 'rfm_f', 'rfm_m', 'rfm_segment', 'rfm_computed_at', 'predicted_clv_12m_ws', 'churn_risk', 'churn_band', 'expected_next_order_at', 'engagement_score', 'lead_score', 'preferred_hour', 'preferred_channel', 'category_affinity', 'price_band', 'points_balance', 'tier_id', 'wallet_balance_ws', 'referral_code']);
        });
    }
};
