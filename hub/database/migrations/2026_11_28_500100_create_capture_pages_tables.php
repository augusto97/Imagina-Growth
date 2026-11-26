<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Captación sin tienda (rediseño v2 §11.3, modulos.md M12 «Páginas de captura y QR»):
 *
 * - `landing_pages`: páginas de captura alojadas en el hub (`/p/{slug}`), con su contenido, campos, casillas de
 *   consentimiento por canal y ajustes. El slug es único en toda la plataforma (la URL no lleva el espacio de trabajo).
 * - `landing_submissions`: cada envío, con el contacto, el lead, de dónde vino (UTM, `ref`, identificadores de clic, si
 *   llegó por el QR) y lo que escribió la persona cifrado (para verlo en el panel; se borra con la supresión).
 * - `whatsapp_links`: chats de WhatsApp con un código de atribución (`ref: …` en el mensaje de alta): el QR impreso
 *   (`kind = qr`) y el botón de WhatsApp de una página (`kind = page`). `/p/w/{código}` cuenta el escaneo o el clic y
 *   redirige a wa.me con el número conectado.
 * - `whatsapp_link_hits`: cada chat que llegó con el código (contacto, mensaje, si era nuevo, lead y la pregunta de
 *   consentimiento).
 * - `capture_daily`: vistas, visitantes y envíos por día (UTC), página o enlace y vía (`qr` o `link`). Sin datos
 *   personales: los visitantes únicos se cuentan con un hash de la IP que vive un día en la caché.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('landing_pages', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26);
            $table->char('site_id', 26)->nullable();          // sitio con el que se registran los contactos (opcional)
            $table->string('slug', 80)->unique();
            $table->string('name', 160);                       // nombre interno
            $table->string('status', 16)->default('draft');    // draft | published | archived
            $table->json('content')->nullable();               // título, texto, logo, imagen, color, botón, gracias, redirección, WhatsApp
            $table->json('fields')->nullable();                // [{key, label, type, required, options?}]
            $table->json('consent_channels')->nullable();      // canales con casilla (sin marcar)
            $table->json('settings')->nullable();              // indexable (noindex por defecto), dato de contacto exigido
            $table->char('wa_link_id', 26)->nullable();        // el botón de WhatsApp con atribución
            $table->timestamp('published_at')->nullable();
            $table->char('created_by', 26)->nullable();
            $table->timestamps();
            $table->index(['workspace_id', 'status']);
        });

        Schema::create('landing_submissions', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26);
            $table->char('page_id', 26);
            $table->char('contact_id', 26)->nullable();
            $table->char('lead_id', 26)->nullable();
            $table->string('via', 8)->default('link');          // qr | link
            $table->json('source')->nullable();                  // canal, UTM, ref, identificadores de clic (sin datos personales)
            $table->text('data')->nullable();                    // cifrado: lo que escribió la persona
            $table->json('consents')->nullable();                // canales con la casilla marcada
            $table->boolean('was_known')->default(false);
            $table->timestamp('created_at');
            $table->index(['workspace_id', 'page_id', 'created_at']);
            $table->index(['workspace_id', 'contact_id']);
        });

        Schema::create('whatsapp_links', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26);
            $table->char('page_id', 26)->nullable();             // el botón de una página de captura
            $table->char('channel_account_id', 26)->nullable();  // null: el número de WhatsApp por defecto
            $table->string('kind', 8)->default('qr');            // qr | page
            $table->string('name', 120);
            $table->string('code', 12)->unique();                // va en el mensaje como «ref: CÓDIGO»
            $table->string('message', 500);                      // el mensaje de alta que se prellena
            $table->string('status', 16)->default('active');     // active | archived
            $table->json('settings')->nullable();                // create_lead, ask_consent, service
            $table->char('created_by', 26)->nullable();
            $table->timestamps();
            $table->index(['workspace_id', 'kind', 'status']);
        });

        Schema::create('whatsapp_link_hits', function (Blueprint $table) {
            $table->id();
            $table->char('workspace_id', 26);
            $table->char('link_id', 26);
            $table->char('contact_id', 26);
            $table->char('message_id', 26);
            $table->boolean('was_new')->default(false);
            $table->char('lead_id', 26)->nullable();
            $table->timestamp('consent_asked_at')->nullable();
            $table->timestamp('consent_granted_at')->nullable();
            $table->timestamp('created_at');
            $table->unique(['link_id', 'message_id']);
            $table->index(['workspace_id', 'link_id', 'created_at']);
            $table->index(['workspace_id', 'contact_id']);
        });

        Schema::create('capture_daily', function (Blueprint $table) {
            $table->char('workspace_id', 26);
            $table->string('subject_type', 8);                   // page | wa_link
            $table->char('subject_id', 26);
            $table->date('day');
            $table->string('via', 8);                            // qr | link
            $table->unsignedInteger('views')->default(0);
            $table->unsignedInteger('visitors')->default(0);
            $table->unsignedInteger('submissions')->default(0);
            $table->primary(['workspace_id', 'subject_type', 'subject_id', 'day', 'via'], 'capture_daily_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('capture_daily');
        Schema::dropIfExists('whatsapp_link_hits');
        Schema::dropIfExists('whatsapp_links');
        Schema::dropIfExists('landing_submissions');
        Schema::dropIfExists('landing_pages');
    }
};
