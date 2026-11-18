<?php

declare(strict_types=1);

namespace App\Domain\Flows\Engine\Handlers;

use App\Domain\Flows\Engine\NodeHandler;
use App\Domain\Flows\Engine\Outcome;
use App\Domain\Flows\Engine\RunState;
use App\Domain\Loyalty\Contracts\LoyaltyLedger;
use App\Support\Money\Decimal;

/**
 * «Dar saldo» (M13): acredita saldo a favor en la moneda del espacio de
 * trabajo, una sola vez por visita del nodo. El grupo de control y las pruebas
 * no reciben nada. El saldo funciona aunque el programa de puntos esté apagado.
 */
final class GiveWalletHandler implements NodeHandler
{
    public function handle(RunState $state, array $node): Outcome
    {
        if ($state->isHoldout()) {
            return Outcome::next(record: 'skipped_holdout');
        }
        $amount = Decimal::of(is_numeric($node['config']['amount'] ?? null) ? (string) $node['config']['amount'] : '0');
        if ($state->isTest() || $state->run->contact_id === null) {
            return Outcome::next(details: ['test' => $state->isTest(), 'amount' => $amount]);
        }
        if ((float) $amount <= 0) {
            return Outcome::next(details: ['skipped' => 'no_amount']);
        }
        $ledger = app(LoyaltyLedger::class);
        $id = $ledger->creditWallet($state->run->contact_id, $amount, 'flow', $state->idempotencyKey($node['id']), 'flow', $state->flow->id);
        $state->remember('recompensa', ['saldo' => $ledger->walletBalance($state->run->contact_id), 'monto' => $amount]);

        return Outcome::next(details: ['amount' => $amount, 'ledger_id' => 'lg_'.$id]);
    }
}
