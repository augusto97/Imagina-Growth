<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * M16 (comercio en el sitio) y M06 (audiencias), Fase 3. Complementa
 * 2026_11_21_000100_create_phase3_tables:
 *  - products: ventas por ventana y puntaje de tendencia que calcula el job de
 *    recomendaciones (views_30d y purchases_30d ya existían sin llenarse).
 *  - product_watches: reintento de un aviso aplazado por la política de envío.
 *  - commerce_settings: ajustes de recomendaciones y avisos del espacio de trabajo.
 *  - onsite_banners: banners personalizados por segmento (no van en
 *    onsite_widgets porque se resuelven por visitante en /banner, sin caché).
 *  - audience_syncs: propósito (público, exclusión, semilla de similares) y
 *    resultado de la última sincronización.
 *  - audience_members: los identificadores con hash que se enviaron, cifrados,
 *    para poder sacar a alguien de la audiencia aunque cambie o se borre su perfil.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedInteger('purchases_7d')->default(0)->after('purchases_30d');
            $table->unsignedInteger('purchases_90d')->default(0)->after('purchases_7d');
            $table->decimal('trend_score', 10, 4)->default(0)->after('view_to_purchase_30d');
            $table->timestamp('stats_computed_at')->nullable()->after('trend_score');
        });

        Schema::table('product_watches', function (Blueprint $table) {
            $table->decimal('price_at_subscribe', 14, 2)->nullable()->after('threshold');
            $table->decimal('notified_price', 14, 2)->nullable()->after('notified_at');
            $table->timestamp('next_attempt_at')->nullable()->after('notified_price');
            $table->unsignedSmallInteger('attempts')->default(0)->after('next_attempt_at');
            $table->string('last_reason', 64)->nullable()->after('attempts');
            $table->index(['workspace_id', 'status', 'next_attempt_at'], 'product_watches_due');
        });

        Schema::create('commerce_settings', function (Blueprint $table) {
            $table->char('workspace_id', 26)->primary();
            $table->json('recommendations')->nullable();
            $table->json('watches')->nullable();
            $table->timestamps();
        });

        Schema::create('onsite_banners', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26);
            $table->char('site_id', 26)->nullable();            // null: todos los sitios
            $table->string('name', 120);
            $table->string('placement', 16)->default('top');    // top | account
            $table->string('audience', 16);                     // segment | points | pending_cart | at_risk
            $table->char('segment_id', 26)->nullable();
            $table->unsignedInteger('min_points')->nullable();
            $table->string('text', 300);                        // con sesión: puede usar {{nombre}}, {{puntos}}
            $table->string('text_public', 300)->nullable();     // sin sesión: sin datos personales
            $table->string('cta', 60)->nullable();
            $table->string('url', 500)->nullable();
            $table->string('style', 16)->default('brand');      // brand | info | success | warning
            $table->unsignedSmallInteger('priority')->default(100);
            $table->string('status', 12)->default('active');    // active | paused
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->char('created_by', 26)->nullable();
            $table->timestamps();
            $table->index(['workspace_id', 'status', 'placement']);
        });

        Schema::table('audience_syncs', function (Blueprint $table) {
            $table->string('purpose', 16)->default('target')->after('name'); // target | exclusion | lookalike_seed
            $table->unsignedInteger('last_added')->default(0)->after('matched_count');
            $table->unsignedInteger('last_removed')->default(0)->after('last_added');
            $table->unsignedInteger('skipped_no_consent')->default(0)->after('last_removed');
            $table->timestamp('last_attempt_at')->nullable()->after('last_synced_at');
            $table->char('created_by', 26)->nullable()->after('last_error');
            $table->index(['workspace_id', 'status']);
        });

        Schema::table('audience_members', function (Blueprint $table) {
            $table->text('identifiers')->nullable()->after('contact_id'); // cifrado: hashes enviados
        });
    }

    public function down(): void
    {
        Schema::table('audience_members', fn (Blueprint $table) => $table->dropColumn('identifiers'));
        Schema::table('audience_syncs', function (Blueprint $table) {
            $table->dropIndex(['workspace_id', 'status']);
            $table->dropColumn(['purpose', 'last_added', 'last_removed', 'skipped_no_consent', 'last_attempt_at', 'created_by']);
        });
        Schema::dropIfExists('onsite_banners');
        Schema::dropIfExists('commerce_settings');
        Schema::table('product_watches', function (Blueprint $table) {
            $table->dropIndex('product_watches_due');
            $table->dropColumn(['price_at_subscribe', 'notified_price', 'next_attempt_at', 'attempts', 'last_reason']);
        });
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn(['purchases_7d', 'purchases_90d', 'trend_score', 'stats_computed_at']));
    }
};
