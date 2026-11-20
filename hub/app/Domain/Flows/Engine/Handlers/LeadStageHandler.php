<?php

declare(strict_types=1);

namespace App\Domain\Flows\Engine\Handlers;

use App\Domain\Flows\Engine\NodeHandler;
use App\Domain\Flows\Engine\Outcome;
use App\Domain\Flows\Engine\RunState;
use App\Domain\Services\Leads\Actions\ChangeLeadStage;
use App\Domain\Services\Leads\Brief\UpdateLeadBrief;
use App\Domain\Services\Leads\Support\Pipelines;
use Illuminate\Support\Facades\DB;

/**
 * «Mover etapa del lead» (M09): pasa el lead abierto del contacto a la etapa del nodo
 * con la misma acción del panel (`ChangeLeadStage`, `by = system`): emite
 * `lead.stage_changed` y, si es ganado, la conversión offline. Si el contacto no tiene
 * lead abierto o su pipeline no tiene esa etapa, sigue por «falló». El grupo de
 * control y las pruebas no mueven nada (sin mensajes, la etapa no se cumplió).
 */
final class LeadStageHandler implements NodeHandler
{
    public function __construct(private readonly ChangeLeadStage $change) {}

    public function handle(RunState $state, array $node): Outcome
    {
        if ($state->isHoldout()) {
            return Outcome::next(record: 'skipped_holdout');
        }
        if ($state->isTest()) {
            return Outcome::next(details: ['test' => true]);
        }
        $c = $node['config'];
        $lead = UpdateLeadBrief::openLead($state->run->contact_id);
        if ($lead === null) {
            return Outcome::next('failed', 'failed', ['reason' => 'no_open_lead'], fallback: true);
        }
        $stage = (string) ($c['stage'] ?? '');
        if (Pipelines::for($lead)->stage($stage) === null) {
            return Outcome::next('failed', 'failed', ['reason' => 'stage_not_in_pipeline', 'lead_id' => $lead->publicId()], fallback: true);
        }
        $from = $lead->stage;
        $reason = is_string($c['lost_reason'] ?? null) ? $c['lost_reason'] : null;
        DB::transaction(fn () => $this->change->handle($state->workspace, $lead, $stage, 'system', null, null, $reason));

        return Outcome::next(details: ['lead_id' => $lead->publicId(), 'from' => $from, 'to' => $stage]);
    }
}
