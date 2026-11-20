<?php

declare(strict_types=1);

namespace App\Domain\Services\Leads\Qualification;

use App\Domain\Messaging\Models\Conversation;
use App\Domain\Services\Leads\Models\Lead;
use App\Domain\Services\Leads\Models\LeadActivity;
use App\Domain\Services\Leads\Models\LeadQualificationSet;
use App\Domain\Services\Leads\Models\LeadSettings;
use App\Domain\Services\Leads\Support\LeadWriter;
use App\Domain\Workspaces\Models\Workspace;

/**
 * Guarda la respuesta del cliente a una pregunta de calificación (M18, auditoría §5.1
 * función 3) en la ficha del lead, con su frase de origen; suma el puntaje del lead
 * (razón `qualification`, hasta ±50, con el aviso al vendedor si pasa a caliente) y, si
 * ya respondió las obligatorias o quedó descalificado, aplica la derivación una sola vez.
 * La historia guarda qué pregunta se respondió, nunca la respuesta. Corre con el espacio
 * de trabajo activo.
 */
final class RecordQualificationAnswer
{
    public function __construct(private readonly LeadWriter $writer, private readonly ApplyQualificationRoute $router) {}

    /**
     * @param  list<string>  $customerTexts
     * @return array{saved: bool, error?: string, pending?: list<string>, complete?: bool, disqualified?: bool, route?: array<string, mixed>|null}
     */
    public function handle(Workspace $workspace, Lead $lead, LeadQualificationSet $set, string $questionKey, string $answer, ?string $quote,
        array $customerTexts, ?Conversation $conversation = null, ?string $messageId = null): array
    {
        $question = null;
        foreach ($set->questions as $q) {
            if ($q['key'] === $questionKey) {
                $question = $q;
            }
        }
        if ($question === null) {
            return ['saved' => false, 'error' => 'Esa pregunta no existe: usa la clave que da get_qualification.'];
        }
        $check = Qualification::check($question, $answer, $quote, $customerTexts);
        if (! $check['ok']) {
            return ['saved' => false, 'error' => $check['error']];
        }
        $state = (array) ($lead->qualification ?? []);
        $answers = Qualification::answersOf($set, $state === [] ? null : $state);
        $answers[$questionKey] = $check['entry'] + array_filter(['by' => 'ai', 'at' => now()->toIso8601String(), 'message_id' => $messageId], fn ($v) => $v !== null);
        $tally = Qualification::tally($set, $answers);
        $sameSet = ($state['set_id'] ?? null) === $set->id;
        $state = [
            'set_id' => $set->id,
            'set_name' => $set->name,
            'answers' => $answers,
            'score' => $tally['score'],
            'disqualified' => $tally['disqualified'],
            'complete' => $tally['complete'],
            'route' => $sameSet && is_array($state['route'] ?? null) ? $state['route'] : null,
        ];
        $lead->qualification = $state;
        $this->scoreReason($lead, $tally['score'], count($answers));
        $lead->last_activity_at = now();
        $this->writer->save($workspace, $lead, LeadSettings::for($workspace->id), actor: 'ai');
        LeadActivity::log($lead, 'qualification', 'ai', ['question' => $questionKey, 'points' => (int) $check['entry']['points'],
            'disqualified' => (bool) $check['entry']['disqualifies']]);

        $route = null;
        if ($state['route'] === null) {
            $decision = Qualification::route($set, $state);
            if ($decision !== null) {
                $route = $this->router->handle($workspace, $lead, $decision, $conversation);
                $state['route'] = $route + ['at' => now()->toIso8601String()];
                $lead->forceFill(['qualification' => $state, 'qualified_at' => now()])->save();
            }
        }

        return ['saved' => true, 'pending' => $tally['pending'], 'complete' => $tally['complete'], 'disqualified' => $tally['disqualified'], 'route' => $route];
    }

    /** La razón `qualification` del puntaje (reemplaza la anterior). */
    private function scoreReason(Lead $lead, int $score, int $answered): void
    {
        $points = max(-Qualification::SCORE_CAP, min(Qualification::SCORE_CAP, $score));
        $reasons = array_values(array_filter($lead->score_reasons ?? [], fn (array $r) => ($r['signal'] ?? null) !== 'qualification'));
        if ($points !== 0) {
            $reasons[] = ['signal' => 'qualification', 'points' => $points, 'at' => now()->toIso8601String(), 'detail' => ['answered' => $answered]];
        }
        $lead->score_reasons = $reasons;
    }
}
