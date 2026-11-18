<?php

declare(strict_types=1);

namespace App\Domain\Flows\Engine\Handlers;

use App\Domain\Flows\Engine\NodeHandler;
use App\Domain\Flows\Engine\Outcome;
use App\Domain\Flows\Engine\RunState;
use App\Domain\Loyalty\Contracts\LoyaltyLedger;

/**
 * «Dar puntos» (M13): acredita puntos confirmados al contacto, una sola vez por
 * visita del nodo (clave del paso). El grupo de control y las pruebas no reciben
 * nada; sin programa activo el paso sigue sin dar puntos.
 */
final class GivePointsHandler implements NodeHandler
{
    public function handle(RunState $state, array $node): Outcome
    {
        if ($state->isHoldout()) {
            return Outcome::next(record: 'skipped_holdout');
        }
        $points = max(0, (int) ($node['config']['points'] ?? 0));
        if ($state->isTest() || $state->run->contact_id === null) {
            return Outcome::next(details: ['test' => $state->isTest(), 'points' => $points]);
        }
        $ledger = app(LoyaltyLedger::class);
        if ($points <= 0 || ! $ledger->isActive()) {
            return Outcome::next(details: ['skipped' => 'no_program']);
        }
        $entry = $ledger->earnPoints($state->run->contact_id, $points, 'flow', $state->idempotencyKey($node['id']), 'flow', $state->flow->id);
        $state->remember('recompensa', ['puntos' => $points, 'saldo_puntos' => $ledger->pointsBalance($state->run->contact_id)]);

        return Outcome::next(details: ['points' => $points, 'ledger_id' => $entry !== null ? 'lg_'.$entry : null]);
    }
}
