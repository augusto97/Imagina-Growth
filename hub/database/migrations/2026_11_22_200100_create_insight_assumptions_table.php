<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Hallazgos de la Fase 4 (inteligencia.md §5, modelo-de-datos.md §14):
 * - insight_assumptions: los supuestos del impacto que la comerciante cambió
 *   (sin fila = el valor por defecto de config/igr-insights.php).
 * - insights.valid_until: vigencia del hallazgo; vencida, pasa a «expired»
 *   aunque su detector no corra (los semanales o uno que falla).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('insight_assumptions', function (Blueprint $table) {
            $table->char('workspace_id', 26);
            $table->string('key', 40);
            $table->decimal('value', 12, 6);
            $table->char('updated_by', 26)->nullable();
            $table->timestamps();
            $table->primary(['workspace_id', 'key']);
        });

        Schema::table('insights', function (Blueprint $table) {
            $table->timestamp('valid_until')->nullable()->after('period_end');
            $table->index(['workspace_id', 'detector_key', 'fingerprint'], 'insights_fingerprint');
        });
    }

    public function down(): void
    {
        Schema::table('insights', function (Blueprint $table) {
            $table->dropIndex('insights_fingerprint');
            $table->dropColumn('valid_until');
        });
        Schema::dropIfExists('insight_assumptions');
    }
};
