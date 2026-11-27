<?php

declare(strict_types=1);

namespace App\Domain\Notices\Support;

use App\Domain\Contacts\Models\Contact;
use App\Domain\Notices\Data\TeamNotice;
use App\Domain\Notices\Jobs\DeliverTeamNotice;
use App\Domain\Services\Leads\Models\Lead;
use App\Domain\Services\Leads\Support\LeadFacts;
use App\Domain\Workspaces\Models\Workspace;

/**
 * Los leads en el centro de avisos (lote 0.4.0): nuevo, caliente o calificado en el chat, al vendedor asignado y a
 * dueños y administradores con «de todo el negocio». Sale aunque el aviso por WhatsApp o correo de los ajustes de
 * leads esté apagado (`AlertSeller` sigue manejando esos dos); cada persona lo apaga en «Mis avisos».
 */
final class LeadTeamNotices
{
    /** @param 'hot'|'new'|'qualified' $reason */
    public static function queue(Workspace $workspace, Lead $lead, string $reason): void
    {
        $contact = Contact::query()->find($lead->contact_id);
        $name = $contact !== null && $contact->displayName() !== '' ? $contact->displayName() : __('Sin nombre');
        $title = match ($reason) {
            'hot' => __('Lead caliente: :name', ['name' => $name]),
            'qualified' => __('Lead calificado en el chat: :name', ['name' => $name]),
            default => __('Lead nuevo: :name', ['name' => $name]),
        };
        DeliverTeamNotice::for($workspace, new TeamNotice(
            kind: 'lead.'.$reason,
            group: 'leads',
            title: $title,
            body: LeadFacts::alertText($lead, $contact, $reason),
            path: 'leads?lead='.$lead->publicId(),
            dedupe: 'lead:'.$lead->id.':'.$reason,
            subjectType: 'lead',
            subjectId: $lead->id,
            logEvent: 'lead_'.$reason,
            userIds: $lead->owner_id !== null ? [$lead->owner_id] : [],
            contactId: $lead->contact_id,
        ));
    }
}
