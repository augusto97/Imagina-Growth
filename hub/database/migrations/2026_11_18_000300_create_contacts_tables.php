<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contactos e identidad (modelo-de-datos.md §2). En la Fase 0 se llenan los
 * datos de identidad y los contadores de compra; los campos calculados de
 * inteligencia (RFM, predicciones) se agregan con su fase.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26);
            $table->text('email')->nullable();          // cifrado
            $table->char('email_bidx', 64)->nullable();
            $table->text('phone')->nullable();          // cifrado, E.164
            $table->char('phone_bidx', 64)->nullable();
            $table->text('first_name')->nullable();     // cifrado
            $table->text('last_name')->nullable();      // cifrado
            $table->json('name_prefix_bidx')->nullable(); // índices ciegos de prefijos del nombre para la búsqueda
            $table->date('birthday')->nullable();
            $table->string('city', 120)->nullable();
            $table->string('region_code', 16)->nullable();
            $table->char('country', 2)->nullable();
            $table->string('locale', 10)->nullable();
            $table->string('timezone', 64)->nullable();
            $table->string('lifecycle_stage', 16)->default('visitor');
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->json('first_source')->nullable();
            $table->json('first_order_source')->nullable();
            $table->string('hdyhau', 120)->nullable();
            $table->unsignedInteger('orders_count')->default(0);
            $table->decimal('revenue_total_ws', 18, 2)->default(0);
            $table->decimal('aov_ws', 18, 2)->default(0);
            $table->timestamp('first_order_at')->nullable();
            $table->timestamp('last_order_at')->nullable();
            $table->decimal('avg_days_between_orders', 8, 2)->nullable();
            $table->unsignedInteger('refunds_count')->default(0);
            $table->decimal('coupon_orders_ratio', 5, 4)->nullable();
            $table->json('custom_attributes')->nullable();
            $table->json('tags')->nullable();
            $table->char('merged_into_id', 26)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['workspace_id', 'email_bidx']);
            $table->index(['workspace_id', 'phone_bidx']);
            $table->index(['workspace_id', 'last_order_at']);
            $table->index(['workspace_id', 'lifecycle_stage']);
        });

        Schema::create('contact_identities', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26);
            $table->char('contact_id', 26)->index();
            $table->string('type', 20); // anonymous_id | customer_ref | email_hash | phone_hash | wa_id | link_token | external
            $table->string('value', 128);
            $table->char('site_id', 26)->nullable();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->unique(['workspace_id', 'type', 'value']);
        });

        Schema::create('contact_merges', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26)->index();
            $table->char('winner_id', 26);
            $table->char('loser_id', 26);
            $table->string('reason', 64);
            $table->json('snapshot')->nullable();
            $table->timestamp('reverted_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_merges');
        Schema::dropIfExists('contact_identities');
        Schema::dropIfExists('contacts');
    }
};
