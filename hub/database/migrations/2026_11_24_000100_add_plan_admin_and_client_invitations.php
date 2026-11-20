<?php

use App\Domain\Billing\Plans\PlanCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Administración de planes y clientes desde la plataforma:
 * - los planes se editan en el panel (archivar en lugar de borrar: hay cuentas que los usan);
 * - una invitación puede dar también un rol en la cuenta (el dueño de una agencia cliente);
 * - los planes por defecto (config/igr-plans.php) se cargan si la tabla está vacía: antes solo se cargaban con
 *   `igr:plans:sync`, que ni el instalador ni el actualizador corrían, y el selector de plan quedaba vacío.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable();
        });
        Schema::table('workspace_invitations', function (Blueprint $table) {
            $table->string('account_role', 20)->nullable();
        });
        if (DB::table('plans')->count() === 0) {
            app(PlanCatalog::class)->sync();
        }
    }

    public function down(): void
    {
        Schema::table('workspace_invitations', function (Blueprint $table) {
            $table->dropColumn('account_role');
        });
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('archived_at');
        });
    }
};
