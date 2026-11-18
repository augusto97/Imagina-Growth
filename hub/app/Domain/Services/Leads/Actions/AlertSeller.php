<?php

declare(strict_types=1);

namespace App\Domain\Services\Leads\Actions;

use App\Domain\Contacts\Models\Contact;
use App\Domain\Flows\Support\TemplateRef;
use App\Domain\Messaging\Contracts\MessageDispatcher;
use App\Domain\Messaging\Data\OutboundMessage;
use App\Domain\Messaging\Enums\Channel;
use App\Domain\Messaging\Enums\MessageKind;
use App\Domain\Services\Leads\Models\Lead;
use App\Domain\Services\Leads\Models\LeadActivity;
use App\Domain\Services\Leads\Models\LeadSeller;
use App\Domain\Services\Leads\Models\LeadSettings;
use App\Domain\Services\Leads\Support\LeadFacts;
use App\Domain\Templates\Library\TemplateLibrary;
use App\Domain\Workspaces\Enums\WorkspaceRole;
use App\Domain\Workspaces\Models\User;
use App\Domain\Workspaces\Models\Workspace;
use Throwable;

/**
 * Aviso al instante al vendedor (M18) cuando un lead llega a «caliente» (y con
 * cada lead nuevo si está activado): WhatsApp de utilidad al teléfono del
 * vendedor con la plantilla aprobada `team_alert` y el enlace al lead en el
 * panel; si no hay plantilla de WhatsApp, teléfono o el envío no sale, por correo.
 * Sin vendedor asignado va a los dueños por correo. Un aviso por lead y motivo.
 * Corre con el espacio de trabajo activo.
 */
final class AlertSeller
{
    public function __construct(private readonly MessageDispatcher $dispatcher) {}

    /** @param 'hot'|'new' $reason */
    public function handle(Workspace $workspace, Lead $lead, LeadSettings $settings, string $reason): ?string
    {
        $rules = $settings->alertRules();
        if (! (bool) ($reason === 'hot' ? $rules['on_hot'] : $rules['on_new'])) {
            return null;
        }
        if (LeadActivity::query()->where('lead_id', $lead->id)->where('kind', 'alert')->where('data->reason', $reason)->exists()) {
            return null;
        }
        $contact = Contact::query()->find($lead->contact_id);
        $text = LeadFacts::alertText($lead, $contact, $reason);
        $url = rtrim((string) config('app.url'), '/').'/w/'.$workspace->publicId().'/leads?lead='.$lead->publicId();
        $context = ['aviso' => [
            'titulo' => $reason === 'hot' ? __('Lead caliente') : __('Lead nuevo'),
            'resumen' => $text, 'detalle' => $text, 'texto' => $text, 'url' => $url,
        ]];

        $channel = null;
        $seller = $lead->owner_id !== null ? LeadSeller::query()->where('user_id', $lead->owner_id)->where('active', true)->first() : null;
        if (($rules['channel'] ?? 'whatsapp') === 'whatsapp' && $seller !== null && is_string($seller->phone) && $seller->phone !== '') {
            $templateId = TemplateRef::resolve($workspace->id, 'whatsapp', $rules['whatsapp_template_id'] ?? null, TemplateRef::TEAM_ALERT);
            if ($templateId !== null && $this->send($workspace, $lead, Channel::WhatsApp, $templateId, $context, $seller->phone, "lead_alert:{$lead->id}:{$reason}:wa")) {
                $channel = 'whatsapp';
            }
        }
        if ($channel === null) {
            $channel = $this->email($workspace, $lead, $context, $reason) ? 'email' : null;
        }
        LeadActivity::log($lead, 'alert', 'system', ['reason' => $reason, 'channel' => $channel ?? 'none', 'score' => $lead->score]);

        return $channel;
    }

    /** @param array<string, mixed> $context */
    private function email(Workspace $workspace, Lead $lead, array $context, string $reason): bool
    {
        $templateId = TemplateRef::resolve($workspace->id, 'email', null, TemplateRef::TEAM_ALERT);
        if ($templateId === null) {
            // La biblioteca solo instala la de correo si no hay otra `team_alert` (p. ej. la de WhatsApp).
            TemplateLibrary::install($workspace, TemplateRef::TEAM_ALERT, copy: true);
            $templateId = TemplateRef::resolve($workspace->id, 'email', null, TemplateRef::TEAM_ALERT);
        }
        if ($templateId === null) {
            return false;
        }
        $owner = $lead->owner_id !== null ? User::query()->find($lead->owner_id) : null;
        $recipients = $owner !== null && is_string($owner->email)
            ? [$owner->email]
            : $workspace->users()->wherePivot('role', WorkspaceRole::Owner->value)->pluck('email')->map(fn ($e) => (string) $e)->all();
        $sent = false;
        foreach (array_slice(array_values(array_unique($recipients)), 0, 5) as $i => $to) {
            $sent = $this->send($workspace, $lead, Channel::Email, $templateId, $context, $to, "lead_alert:{$lead->id}:{$reason}:mail:{$i}") || $sent;
        }

        return $sent;
    }

    /** @param array<string, mixed> $context */
    private function send(Workspace $workspace, Lead $lead, Channel $channel, string $templateId, array $context, string $to, string $key): bool
    {
        try {
            return $this->dispatcher->dispatch(new OutboundMessage(
                workspaceId: $workspace->id,
                contactId: $lead->contact_id,
                channel: $channel,
                kind: MessageKind::Utility,
                sourceType: 'system',
                sourceId: $lead->id,
                templateId: $templateId,
                context: $context,
                idempotencyKey: $key,
                to: $to,
            ))->sent();
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }
}
