<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tablas de la Fase 3 (captación, fidelización, referidos, reseñas, comercio en
 * el sitio y audiencias), según modelo-de-datos.md §6 y §7. Las diferencias con el
 * documento están en su §13. Las columnas de fidelización del contacto
 * (points_balance, tier_id, wallet_balance_ws, referral_code) llegaron en la Fase 1.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── M12 Captación ───────────────────────────────────────────────────
        Schema::create('onsite_widgets', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26);
            $table->char('site_id', 26)->nullable();          // null: todos los sitios del espacio de trabajo
            $table->string('name', 160);
            $table->string('kind', 24);                         // popup | embedded_form | bar | spin_wheel | teaser | two_step | quiz | wa_widget | referral_welcome …
            $table->string('status', 16)->default('draft');   // draft | active | paused | archived
            $table->unsignedSmallInteger('priority')->default(100);
            $table->json('targeting')->nullable();
            $table->json('design')->nullable();
            $table->json('content')->nullable();
            $table->json('variants')->nullable();               // A/B: [{key, weight, design, content}]
            $table->json('consent_config')->nullable();         // canales, textos y doble opt-in
            $table->json('coupon_config')->nullable();          // CouponSpec del regalo, o premios de la ruleta con sus probabilidades
            $table->json('stats')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->char('created_by', 26)->nullable();
            $table->timestamps();
            $table->index(['workspace_id', 'status']);
        });

        Schema::create('quizzes', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26);
            $table->char('widget_id', 26)->unique();
            $table->json('questions');                          // [{id, text, options: [{key, label, next?, attributes?}]}]
            $table->json('outcomes');                           // reglas → productos y atributos que se guardan
            $table->timestamps();
        });

        Schema::create('widget_captures', function (Blueprint $table) {
            $table->id();
            $table->char('workspace_id', 26);
            $table->char('widget_id', 26);
            $table->string('variant', 8)->nullable();
            $table->char('contact_id', 26);
            $table->string('anonymous_id', 64)->nullable();
            $table->char('coupon_id', 26)->nullable();
            $table->string('prize', 40)->nullable();
            $table->json('answers')->nullable();
            $table->boolean('was_known')->default(false);       // ya era contacto antes (no cuenta como captación nueva)
            $table->timestamp('captured_at');
            $table->unique(['widget_id', 'contact_id']);
            $table->index(['workspace_id', 'contact_id', 'captured_at']);
        });

        // ── M13 Fidelización ────────────────────────────────────────────────
        Schema::create('loyalty_programs', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26)->unique();         // un programa por espacio de trabajo
            $table->string('status', 16)->default('draft');   // draft | active | paused
            $table->string('currency_name', 60)->default('Puntos');
            $table->json('earn_rules');
            $table->json('redeem_rules')->nullable();
            $table->json('expiry_policy')->nullable();
            $table->unsignedSmallInteger('pending_days')->default(15);
            $table->json('wallet_rules')->nullable();           // cashback y vencimiento del saldo
            $table->timestamps();
        });

        Schema::create('loyalty_tiers', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26);
            $table->char('program_id', 26);
            $table->string('name', 80);
            $table->unsignedSmallInteger('rank');
            $table->string('threshold_type', 16);               // spend_12m | points_12m | orders_12m
            $table->decimal('threshold', 14, 2);
            $table->json('benefits')->nullable();               // multiplicador, envío gratis, rol de WooCommerce, regalos
            $table->string('wc_role', 60)->nullable();
            $table->timestamps();
            $table->unique(['program_id', 'rank']);
        });

        Schema::create('loyalty_rewards', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26);
            $table->char('program_id', 26);
            $table->string('name', 120);
            $table->string('kind', 20);                         // coupon_fixed | coupon_percent | free_shipping | free_product | wallet
            $table->unsignedInteger('points_cost');
            $table->decimal('value', 14, 2)->default(0);
            $table->json('config')->nullable();                 // producto gratis, mínimo de compra, vencimiento del cupón
            $table->string('status', 16)->default('active');
            $table->timestamps();
            $table->index(['program_id', 'status']);
        });

        Schema::create('loyalty_ledger', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26);
            $table->char('contact_id', 26);
            $table->string('kind', 8);                          // points | wallet
            $table->decimal('delta', 14, 2);                    // puntos (enteros) o dinero de la billetera
            $table->decimal('balance_after', 14, 2);
            $table->char('currency', 3)->nullable();            // solo billetera
            $table->string('status', 12);                       // pending | confirmed | reversed | expired
            $table->string('reason', 40);
            $table->string('ref_type', 20)->nullable();
            $table->string('ref_id', 60)->nullable();
            $table->char('parent_id', 26)->nullable();          // la entrada que confirma, revierte o vence
            $table->string('idempotency_key', 190);             // nunca se acredita dos veces lo mismo
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('created_at');
            $table->unique(['workspace_id', 'idempotency_key']);
            $table->index(['workspace_id', 'contact_id', 'kind', 'created_at']);
            $table->index(['workspace_id', 'status', 'expires_at']);
            $table->index(['ref_type', 'ref_id']);
        });

        // ── M14 Referidos y afiliados ───────────────────────────────────────
        Schema::create('referral_programs', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26)->unique();
            $table->string('status', 16)->default('draft');
            $table->json('referrer_reward');                    // puntos, saldo o cupón
            $table->json('friend_reward');                      // CouponSpec del regalo del amigo
            $table->json('rules')->nullable();                  // compra mínima, límite mensual, antifraude, días de devolución
            $table->text('share_text')->nullable();
            $table->timestamps();
        });

        Schema::create('referrals', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26);
            $table->char('referrer_contact_id', 26);
            $table->string('code', 32);
            $table->char('friend_contact_id', 26)->nullable();
            $table->string('status', 16);                       // clicked | signed_up | ordered | qualified | rewarded | rejected
            $table->char('order_id', 26)->nullable();
            $table->char('friend_coupon_id', 26)->nullable();
            $table->char('reward_ledger_id', 26)->nullable();
            $table->json('fraud_signals')->nullable();
            $table->string('review_status', 16)->nullable();    // null | pending_review | approved | rejected (revisión manual)
            $table->timestamps();
            $table->unique(['workspace_id', 'friend_contact_id']); // un amigo solo puede ser referido una vez
            $table->index(['workspace_id', 'referrer_contact_id', 'status']);
            $table->index(['workspace_id', 'code']);
        });

        Schema::create('affiliates', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26);
            $table->char('contact_id', 26)->nullable();
            $table->string('name', 160);
            $table->string('kind', 16);                         // influencer | affiliate | partner
            $table->text('email')->nullable();                  // cifrado: a dónde va el enlace mágico del panel
            $table->char('email_bidx', 64)->nullable();
            $table->json('coupon_codes')->nullable();
            $table->string('link_code', 32);
            $table->json('commission');                         // {type: percent|fixed, value, basis: net|total}
            $table->text('payout_info')->nullable();            // cifrado
            $table->string('status', 16)->default('active');
            $table->timestamps();
            $table->unique(['workspace_id', 'link_code']);
        });

        Schema::create('affiliate_conversions', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26);
            $table->char('affiliate_id', 26);
            $table->char('order_id', 26);
            $table->string('via', 8);                           // coupon | link
            $table->decimal('amount_ws', 14, 2);
            $table->decimal('commission_ws', 14, 2);
            $table->string('status', 12)->default('pending');   // pending | approved | paid | void
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->unique(['affiliate_id', 'order_id']);
            $table->index(['workspace_id', 'status']);
        });

        // ── M15 Reseñas y NPS ───────────────────────────────────────────────
        Schema::create('reviews', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26);
            $table->char('site_id', 26);
            $table->char('product_id', 26)->nullable();         // interno (products.id); null = reseña de la tienda
            $table->string('external_product_id', 64)->nullable();
            $table->char('contact_id', 26)->nullable();
            $table->char('order_id', 26)->nullable();
            $table->unsignedTinyInteger('rating');
            $table->string('title', 200)->nullable();
            $table->text('body')->nullable();
            $table->string('author_name', 120)->nullable();     // «Ana R.»: lo que se publica
            $table->json('media')->nullable();                  // [{path, url, type, thumb, size}]
            $table->string('status', 12)->default('pending');   // pending | published | rejected
            $table->boolean('verified_buyer')->default(false);
            $table->string('source', 16);                       // request | widget | import | woocommerce
            $table->string('external_review_id', 40)->nullable(); // comentario de WooCommerce
            $table->text('owner_reply')->nullable();
            $table->timestamp('replied_at')->nullable();
            $table->json('ai_flags')->nullable();               // ofensivo, spam, datos personales (filtro; la IA llega en la Fase 4)
            $table->char('moderated_by', 26)->nullable();
            $table->timestamp('moderated_at')->nullable();
            $table->timestamps();
            $table->index(['workspace_id', 'status', 'created_at']);
            $table->index(['workspace_id', 'external_product_id', 'status']);
            $table->unique(['site_id', 'external_review_id']);
        });

        Schema::create('review_requests', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26);
            $table->char('order_id', 26);
            $table->char('contact_id', 26);
            $table->string('channel', 16);
            $table->string('status', 16)->default('scheduled'); // scheduled | sent | completed | expired | skipped
            $table->json('products')->nullable();
            $table->char('message_id', 26)->nullable();
            $table->timestamp('scheduled_for')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['workspace_id', 'order_id']);
            $table->index(['workspace_id', 'status', 'scheduled_for']);
        });

        // ── M16 Comercio en el sitio ────────────────────────────────────────
        Schema::create('product_watches', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26);
            $table->char('site_id', 26);
            $table->char('contact_id', 26);
            $table->string('external_product_id', 64);
            $table->string('kind', 16);                         // back_in_stock | price_drop
            $table->decimal('threshold', 14, 2)->nullable();    // precio al suscribirse (price_drop)
            $table->string('channel', 16);                      // email | whatsapp | sms
            $table->string('status', 12)->default('active');    // active | notified | cancelled
            $table->timestamp('notified_at')->nullable();
            $table->char('message_id', 26)->nullable();
            $table->timestamps();
            $table->index(['workspace_id', 'site_id', 'external_product_id', 'kind', 'status'], 'product_watches_product_lookup');
            $table->index(['workspace_id', 'contact_id']);
        });

        Schema::create('product_associations', function (Blueprint $table) {
            $table->char('workspace_id', 26);
            $table->char('site_id', 26);
            $table->string('product_id', 64);                   // externo
            $table->string('related_product_id', 64);
            $table->unsignedInteger('pair_orders');
            $table->decimal('support', 8, 6);
            $table->decimal('confidence', 8, 6);
            $table->decimal('lift', 10, 4);
            $table->timestamp('computed_at');
            $table->primary(['site_id', 'product_id', 'related_product_id']);
            $table->index(['workspace_id', 'site_id', 'product_id', 'lift'], 'product_associations_lookup');
        });

        // ── M06 Audiencias ──────────────────────────────────────────────────
        Schema::create('audience_syncs', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26);
            $table->char('segment_id', 26);
            $table->char('destination_id', 26);                 // ad_destinations
            $table->string('name', 160);
            $table->string('platform_audience_id', 120)->nullable();
            $table->string('status', 16)->default('pending');   // pending | active | paused | error
            $table->unsignedInteger('members_count')->default(0);
            $table->unsignedInteger('matched_count')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamps();
            $table->unique(['segment_id', 'destination_id']);
        });

        Schema::create('audience_members', function (Blueprint $table) {
            $table->char('audience_sync_id', 26);
            $table->char('contact_id', 26);
            $table->timestamp('synced_at');
            $table->primary(['audience_sync_id', 'contact_id']);
        });
    }

    public function down(): void
    {
        foreach (['audience_members', 'audience_syncs', 'product_associations', 'product_watches', 'review_requests', 'reviews',
            'affiliate_conversions', 'affiliates', 'referrals', 'referral_programs', 'loyalty_ledger', 'loyalty_rewards',
            'loyalty_tiers', 'loyalty_programs', 'widget_captures', 'quizzes', 'onsite_widgets'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
