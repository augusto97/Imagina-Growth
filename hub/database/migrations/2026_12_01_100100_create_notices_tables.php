<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Avisos (lote 0.4.0, docs/avisos.md):
 * - `notices`: el centro de avisos de la app, por persona y espacio de trabajo (nueva cita, lead, conversación asignada,
 *   conexión caída). Título y detalle cifrados: pueden llevar el nombre del contacto.
 * - `notice_log`: qué se le avisó a quién por cada cita o lead, por qué canal y, si no salió, por qué (la sección «Avisos»).
 * - `notice_preferences`: «Mis avisos» de cada persona (qué y por dónde) y su WhatsApp para los avisos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notices', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26);
            $table->char('user_id', 26);
            $table->string('kind', 40);                     // appointment.booked | appointment.rescheduled | appointment.cancelled | appointment.confirmed | lead.new | lead.hot | lead.qualified | conversation.assigned | connection.alert
            $table->text('title');                          // cifrado
            $table->text('body')->nullable();               // cifrado
            $table->string('url', 500)->nullable();         // ruta del panel (/w/ws_…/…)
            $table->string('subject_type', 20)->nullable(); // appointment | lead | conversation | connection
            $table->char('subject_id', 26)->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->index(['workspace_id', 'user_id', 'read_at', 'created_at']);
        });

        Schema::create('notice_log', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26);
            $table->string('subject_type', 20);             // appointment | lead
            $table->char('subject_id', 26);
            $table->string('audience', 10);                 // customer | team
            $table->string('event', 32);                    // confirmation | reminder_24 | reminder_2 | rescheduled | cancelled | booked | confirmed | lead_new | lead_hot | lead_qualified
            $table->string('channel', 16)->nullable();      // whatsapp | email | in_app | push | null (no salió por ninguno)
            $table->text('recipient')->nullable();          // cifrado: a quién (enmascarado o nombre de la persona del equipo)
            $table->string('status', 16);                   // queued | sent | deferred | denied | skipped | failed
            $table->string('reason', 64)->nullable();       // por qué no salió (no_email, no_channel, plan_no_platform_email…)
            $table->char('message_id', 26)->nullable();     // el mensaje de `messages`, si se creó
            $table->char('user_id', 26)->nullable();        // la persona del equipo avisada
            $table->timestamp('created_at')->nullable();
            $table->index(['workspace_id', 'subject_type', 'subject_id', 'created_at']);
        });

        Schema::create('notice_preferences', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26);
            $table->char('user_id', 26);
            $table->json('settings');                       // {grupo: {in_app, email, push, whatsapp, scope}}
            $table->text('phone')->nullable();              // cifrado: WhatsApp para los avisos
            $table->timestamps();
            $table->unique(['workspace_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notice_preferences');
        Schema::dropIfExists('notice_log');
        Schema::dropIfExists('notices');
    }
};
