<?php

use App\Domain\Billing\Plans\PlanCatalog;
use App\Domain\Billing\Plans\PlatformAccounts;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * «Sin plan» deja de ser «todo» (docs/auditoria-2026-11.md S3):
 * - crea los planes `diagnostico` (el gratuito que rige a las cuentas sin plan) y `plataforma` (uso interno, todo y
 *   sin topes) si faltan, sin pisar los editados;
 * - asigna `plataforma` a las cuentas sin plan del superadmin (las de su agencia o donde es dueño), para que quien
 *   administra no pierda módulos al actualizar;
 * - olvida los módulos que Pennant guardó por espacio de trabajo (`module.*`): se resolvieron con la regla vieja y
 *   sin esto las cuentas sin plan seguirían con todo.
 * Las demás cuentas sin plan pasan a Diagnóstico: el superadmin les asigna su plan en Plataforma → Clientes
 * (`igr:doctor` las lista).
 */
return new class extends Migration
{
    public function up(): void
    {
        PlanCatalog::ensure(PlanCatalog::DEFAULT_PLAN);
        PlanCatalog::ensure(PlanCatalog::platformPlanId());
        PlatformAccounts::grant();
        DB::table('features')->where('name', 'like', 'module.%')->delete();
    }

    public function down(): void
    {
        // Los planes y las asignaciones se quedan: quitarlos dejaría cuentas sin el plan que ya usan.
    }
};
