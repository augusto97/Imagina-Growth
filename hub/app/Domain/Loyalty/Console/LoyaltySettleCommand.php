<?php

declare(strict_types=1);

namespace App\Domain\Loyalty\Console;

use App\Domain\Loyalty\Actions\OrderLifecycle;
use App\Domain\Loyalty\Actions\ReleaseWalletHolds;
use App\Domain\Loyalty\Models\LedgerEntry;
use App\Domain\Loyalty\Models\LoyaltySettlement;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * Cada 15 minutos: confirma los puntos de los pedidos que cumplieron su periodo
 * de devolución (y acredita su cashback) y libera el saldo de los cupones de
 * saldo que vencieron sin usarse.
 */
final class LoyaltySettleCommand extends Command
{
    protected $signature = 'igr:loyalty:settle {--now= : Fecha y hora de referencia (pruebas)}';

    protected $description = 'Confirma puntos pendientes vencidos su periodo y libera saldos reservados';

    public function handle(CurrentWorkspace $current, OrderLifecycle $orders, ReleaseWalletHolds $holds): int
    {
        $now = $this->option('now') ? CarbonImmutable::parse((string) $this->option('now')) : CarbonImmutable::now();
        $settled = 0;
        $released = 0;

        $due = LoyaltySettlement::withoutWorkspaceScope()->where('status', 'scheduled')->where('due_at', '<=', $now)->orderBy('due_at')->limit(5000)->get()->groupBy('workspace_id');
        $holdWorkspaces = LedgerEntry::withoutWorkspaceScope()->where('kind', 'wallet')->where('status', 'pending')
            ->where('created_at', '<=', $now->subHours(24))->distinct()->pluck('workspace_id')->all();

        foreach (array_unique([...$due->keys()->all(), ...$holdWorkspaces]) as $workspaceId) {
            $workspace = Workspace::query()->whereKey((string) $workspaceId)->first();
            if ($workspace === null) {
                continue;
            }
            [$s, $r] = $current->run($workspace, function () use ($due, $workspaceId, $orders, $holds, $now): array {
                $count = 0;
                foreach ($due->get($workspaceId, collect()) as $settlement) {
                    try {
                        $orders->settle($settlement);
                        $count++;
                    } catch (Throwable $e) {
                        report($e);
                    }
                }

                return [$count, $holds->handle($now)];
            });
            $settled += $s;
            $released += $r;
        }
        $this->line("Pedidos confirmados: {$settled} · saldos liberados: {$released}");

        return self::SUCCESS;
    }
}
