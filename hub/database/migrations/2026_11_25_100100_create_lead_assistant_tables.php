<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Bloque «ARM» de leads (docs/auditoria-2026-11.md §5.1, modulos.md M18):
 * - leads.brief: la ficha que la IA llena desde la conversación (qué necesita,
 *   cantidad, ciudad o zona, para cuándo, presupuesto y temperatura, cada valor
 *   con su frase de origen y quién lo puso). Va cifrada: son palabras del cliente.
 * - leads.stage_changed_at: desde cuándo está en su etapa (leads quietos en «Hoy»
 *   y estancados en el embudo semanal). Se llena con la historia que ya existe.
 * - lead_settings.ai_brief y assistant: apagar la ficha con IA; días para «quieto»,
 *   plantilla de seguimiento fuera de la ventana de 24 h y resumen matutino.
 * - lead_tasks: tareas y llamadas del vendedor (las crea una persona o el nodo de
 *   flujo `crm_task`); salen en «Hoy».
 * - lead_assistant_marks: lo que el vendedor marcó hecho o pospuso en «Hoy».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->text('brief')->nullable()->after('score_reasons');
            $table->timestamp('brief_updated_at')->nullable()->after('brief');
            $table->timestamp('stage_changed_at')->nullable()->after('stage');
        });
        // Leads que ya existen: el último cambio de etapa de su historia o, si no hay, su creación.
        DB::statement("UPDATE leads l SET stage_changed_at = COALESCE(
            (SELECT MAX(a.created_at) FROM lead_activities a WHERE a.lead_id = l.id AND a.kind = 'stage'), l.created_at)");

        Schema::table('lead_settings', function (Blueprint $table) {
            $table->boolean('ai_brief')->default(true)->after('ai_qualify');
            $table->json('assistant')->nullable()->after('ai_brief');
        });

        Schema::create('lead_tasks', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26);
            $table->char('lead_id', 26)->nullable();
            $table->char('contact_id', 26)->nullable();
            $table->char('owner_id', 26)->nullable();          // vendedor (usuario); sin dueño la ven quienes ven todo
            $table->string('kind', 16)->default('task');       // task | call
            $table->string('title', 160);
            $table->text('note')->nullable();                  // cifrada: la escribe una persona
            $table->timestamp('due_at');
            $table->string('status', 12)->default('open');     // open | done
            $table->string('source', 12)->default('user');     // user | flow
            $table->string('source_ref', 120)->nullable();      // clave del paso del flujo: no se duplica en un reintento
            $table->char('created_by', 26)->nullable();
            $table->timestamp('done_at')->nullable();
            $table->char('done_by', 26)->nullable();
            $table->timestamps();
            $table->unique(['workspace_id', 'source_ref']);
            $table->index(['workspace_id', 'status', 'owner_id', 'due_at']);
            $table->index(['workspace_id', 'lead_id']);
        });

        Schema::create('lead_assistant_marks', function (Blueprint $table) {
            $table->id();
            $table->char('workspace_id', 26);
            $table->string('item_key', 120);                   // tipo:lead:momento (una situación nueva no hereda la marca)
            $table->char('lead_id', 26)->nullable();
            $table->string('status', 12);                      // done | snoozed
            $table->timestamp('until')->nullable();            // pospuesto hasta
            $table->char('user_id', 26)->nullable();
            $table->timestamps();
            $table->unique(['workspace_id', 'item_key']);
            $table->index(['workspace_id', 'lead_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_assistant_marks');
        Schema::dropIfExists('lead_tasks');
        Schema::table('lead_settings', fn (Blueprint $table) => $table->dropColumn(['ai_brief', 'assistant']));
        Schema::table('leads', fn (Blueprint $table) => $table->dropColumn(['brief', 'brief_updated_at', 'stage_changed_at']));
    }
};
