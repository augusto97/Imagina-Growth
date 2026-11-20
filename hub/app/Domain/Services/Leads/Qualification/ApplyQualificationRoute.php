<?php

declare(strict_types=1);

namespace App\Domain\Services\Leads\Qualification;

use App\Domain\Bookings\Support\BookingSettings;
use App\Domain\Inbox\Actions\AssignConversation;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Services\Leads\Actions\AlertSeller;
use App\Domain\Services\Leads\Actions\AssignLead;
use App\Domain\Services\Leads\Models\Lead;
use App\Domain\Services\Leads\Models\LeadActivity;
use App\Domain\Services\Leads\Models\LeadSettings;
use App\Domain\Workspaces\Enums\WorkspaceRole;
use App\Domain\Workspaces\Models\User;
use App\Domain\Workspaces\Models\Workspace;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Aplica la derivación de la calificación (M18, auditoría §5.1 función 3):
 *
 * - `owner`: el lead y la conversación quedan con el dueño del negocio, con aviso.
 * - `seller`: con el vendedor del lead o, si no tiene, el que dan las reglas de asignación
 *   de leads (AssignLead: por turnos o por servicio); sin vendedores, con el dueño. Con aviso.
 * - `agent`: sigue atendiendo la IA.
 * - `web`: un enlace para el cliente (catálogo, cotizador, reservas o el que puso el dueño).
 *
 * El traspaso de la conversación (la IA se apaga y avisa al cliente) lo hace el agente
 * con el resultado. Corre con el espacio de trabajo activo.
 */
final class ApplyQualificationRoute
{
    public function __construct(
        private readonly AssignLead $assignLead,
        private readonly AssignConversation $assignConversation,
        private readonly AlertSeller $alerts,
    ) {}

    /**
     * @param  array<string, mixed>  $decision  de Qualification::route
     * @return array<string, mixed> {action, link?, url?, rule, assignee?}
     */
    public function handle(Workspace $workspace, Lead $lead, array $decision, ?Conversation $conversation): array
    {
        $action = (string) ($decision['action'] ?? 'agent');
        $out = ['action' => $action, 'rule' => $decision['rule'] ?? 'default'];
        if ($action === 'web') {
            $url = self::url($workspace, (string) ($decision['link'] ?? 'catalog'), is_string($decision['url'] ?? null) ? $decision['url'] : null);
            $out += ['link' => (string) ($decision['link'] ?? 'catalog'), 'url' => $url];
            if ($url === null) {
                $out['action'] = 'agent'; // sin dirección no hay a dónde mandarlo: sigue la IA
            }
        } elseif ($action === 'owner' || $action === 'seller') {
            $settings = LeadSettings::for($workspace->id);
            $owner = $action === 'seller' ? ($lead->owner_id ?? $this->assignLead->handle($lead, $settings)) : null;
            $owner ??= self::businessOwner($workspace);
            if ($owner !== null && $lead->owner_id !== $owner) {
                $from = $lead->owner_id;
                $lead->forceFill(['owner_id' => $owner])->save();
                LeadActivity::log($lead, 'owner', 'ai', ['from' => $from !== null ? 'usr_'.$from : null, 'to' => 'usr_'.$owner, 'to_name' => User::query()->whereKey($owner)->value('name')]);
            }
            if ($conversation !== null && $owner !== null) {
                try {
                    $this->assignConversation->assign($conversation, $owner, 'ai');
                } catch (InvalidArgumentException) {
                    // Esa persona no atiende la bandeja: el lead queda con ella y la conversación, sin asignar.
                }
            }
            $this->alerts->handle($workspace, $lead, $settings, 'qualified');
            $out['assignee'] = $owner;
        }
        LeadActivity::log($lead, 'routed', 'ai', array_filter(['action' => $out['action'], 'link' => $out['link'] ?? null], fn ($v) => $v !== null));

        return $out;
    }

    public static function url(Workspace $workspace, string $link, ?string $url): ?string
    {
        if ($url !== null) {
            return $url;
        }

        return match ($link) {
            'booking' => BookingSettings::pageUrl($workspace),
            'catalog' => self::siteUrl($workspace),
            default => null,
        };
    }

    private static function siteUrl(Workspace $workspace): ?string
    {
        $url = BookingSettings::site($workspace)?->getAttribute('url');

        return is_string($url) && $url !== '' ? $url : null;
    }

    /** El primer dueño del espacio de trabajo. */
    private static function businessOwner(Workspace $workspace): ?string
    {
        $id = DB::table('workspace_user')->where('workspace_id', $workspace->id)->where('role', WorkspaceRole::Owner->value)->orderBy('created_at')->value('user_id');

        return is_string($id) ? $id : null;
    }
}
