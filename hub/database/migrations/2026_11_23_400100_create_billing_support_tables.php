<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * M21 (planes y facturación), sobre 2026_11_23_000100 (modelo-de-datos.md §15):
 * - workspace_feature_overrides: el equipo de la plataforma activa o apaga un
 *   módulo para un espacio de trabajo por encima de su plan (Pennant lo resuelve).
 * - plan_change_requests: el dueño pide cambiar de plan; la plataforma lo aplica
 *   (no hay cobro automático en la v1).
 * - billing_invoices.account-level: se agregan el resumen de pago y quién la emitió.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_feature_overrides', function (Blueprint $table) {
            $table->char('workspace_id', 26);
            $table->string('feature', 32);
            $table->boolean('enabled');
            $table->char('set_by', 26)->nullable();
            $table->string('reason', 255)->nullable();
            $table->timestamps();
            $table->primary(['workspace_id', 'feature']);
        });

        Schema::create('plan_change_requests', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('account_id', 26);
            $table->char('workspace_id', 26)->nullable();
            $table->char('user_id', 26)->nullable();
            $table->string('from_plan', 40)->nullable();
            $table->string('to_plan', 40);
            $table->string('note', 500)->nullable();
            $table->string('status', 16)->default('pending'); // pending | applied | rejected
            $table->char('handled_by', 26)->nullable();
            $table->timestamp('handled_at')->nullable();
            $table->timestamps();
            $table->index(['account_id', 'status']);
        });

        Schema::table('billing_invoices', function (Blueprint $table) {
            $table->string('pdf_path', 255)->nullable()->after('payment_url');
            $table->timestamp('sent_at')->nullable()->after('pdf_path');
            $table->char('issued_by', 26)->nullable()->after('paid_at');
            $table->string('notes', 500)->nullable()->after('issued_by');
        });
    }

    public function down(): void
    {
        Schema::table('billing_invoices', fn (Blueprint $table) => $table->dropColumn(['pdf_path', 'sent_at', 'issued_by', 'notes']));
        Schema::dropIfExists('plan_change_requests');
        Schema::dropIfExists('workspace_feature_overrides');
    }
};
