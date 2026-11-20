<?php

declare(strict_types=1);

namespace App\Domain\Flows\Engine\Handlers;

use App\Domain\Flows\Engine\NodeHandler;
use App\Domain\Flows\Engine\Outcome;
use App\Domain\Flows\Engine\RunState;
use App\Domain\Inbox\Actions\AssignConversation;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Services\Leads\Actions\AssignLead;
use App\Domain\Services\Leads\Brief\UpdateLeadBrief;
use App\Domain\Services\Leads\Models\LeadActivity;
use App\Domain\Services\Leads\Models\LeadSettings;
use App\Domain\Workspaces\Models\User;
use App\Support\Ids\PrefixedId;
use InvalidArgumentException;

/**
 * «Asignar a una persona» (M09): la conversación más reciente del contacto en la
 * bandeja y su lead abierto quedan con la persona elegida o, en modo automático,
 * con las reglas de la bandeja (turnos entre quien está disponible) y las de leads
 * (turnos o por servicio), solo si todavía no tenían a nadie. Sin conversación ni
 * lead sigue por «falló». El grupo de control y las pruebas no cambian nada.
 */
final class AssignAgentHandler implements NodeHandler
{
    public function __construct(private readonly AssignConversation $conversations, private readonly AssignLead $leads) {}

    public function handle(RunState $state, array $node): Outcome
    {
        if ($state->isHoldout()) {
            return Outcome::next(record: 'skipped_holdout');
        }
        if ($state->isTest()) {
            return Outcome::next(details: ['test' => true]);
        }
        $c = $node['config'];
        $userId = ($c['mode'] ?? 'auto') === 'user' ? PrefixedId::decode(User::ID_PREFIX, (string) ($c['user_id'] ?? '')) : null;
        if (($c['mode'] ?? 'auto') === 'user' && ($userId === null || ! $state->workspace->users()->where('users.id', $userId)->exists())) {
            return Outcome::next('failed', 'failed', ['reason' => 'not_a_member'], fallback: true);
        }
        $conversation = Conversation::query()->where('contact_id', $state->run->contact_id)->orderByDesc('last_message_at')->orderByDesc('updated_at')->first();
        $lead = UpdateLeadBrief::openLead($state->run->contact_id);
        if ($conversation === null && $lead === null) {
            return Outcome::next('failed', 'failed', ['reason' => 'nothing_to_assign'], fallback: true);
        }
        $details = [];
        if ($conversation !== null) {
            try {
                $details['conversation'] = $userId !== null ? $this->conversations->assign($conversation, $userId, 'system')->assignee_id : $this->conversations->auto($conversation);
            } catch (InvalidArgumentException) {
                $details['conversation_skipped'] = 'not_inbox_agent';
            }
        }
        if ($lead !== null) {
            $owner = $userId ?? $lead->owner_id ?? $this->leads->handle($lead, LeadSettings::for($state->workspace->id));
            if ($owner !== null && $owner !== $lead->owner_id) {
                $name = User::query()->whereKey($owner)->value('name');
                LeadActivity::log($lead, 'owner', 'system', ['from' => $lead->owner_id !== null ? 'usr_'.$lead->owner_id : null, 'to' => 'usr_'.$owner, 'to_name' => $name, 'flow' => $state->flow->publicId()]);
                $lead->forceFill(['owner_id' => $owner])->save();
            }
            $details['lead'] = $lead->publicId();
            $details['owner'] = $owner !== null ? 'usr_'.$owner : null;
        }

        return Outcome::next(details: $details);
    }
}
