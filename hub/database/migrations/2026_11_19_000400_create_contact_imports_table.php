<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Importaciones CSV de contactos (modulos.md M03). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_imports', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26)->index();
            $table->char('user_id', 26)->nullable();
            $table->string('filename', 255);
            $table->string('path', 255)->nullable(); // archivo cifrado; se borra al terminar
            $table->json('mapping');
            $table->json('consent')->nullable();     // origen declarado: canales, fuente y fecha
            $table->string('status', 16)->default('queued');
            $table->json('totals')->nullable();
            $table->json('errors')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_imports');
    }
};
