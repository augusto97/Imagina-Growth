<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Rediseño v2 (docs/diseno/rediseno-v2.md §4 y §6):
 *
 * - `workspace_strategies`: lo que la persona decidió con cada estrategia (activa o en pausa), quién y cuándo, y qué
 *   bloques creó o tocó (`refs`: flujos, widgets…) y lo que hay que devolver al reanudar (`settings`). El estado real
 *   se sigue leyendo de los bloques (el flujo, el widget, el programa): esta fila solo guarda la intención.
 * - `workspace_onboarding`: el asistente de configuración del espacio de trabajo (tipo de negocio, paso donde quedó,
 *   saltado o terminado). Los pasos hechos se derivan de los datos reales, no se guardan.
 * - `onboarding_user_states`: lo de cada persona en cada espacio de trabajo (si ya vio la bienvenida).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_strategies', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26);
            $table->string('strategy_key', 40);
            $table->string('status', 16);                      // active | paused
            $table->json('refs')->nullable();                  // {flows: [ulid], widgets: [ulid], …}
            $table->json('settings')->nullable();              // valores para reanudar (p. ej. las horas de los recordatorios)
            $table->timestamp('activated_at')->nullable();
            $table->char('activated_by', 26)->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->char('paused_by', 26)->nullable();
            $table->timestamps();
            $table->unique(['workspace_id', 'strategy_key']);
        });

        Schema::create('workspace_onboarding', function (Blueprint $table) {
            $table->char('workspace_id', 26)->primary();
            $table->string('business_type', 16)->nullable();   // store | services | both
            $table->string('current_step', 24)->nullable();    // business | connect | history | channels | strategies | done
            $table->timestamp('skipped_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->char('updated_by', 26)->nullable();
            $table->timestamps();
        });

        Schema::create('onboarding_user_states', function (Blueprint $table) {
            $table->char('workspace_id', 26);
            $table->char('user_id', 26);
            $table->timestamp('welcome_seen_at')->nullable();
            $table->timestamps();
            $table->primary(['workspace_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('onboarding_user_states');
        Schema::dropIfExists('workspace_onboarding');
        Schema::dropIfExists('workspace_strategies');
    }
};
