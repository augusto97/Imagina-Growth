<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M01: cuentas, espacios de trabajo, membresías, invitaciones y auditoría.
 * Ver docs/modelo-de-datos.md §1.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->string('type', 16); // agency | direct
            $table->string('name');
            $table->string('legal_name')->nullable();
            $table->string('tax_id', 40)->nullable();
            $table->char('country', 2)->default('CO');
            $table->string('billing_email')->nullable();
            $table->string('plan_id', 40)->nullable();
            $table->string('status', 16)->default('active');
            $table->timestamps();
        });

        Schema::create('account_user', function (Blueprint $table) {
            $table->char('account_id', 26);
            $table->char('user_id', 26);
            $table->string('role', 20); // agency_owner | agency_member
            $table->timestamps();
            $table->primary(['account_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::create('workspaces', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('account_id', 26)->index();
            $table->string('name');
            $table->string('slug', 80);
            $table->char('country', 2)->default('CO');
            $table->string('timezone', 64)->default('America/Bogota');
            $table->char('currency', 3)->default('COP');
            $table->string('locale', 10)->default('es-CO');
            $table->string('industry', 40)->nullable();
            $table->string('legal_name')->nullable();
            $table->string('privacy_policy_url', 2048)->nullable();
            $table->json('settings')->nullable();
            $table->json('brand_kit')->nullable();
            $table->string('status', 16)->default('active');
            $table->timestamps();
            $table->unique(['account_id', 'slug']);
        });
        // ID numérico para ClickHouse (events.workspace_id UInt32).
        DB::statement('ALTER TABLE workspaces ADD ch_id INT UNSIGNED NOT NULL AUTO_INCREMENT UNIQUE AFTER id');

        Schema::create('workspace_user', function (Blueprint $table) {
            $table->char('workspace_id', 26);
            $table->char('user_id', 26);
            $table->string('role', 20); // owner | admin | marketing | agent | viewer
            $table->json('notifications')->nullable();
            $table->timestamps();
            $table->primary(['workspace_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::create('workspace_invitations', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26)->index();
            $table->string('email');
            $table->string('role', 20);
            $table->char('token_hash', 64)->unique();
            $table->char('invited_by', 26)->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
            $table->index(['workspace_id', 'email']);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->char('workspace_id', 26)->nullable();
            $table->char('account_id', 26)->nullable();
            $table->char('user_id', 26)->nullable();
            $table->string('action', 64);
            $table->string('subject_type', 64)->nullable();
            $table->string('subject_id', 64)->nullable();
            $table->json('changes')->nullable();
            $table->char('ip_hash', 64)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['workspace_id', 'created_at']);
            $table->index(['account_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('workspace_invitations');
        Schema::dropIfExists('workspace_user');
        Schema::dropIfExists('workspaces');
        Schema::dropIfExists('account_user');
        Schema::dropIfExists('accounts');
    }
};
