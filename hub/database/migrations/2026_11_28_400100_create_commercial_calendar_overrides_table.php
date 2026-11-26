<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Excepciones del calendario comercial (rediseño v2 §11.5, modulos.md M10): las fechas que el organizador fija cada año
 * sin una regla (Hot Sale, El Buen Fin, los CyberLunes) o que cambian de un año a otro se corrigen desde Plataforma →
 * Calendario comercial. Una fila por momento, país y año; sin fila rige la regla del código. De la plataforma, no de
 * un espacio de trabajo (como `holiday_overrides`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commercial_calendar_overrides', function (Blueprint $table) {
            $table->id();
            $table->string('event_key', 40);                 // clave del momento (CommercialCalendar)
            $table->char('country', 2);                       // CO | MX | VE
            $table->unsignedSmallInteger('year');
            $table->date('starts_on')->nullable();            // fecha real del momento ese año (null = la de la regla)
            $table->date('ends_on')->nullable();              // último día (null = la duración de la regla)
            $table->boolean('disabled')->default(false);      // ese año no se hace (no sale en el calendario ni avisa)
            $table->string('note', 160)->nullable();          // de dónde salió la fecha (p. ej. «anuncio de la AMVO»)
            $table->char('created_by', 26)->nullable();       // users.id
            $table->timestamps();
            $table->unique(['event_key', 'country', 'year']);
            $table->index(['country', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commercial_calendar_overrides');
    }
};
