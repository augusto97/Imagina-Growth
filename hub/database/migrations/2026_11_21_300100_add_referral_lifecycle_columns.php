<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M14 (referidos y afiliados): columnas del ciclo de vida que no estaban en la
 * migración base de la Fase 3, más las huellas antifraude (modelo-de-datos.md §13).
 *
 * - referrals: cuándo compró el amigo, cuándo califica (pedido completado + días de
 *   devolución), la recompensa entregada y su costo, y el motivo del rechazo.
 * - affiliates: el material del panel del creador y el nonce del enlace mágico
 *   (cambiarlo invalida los enlaces anteriores).
 * - affiliate_conversions: cuándo se aprueba y por qué se anuló.
 * - contacts.referral_code: único por espacio de trabajo.
 * - referral_fingerprints: hashes (HMAC con la clave del índice ciego) de la IP y del
 *   destinatario de envío de quienes refieren, para las señales de fraude. Nunca
 *   guarda el dato en claro.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('referrals', function (Blueprint $table) {
            $table->char('site_id', 26)->nullable()->after('workspace_id');
            $table->timestamp('ordered_at')->nullable()->after('order_id');
            $table->decimal('order_amount_ws', 14, 2)->nullable()->after('ordered_at');
            $table->timestamp('qualifies_at')->nullable()->after('order_amount_ws');
            $table->timestamp('rewarded_at')->nullable()->after('qualifies_at');
            $table->json('reward')->nullable()->after('reward_ledger_id');
            $table->decimal('reward_cost_ws', 14, 2)->nullable()->after('reward');
            $table->string('rejected_reason', 40)->nullable()->after('review_status');
            $table->index(['workspace_id', 'status', 'qualifies_at'], 'referrals_qualify_lookup');
            $table->index(['workspace_id', 'review_status'], 'referrals_review_lookup');
            $table->index(['workspace_id', 'order_id'], 'referrals_order_lookup');
        });

        Schema::table('affiliates', function (Blueprint $table) {
            $table->json('material')->nullable()->after('commission');
            $table->string('portal_nonce', 16)->nullable()->after('payout_info');
            $table->timestamp('portal_sent_at')->nullable()->after('portal_nonce');
        });

        Schema::table('affiliate_conversions', function (Blueprint $table) {
            $table->timestamp('qualifies_at')->nullable()->after('status');
            $table->string('void_reason', 40)->nullable()->after('paid_at');
            $table->index(['workspace_id', 'status', 'qualifies_at'], 'affiliate_conv_qualify_lookup');
            $table->index(['workspace_id', 'order_id'], 'affiliate_conv_order_lookup');
        });

        Schema::table('contacts', function (Blueprint $table) {
            $table->unique(['workspace_id', 'referral_code'], 'contacts_referral_code_unique');
        });

        Schema::create('referral_fingerprints', function (Blueprint $table) {
            $table->id();
            $table->char('workspace_id', 26);
            $table->char('contact_id', 26);
            $table->string('kind', 12);                         // ip | address
            $table->char('hash', 64);
            $table->timestamp('last_seen_at');
            $table->unique(['workspace_id', 'contact_id', 'kind', 'hash'], 'referral_fp_unique');
            $table->index(['workspace_id', 'kind', 'hash'], 'referral_fp_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referral_fingerprints');
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropUnique('contacts_referral_code_unique');
        });
        Schema::table('affiliate_conversions', function (Blueprint $table) {
            $table->dropIndex('affiliate_conv_qualify_lookup');
            $table->dropIndex('affiliate_conv_order_lookup');
            $table->dropColumn(['qualifies_at', 'void_reason']);
        });
        Schema::table('affiliates', function (Blueprint $table) {
            $table->dropColumn(['material', 'portal_nonce', 'portal_sent_at']);
        });
        Schema::table('referrals', function (Blueprint $table) {
            $table->dropIndex('referrals_qualify_lookup');
            $table->dropIndex('referrals_review_lookup');
            $table->dropIndex('referrals_order_lookup');
            $table->dropColumn(['site_id', 'ordered_at', 'order_amount_ws', 'qualifies_at', 'rewarded_at', 'reward', 'reward_cost_ws', 'rejected_reason']);
        });
    }
};
