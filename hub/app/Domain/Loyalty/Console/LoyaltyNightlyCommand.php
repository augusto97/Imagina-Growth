<?php

declare(strict_types=1);

namespace App\Domain\Loyalty\Console;

use App\Domain\Loyalty\Actions\RecalculateAllTiers;
use App\Domain\Loyalty\Ledger\DatabaseLoyaltyLedger;
use App\Domain\Loyalty\Models\LoyaltyProgram;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Cada noche, por programa: vence los puntos cumplidos (`points.expired`), da
 * los puntos de cumpleaños, pasa al contacto vigente el saldo de los contactos
 * fusionados y recalcula los niveles VIP (la ventana de 12 meses avanza: un
 * nivel también puede bajar).
 */
final class LoyaltyNightlyCommand extends Command
{
    protected $signature = 'igr:loyalty:nightly {--now= : Fecha y hora de referencia (pruebas)} {--workspace= : Solo este espacio de trabajo}';

    protected $description = 'Vencimiento de puntos, cumpleaños, fusiones y niveles VIP';

    public function handle(CurrentWorkspace $current, DatabaseLoyaltyLedger $ledger, RecalculateAllTiers $recalculate): int
    {
        $now = $this->option('now') ? CarbonImmutable::parse((string) $this->option('now')) : CarbonImmutable::now();
        $programs = LoyaltyProgram::withoutWorkspaceScope()->whereIn('status', ['active', 'paused'])
            ->when($this->option('workspace'), fn ($q, $ws) => $q->where('workspace_id', $ws))->get();
        foreach ($programs as $program) {
            $workspace = Workspace::query()->find($program->workspace_id);
            if ($workspace === null) {
                continue;
            }
            $summary = $current->run($workspace, function () use ($workspace, $program, $ledger, $recalculate, $now): array {
                $ledger->forgetProgram();
                $out = ['expired' => 0, 'birthdays' => 0, 'merged' => 0, 'tiers' => 0];
                $local = $now->setTimezone($workspace->timezone ?: 'America/Bogota');
                $base = fn () => DB::table('contacts')->where('workspace_id', $workspace->id);

                // Vencimiento (también con el programa en pausa: la regla se le anunció a la persona).
                foreach ($base()->whereNull('merged_into_id')->whereNotNull('points_expiring_at')->where('points_expiring_at', '<=', $local->toDateString())->pluck('id') as $id) {
                    $out['expired'] += $this->safely(fn () => $ledger->expire((string) $id, $now)) ?? 0;
                }
                // Saldos de contactos fusionados.
                foreach ($base()->whereNotNull('merged_into_id')->where(fn ($q) => $q->where('points_balance', '!=', 0)->orWhere('wallet_balance_ws', '!=', 0))->pluck('merged_into_id')->unique() as $winner) {
                    $this->safely(fn () => $ledger->absorbMerged((string) $winner));
                    $out['merged']++;
                }
                if (! $program->isActive()) {
                    return $out;
                }
                // Cumpleaños: clientes con al menos un pedido o ya en el programa.
                if ($program->rules()->fixedPoints('birthday') > 0) {
                    $md = $local->format('m-d');
                    $birthdays = $base()->whereNull('merged_into_id')->whereNull('deleted_at')->whereNotNull('birthday')
                        ->where(fn ($q) => $q->where('orders_count', '>', 0)->orWhere('points_balance', '!=', 0))
                        ->where(function ($q) use ($md, $local) {
                            $q->whereRaw("DATE_FORMAT(birthday, '%m-%d') = ?", [$md]);
                            if ($md === '02-28' && ! $local->isLeapYear()) {
                                $q->orWhereRaw("DATE_FORMAT(birthday, '%m-%d') = '02-29'");
                            }
                        })->pluck('id');
                    foreach ($birthdays as $id) {
                        if ($this->safely(fn () => $ledger->earnPoints((string) $id, 0, 'birthday', 'birthday:'.$id.':'.$local->year, 'contact', (string) $id)) !== null) {
                            $out['birthdays']++;
                        }
                    }
                }
                // Niveles: la ventana de 12 meses avanza (también pueden bajar).
                $out['tiers'] = $this->safely(fn () => $recalculate->handle($program, $now)) ?? 0;

                return $out;
            });
            $this->line(sprintf('%s: %d puntos vencidos · %d cumpleaños · %d fusiones · %d cambios de nivel', $workspace->name, $summary['expired'], $summary['birthdays'], $summary['merged'], $summary['tiers']));
        }

        return self::SUCCESS;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $fn
     * @return T|null
     */
    private function safely(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }
}
