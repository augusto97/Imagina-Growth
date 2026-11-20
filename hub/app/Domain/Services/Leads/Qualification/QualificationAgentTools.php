<?php

declare(strict_types=1);

namespace App\Domain\Services\Leads\Qualification;

use App\Domain\Billing\Contracts\Entitlements;
use App\Domain\Intelligence\Ai\Agent\Support\AgentRun;
use App\Domain\Intelligence\Ai\Data\AiTool;
use App\Domain\Messaging\Enums\Channel;
use App\Domain\Messaging\Models\ChannelAccount;
use App\Domain\Services\Contracts\LeadIntake;
use App\Domain\Services\Data\LeadIntakeInput;
use App\Domain\Services\Leads\Brief\UpdateLeadBrief;
use App\Domain\Services\Leads\Models\Lead;
use App\Domain\Services\Leads\Models\LeadQualificationSet;
use App\Domain\Services\Leads\Models\LeadSettings;

/**
 * Las herramientas del agente de ventas para calificar en el chat (M18, auditoría §5.1
 * función 3): `get_qualification` (qué preguntar y qué falta) y `save_qualification_answer`
 * (guarda la respuesta con la frase del cliente y, al completar o descalificar, deriva).
 *
 * Solo se ofrecen si el plan incluye servicios y hay un juego de preguntas activo. Sin lead
 * abierto, la primera respuesta lo crea por WhatsApp con la regla de los leads de WhatsApp
 * (origen `whatsapp`, referencia = la conversación). En «Probar el agente» revisa la
 * respuesta sin guardar nada.
 */
final class QualificationAgentTools
{
    public function __construct(
        private readonly Entitlements $entitlements,
        private readonly LeadIntake $intake,
        private readonly RecordQualificationAnswer $record,
    ) {}

    /** @return list<AiTool> */
    public function for(AgentRun $run): array
    {
        if (! $this->entitlements->allows($run->workspace, 'services') || ! LeadQualificationSet::query()->where('active', true)->exists()) {
            return [];
        }

        return [$this->get($run), $this->save($run)];
    }

    private function get(AgentRun $run): AiTool
    {
        return new AiTool('get_qualification',
            'Preguntas de calificación que este negocio quiere que hagas al cliente interesado en un servicio, con las que ya respondió. Hazlas de forma natural, una o dos por mensaje.',
            self::schema(['service' => ['type' => ['string', 'null'], 'description' => 'Lo que pide el cliente, si todavía no hay un lead (p. ej. «asesoría laboral»)']]),
            function (array $in) use ($run): array {
                $lead = $this->lead($run);
                $run->qualificationService = is_string($in['service'] ?? null) && trim($in['service']) !== '' ? trim($in['service']) : $run->qualificationService;
                $set = Qualification::setFor($lead, $run->qualificationService);
                if ($set === null) {
                    return ['available' => false, 'message' => 'No hay preguntas de calificación para esto: sigue con la conversación normal.'];
                }
                $state = $lead?->qualification;
                $tally = Qualification::tally($set, Qualification::answersOf($set, $state));
                $routed = is_array($state['route'] ?? null) && ($state['set_id'] ?? null) === $set->id;

                return [
                    'available' => true,
                    'questions' => Qualification::forAgent($set, $state),
                    'pending' => $tally['pending'],
                    'done' => $routed,
                    'instructions' => $routed
                        ? 'Ya está calificado: no repitas las preguntas.'
                        : 'Pregunta lo pendiente sin sonar a formulario. Cuando responda, guarda cada respuesta con save_qualification_answer copiando sus palabras exactas en customer_quote. No le digas puntajes ni que lo estás calificando.',
                ];
            });
    }

