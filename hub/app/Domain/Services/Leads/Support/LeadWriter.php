<?php

declare(strict_types=1);

namespace App\Domain\Services\Leads\Support;

use App\Domain\Services\Leads\Actions\AlertSeller;
use App\Domain\Services\Leads\Models\Lead;
use App\Domain\Services\Leads\Models\LeadActivity;
use App\Domain\Services\Leads\Models\LeadSettings;
use App\Domain\Workspaces\Models\Workspace;

/**
 * Guarda un lead después de cambiar sus señales: recalcula puntaje y temperatura,
 * deja la historia del cambio, copia el puntaje al perfil y avisa al vendedor si
 * acaba de pasar a «caliente». Corre con el espacio de trabajo activo.
 */
final class LeadWriter
{
    public function __construct(private readonly LeadScorer $scorer, private readonly AlertSeller $alerts) {}

    public function save(Workspace $workspace, Lead $lead, LeadSettings $settings, bool $alert = true, string $actor = 'system'): void
    {
        $before = $lead->getOriginal('score');
        $result = $this->scorer->recompute($lead, $settings);
        $lead->save();
        if ($lead->isOpen()) {
            LeadScorer::mirror($lead);
        }
        if ($before !== null && (int) $before !== $result['score']) {
            LeadActivity::log($lead, 'score', $actor, ['from' => (int) $before, 'to' => $result['score'], 'temperature' => $result['temperature']]);
        }
        if ($alert && $result['became_hot'] && $lead->isOpen()) {
            $this->alerts->handle($workspace, $lead, $settings, 'hot');
        }
    }
}
