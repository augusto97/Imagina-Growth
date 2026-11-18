<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M02: sitios, códigos de conexión y rechazos de ingesta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sites', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26);
            $table->string('platform', 20); // woocommerce | wordpress
            $table->string('name')->nullable();
            $table->string('url', 2048);
            $table->string('host', 255);
            $table->string('rest_url', 2048)->nullable();
            $table->json('domains')->nullable();
            $table->string('public_key', 40)->unique();
            $table->text('secret'); // cifrado
            $table->timestamp('secret_rotated_at')->nullable();
            $table->text('previous_secret')->nullable(); // cifrado, vale 24 h tras rotar
            $table->timestamp('previous_secret_expires_at')->nullable();
            $table->string('status', 16)->default('pending'); // pending | connected | degraded | disconnected
            $table->string('timezone', 64)->nullable();
            $table->char('currency', 3)->nullable();
            $table->string('plugin_version', 20)->nullable();
            $table->string('wp_version', 20)->nullable();
            $table->string('wc_version', 20)->nullable();
            $table->string('php_version', 20)->nullable();
            $table->json('capabilities')->nullable();
            $table->json('settings')->nullable(); // estados pagados, contraentrega, pasarelas offline
            $table->json('backfill_state')->nullable();
            $table->json('health')->nullable(); // último heartbeat: outbox, errores
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('last_event_at')->nullable();
            $table->string('tracking_domain')->nullable();
            $table->timestamps();
            $table->index(['workspace_id', 'status']);
            $table->index(['workspace_id', 'host']);
        });
        DB::statement('ALTER TABLE sites ADD ch_id INT UNSIGNED NOT NULL AUTO_INCREMENT UNIQUE AFTER id');

        Schema::create('site_connect_codes', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26)->index();
            $table->char('code_hash', 64)->unique();
            $table->string('site_url', 2048)->nullable();
            $table->string('site_host', 255)->nullable();
            $table->string('nonce', 64)->nullable();
            $table->string('return_url', 2048)->nullable();
            $table->string('platform', 20)->nullable();
            $table->string('name')->nullable();
            $table->char('created_by', 26)->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->char('site_id', 26)->nullable();
            $table->timestamps();
        });

        Schema::create('ingest_rejections', function (Blueprint $table) {
            $table->id();
            $table->char('workspace_id', 26);
            $table->char('site_id', 26)->nullable();
            $table->string('event_id', 36)->nullable();
            $table->string('event', 64)->nullable();
            $table->string('reason', 40);
            $table->text('detail')->nullable();
            $table->json('sample')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['workspace_id', 'site_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ingest_rejections');
        Schema::dropIfExists('site_connect_codes');
        Schema::dropIfExists('sites');
    }
};