    private function save(AgentRun $run): AiTool
    {
        return new AiTool('save_qualification_answer',
            'Guarda la respuesta del cliente a una pregunta de calificación. customer_quote son sus palabras exactas; answer es la clave o el texto de la opción, el número o el texto que respondió.',
            self::schema([
                'question_key' => ['type' => 'string', 'description' => 'Clave de la pregunta (de get_qualification)'],
                'answer' => ['type' => 'string', 'description' => 'Respuesta: clave de la opción (yes/no en las de sí o no), el número o el texto'],
                'customer_quote' => ['type' => 'string', 'description' => 'La frase exacta del cliente de la que sale la respuesta'],
            ]),
            function (array $in) use ($run): array {
                $key = (string) ($in['question_key'] ?? '');
                $answer = (string) ($in['answer'] ?? '');
                $quote = (string) ($in['customer_quote'] ?? '');
                if ($run->isTest || $run->contact === null) {
                    $set = Qualification::setFor(null);
                    $question = $set !== null ? collect($set->questions)->firstWhere('key', $key) : null;
                    $check = is_array($question) ? Qualification::check($question, $answer, $quote, $run->customerTexts) : ['ok' => false, 'error' => 'Esa pregunta no existe.'];

                    return ['saved' => false, 'test' => true, 'valid' => $check['ok'], 'error' => $check['ok'] ? null : $check['error']];
                }
                $lead = $this->lead($run) ?? $this->createLead($run);
                if ($lead === null) {
                    return ['saved' => false, 'error' => 'No se puede guardar la ficha de este chat; sigue la conversación sin calificar.'];
                }
                $set = $this->setForAnswer($run, $lead, $key);
                if ($set === null) {
                    return ['saved' => false, 'error' => 'No hay preguntas de calificación para este cliente.'];
                }
                $result = $this->record->handle($run->workspace, $lead, $set, $key, $answer, $quote, $run->customerTexts, $run->conversation);
                $route = $result['route'] ?? null;
                $out = ['saved' => $result['saved'], 'error' => $result['error'] ?? null, 'pending' => $result['pending'] ?? null];
                if (is_array($route)) {
                    $out['next'] = match ($route['action']) {
                        'owner', 'seller' => 'Ya tenemos lo necesario: avisa con amabilidad que una persona del equipo lo atiende en breve.',
                        'web' => 'Comparte este enlace para que siga por su cuenta, con una frase amable: '.$route['url'],
                        default => 'Sigue atendiéndolo tú.',
                    };
                    if (in_array($route['action'], ['owner', 'seller'], true)) {
                        $run->handoffReason = 'qualified_'.$route['action'];
                    } elseif ($route['action'] === 'web' && is_string($route['url'] ?? null)) {
                        $run->requiredLink = $route['url'];
                        $out['url'] = $route['url'];
                    }
                }

                return array_filter($out, fn ($v) => $v !== null);
            });
    }

    /**
     * El juego de la respuesta: el que ya tiene el lead; si no, el que aplica por pipeline y
     * servicio (o lo que pidió el cliente) si trae esa pregunta, o el único activo que la trae.
     */
    private function setForAnswer(AgentRun $run, Lead $lead, string $key): ?LeadQualificationSet
    {
        $hasKey = fn (LeadQualificationSet $s): bool => in_array($key, array_column($s->questions, 'key'), true);
        $set = Qualification::setFor($lead, $run->qualificationService);
        if ($set !== null && (is_string($lead->qualification['set_id'] ?? null) || $hasKey($set))) {
            return $set;
        }
        $withKey = LeadQualificationSet::query()->where('active', true)->orderBy('position')->get()->filter($hasKey)->values();

        return $withKey->count() === 1 ? $withKey->first() : ($set ?? $withKey->first());
    }

    private function lead(AgentRun $run): ?Lead
    {
        return $run->contact !== null ? UpdateLeadBrief::openLead($run->contact->id) : null;
    }

    /** Sin lead abierto: lo crea por WhatsApp con la regla de los leads de WhatsApp (si no se apagó). */
    private function createLead(AgentRun $run): ?Lead
    {
        if ($run->conversation === null || $run->contact === null || ! LeadSettings::for($run->workspace->id)->whatsapp_leads) {
            return null;
        }
        $channel = ChannelAccount::query()->find($run->conversation->channel_account_id)?->channel;
        if ($channel !== Channel::WhatsApp) {
            return null;
        }
        $id = $this->intake->capture(new LeadIntakeInput(
            workspaceId: $run->workspace->id, contactId: $run->contact->id, origin: 'whatsapp', originRef: $run->conversation->id, source: ['channel' => 'whatsapp'],
        ));
        $lead = $id !== null ? Lead::query()->find(preg_replace('/^[a-z]+_/', '', $id)) : null;

        return $lead !== null && $lead->isOpen() ? $lead : null;
    }

    /**
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>
     */
    private static function schema(array $properties): array
    {
        return ['type' => 'object', 'properties' => (object) $properties, 'required' => array_keys($properties), 'additionalProperties' => false];
    }
}
