<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * M18 Leads (fork S), sobre 2026_11_23_000100 (modelo-de-datos.md §15):
 * - lead_settings: reglas del puntaje, umbrales, avisos, asignación, área de servicio
 *   y si los WhatsApp de compra crean leads (una fila por espacio de trabajo).
 * - lead_sellers: quién atiende los leads, con su WhatsApp (cifrado) para el aviso
 *   al instante y la última asignación (turnos).
 * - leads: próximo paso y cuándo salió la conversión offline (lead ganado).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_settings', function (Blueprint $table) {
            $table->char('workspace_id', 26)->primary();
            $table->json('scoring')->nullable();
            $table->json('alerts')->nullable();
            $table->json('assignment')->nullable();
            $table->boolean('whatsapp_leads')->default(true);
            $table->boolean('ai_qualify')->default(true);
            $table->timestamps();
        });

        Schema::create('lead_sellers', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26);
            $table->char('user_id', 26);
            $table->text('phone')->nullable();               // cifrado (E.164)
            $table->json('services')->nullable();            // palabras del servicio que atiende (reglas)
            $table->boolean('active')->default(true);
            $table->timestamp('last_assigned_at')->nullable();
            $table->timestamps();
            $table->unique(['workspace_id', 'user_id']);
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->string('next_step', 255)->nullable()->after('lost_reason');
            $table->timestamp('next_step_at')->nullable()->after('next_step');
            $table->timestamp('offline_conversion_at')->nullable()->after('won_at');
        });
    }

    public function down(): void
    {
        Schema::table('leads', fn (Blueprint $table) => $table->dropColumn(['next_step', 'next_step_at', 'offline_conversion_at']));
        Schema::dropIfExists('lead_sellers');
        Schema::dropIfExists('lead_settings');
    }
};
