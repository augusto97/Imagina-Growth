<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * «Filtra a los curiosos» (docs/auditoria-2026-11.md §5.1 función 3, modulos.md M18):
 * - lead_qualification_sets: las preguntas de calificación que el agente de ventas hace
 *   en el chat, por pipeline o por servicio (texto, tipo de respuesta, obligatorias,
 *   puntos y qué respuesta descalifica) y las reglas de derivación según respuestas y
 *   puntaje (al dueño, a un vendedor, seguir con la IA o a la web).
 * - leads.qualification: las respuestas del cliente con su frase de origen, el puntaje,
 *   si quedó descalificado y la derivación aplicada. Va cifrada: son palabras del cliente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_qualification_sets', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26);
            $table->string('name', 80);
            $table->char('pipeline_id', 26)->nullable();       // solo los leads de ese pipeline (null: todos)
            $table->json('services')->nullable();               // palabras del servicio del lead (vacío: todos)
            $table->boolean('active')->default(true);
            $table->json('questions');                          // [{key, text, type, required, options[], min, max, points, out_of_range}]
            $table->json('routing');                            // {rules: [{when, value?, question?, option?, action, link?, url?}], default: {...}}
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
            $table->index(['workspace_id', 'active', 'position']);
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->text('qualification')->nullable()->after('brief_updated_at');
            $table->timestamp('qualified_at')->nullable()->after('qualification');
        });
    }

    public function down(): void
    {
        Schema::table('leads', fn (Blueprint $table) => $table->dropColumn(['qualification', 'qualified_at']));
        Schema::dropIfExists('lead_qualification_sets');
    }
};
