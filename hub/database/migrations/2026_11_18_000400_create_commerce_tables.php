<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Comercio (modelo-de-datos.md §4): proyección de pedidos, productos y categorías.
 * En `orders`, `created_at` y `updated_at` son las fechas de WooCommerce
 * (creación y última modificación); `projected_at` es cuándo lo escribió el hub.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26);
            $table->char('site_id', 26);
            $table->string('external_id', 64);
            $table->string('parent_external_id', 64)->nullable();
            $table->string('type', 40);
            $table->string('sku', 100)->nullable();
            $table->string('name', 300);
            $table->string('slug', 300)->nullable();
            $table->string('permalink', 2048)->nullable();
            $table->string('image_url', 2048)->nullable();
            $table->json('category_ids')->nullable();
            $table->string('brand', 120)->nullable();
            $table->decimal('price', 18, 2)->default(0);
            $table->decimal('regular_price', 18, 2)->nullable();
            $table->decimal('sale_price', 18, 2)->nullable();
            $table->char('currency', 3)->nullable();
            $table->string('stock_status', 20)->nullable();
            $table->decimal('stock_qty', 14, 2)->nullable();
            $table->decimal('rating_avg', 3, 2)->nullable();
            $table->unsignedInteger('rating_count')->nullable();
            $table->decimal('cost', 18, 2)->nullable();
            $table->string('status', 20);
            $table->string('content_hash', 64);
            $table->unsignedInteger('views_30d')->default(0);
            $table->unsignedInteger('purchases_30d')->default(0);
            $table->decimal('view_to_purchase_30d', 7, 4)->nullable();
            $table->timestamp('source_updated_at', 3)->nullable(); // occurred_at del último evento aplicado
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
            $table->unique(['site_id', 'external_id']);
            $table->index(['workspace_id', 'site_id']);
        });

        Schema::create('product_categories', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26);
            $table->char('site_id', 26);
            $table->string('external_id', 64);
            $table->string('parent_external_id', 64)->nullable();
            $table->string('name', 200);
            $table->string('slug', 200)->nullable();
            $table->string('content_hash', 64)->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
            $table->unique(['site_id', 'external_id']);
            $table->index(['workspace_id', 'site_id']);
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26);
            $table->char('site_id', 26);
            $table->string('external_id', 64);
            $table->string('number', 64)->nullable();
            $table->char('contact_id', 26)->nullable();
            $table->string('status', 40);
            $table->boolean('is_paid')->default(false);
            $table->boolean('is_cod')->default(false);   // contraentrega: venta «por confirmar» hasta cobrarse
            $table->timestamp('paid_at')->nullable();
            $table->char('currency', 3);
            $table->decimal('subtotal', 18, 2)->default(0);
            $table->decimal('discount', 18, 2)->default(0);
            $table->decimal('shipping', 18, 2)->default(0);
            $table->decimal('tax', 18, 2)->default(0);
            $table->decimal('total', 18, 2)->default(0);
            $table->decimal('refunded', 18, 2)->default(0);
            $table->decimal('total_ws', 18, 2)->nullable();
            $table->decimal('net_ws', 18, 2)->nullable();
            $table->decimal('cost_ws', 18, 2)->nullable();
            $table->string('payment_method_id', 100)->nullable();
            $table->string('payment_method_title', 200)->nullable();
            $table->boolean('is_offline_payment')->default(false);
            $table->string('shipping_city', 120)->nullable();
            $table->string('shipping_region_code', 32)->nullable();
            $table->char('shipping_country', 2)->nullable();
            $table->json('coupon_codes')->nullable();
            $table->unsignedInteger('items_count')->default(0);
            $table->boolean('is_first_order')->default(false);
            $table->unsignedInteger('order_index')->nullable();
            $table->string('created_via', 40)->nullable();
            $table->string('customer_ref', 80)->nullable();
            $table->string('channel_first', 40)->nullable();
            $table->string('channel_last', 40)->nullable();
            $table->json('utm_last')->nullable();
            $table->json('wc_attribution')->nullable();
            $table->char('attributed_message_id', 26)->nullable();
            $table->char('attributed_flow_id', 26)->nullable();
            $table->char('attributed_campaign_id', 26)->nullable();
            $table->char('attributed_referral_id', 26)->nullable();
            $table->char('attributed_affiliate_id', 26)->nullable();
            $table->timestamp('created_at', 3)->nullable();  // fecha del pedido en la tienda
            $table->timestamp('updated_at', 3)->nullable();  // última modificación en la tienda
            $table->timestamp('projected_at', 3)->nullable();

            $table->unique(['site_id', 'external_id']);
            $table->index(['workspace_id', 'created_at']);
            $table->index(['workspace_id', 'paid_at']);
            $table->index(['workspace_id', 'contact_id']);
            $table->index(['site_id', 'status']);
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26);
            $table->char('order_id', 26)->index();
            $table->char('product_id', 26)->nullable();
            $table->string('external_item_id', 64)->nullable();
            $table->string('external_product_id', 64);
            $table->string('external_variation_id', 64)->nullable();
            $table->string('sku', 100)->nullable();
            $table->string('name', 300);
            $table->json('category_ids')->nullable();
            $table->string('brand', 120)->nullable();
            $table->decimal('qty', 14, 2);
            $table->decimal('unit_price', 18, 2);
            $table->decimal('line_total', 18, 2);
            $table->decimal('cost', 18, 2)->nullable();
            $table->index(['workspace_id', 'external_product_id']);
        });

        Schema::create('fx_rates', function (Blueprint $table) {
            $table->date('date');
            $table->char('base', 3);
            $table->char('quote', 3);
            $table->decimal('rate', 20, 10);
            $table->primary(['date', 'base', 'quote']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fx_rates');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('product_categories');
        Schema::dropIfExists('products');
    }
};
