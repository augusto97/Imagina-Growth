<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Leads de anuncios (rediseño v2 §11.3, modulos.md M18 «Leads de anuncios»):
 *
 * - `lead_ad_sources`: de dónde llegan. Meta Lead Ads: una página de Facebook suscrita al campo `leadgen` (con el token
 *   de la página cifrado; una página en un solo espacio de trabajo). Formularios de clientes potenciales de Google Ads:
 *   uno por espacio de trabajo, con el token de su URL de webhook y la clave que Google manda en `google_key` (cifrada).
 *   `settings.forms` guarda por formulario qué casilla o pregunta es el consentimiento de cada canal y cuál el servicio.
 * - `lead_ad_leads`: cada lead recibido, idempotente por (espacio de trabajo, plataforma, ID del lead), con la campaña,
 *   el conjunto o grupo, el anuncio y el formulario, si es de prueba, su estado y lo que respondió la persona (cifrado).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_ad_sources', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26);
            $table->string('platform', 16);                    // meta | google
            $table->string('external_id', 64)->nullable();     // Meta: ID de la página
            $table->string('name', 160)->nullable();
            $table->string('token', 40)->nullable()->unique();  // Google: el de la URL del webhook (glf_…)
            $table->text('secret')->nullable();                // cifrado: token de la página (Meta) o clave (Google)
            $table->string('status', 16)->default('active');   // active | error | disabled
            $table->string('last_error', 500)->nullable();
            $table->json('settings')->nullable();              // forms: {id: {name, consent: [{key, channel, text?}], service_key?}}
            $table->char('oauth_connection_id', 26)->nullable();
            $table->timestamp('last_lead_at')->nullable();
            $table->timestamp('test_received_at')->nullable();
            $table->timestamps();
            $table->index(['workspace_id', 'platform']);
            $table->index(['platform', 'external_id']);
        });

        Schema::create('lead_ad_leads', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26);
            $table->char('source_id', 26);
            $table->string('platform', 16);
            $table->string('external_id', 100);                // leadgen_id (Meta) o lead_id (Google)
            $table->string('form_id', 64)->nullable();
            $table->string('campaign_id', 64)->nullable();
            $table->string('adset_id', 64)->nullable();        // conjunto (Meta) o grupo de anuncios (Google)
            $table->string('ad_id', 64)->nullable();           // anuncio (Meta) o creatividad (Google)
            $table->boolean('is_test')->default(false);
            $table->string('status', 16)->default('received'); // received | processed | failed | test
            $table->string('error', 500)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->char('contact_id', 26)->nullable();
            $table->char('lead_id', 26)->nullable();
            $table->text('data')->nullable();                  // cifrado: respuestas y datos del anuncio
            $table->json('consents')->nullable();              // canales con consentimiento registrado
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->unique(['workspace_id', 'platform', 'external_id']);
            $table->index(['workspace_id', 'source_id', 'received_at']);
            $table->index(['workspace_id', 'contact_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_ad_leads');
        Schema::dropIfExists('lead_ad_sources');
    }
};
