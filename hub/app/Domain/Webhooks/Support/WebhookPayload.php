<?php

declare(strict_types=1);

namespace App\Domain\Webhooks\Support;

use App\Domain\Contacts\Models\Contact;
use App\Domain\Flows\Support\EventProps;
use App\Domain\Webhooks\Models\WebhookSubscription;
use App\Support\Ids\PrefixedId;

/**
 * El cuerpo JSON de un webhook (docs/api-publica.md §9.2):
 * `{id, event, occurred_at, workspace_id, site_id, contact_id, data, contact?}`.
 * Sin `include_pii`, `data` pasa por EventProps::sanitize (sin correos, teléfonos, nombres
 * ni direcciones) y no hay bloque `contact`.
 */
final class WebhookPayload
{
    /** @param array<string, mixed> $properties */
    public static function build(WebhookSubscription $sub, string $event, string $eventId, string $occurredAt, ?string $siteId, ?string $contactId, array $properties): string
    {
        $body = [
            'id' => $eventId,
            'event' => $event,
            'occurred_at' => $occurredAt,
            'workspace_id' => PrefixedId::encode('ws', $sub->workspace_id),
            'site_id' => $siteId !== null && $siteId !== '' ? PrefixedId::encode('st', $siteId) : null,
            'contact_id' => $contactId !== null ? PrefixedId::encode('ct', $contactId) : null,
            'data' => $sub->include_pii ? $properties : EventProps::sanitize($properties),
        ];
        if ($sub->include_pii && $contactId !== null) {
            $contact = Contact::withoutWorkspaceScope()->where('workspace_id', $sub->workspace_id)->find($contactId);
            if ($contact instanceof Contact) {
                $body['contact'] = [
                    'email' => $contact->email, 'phone' => $contact->phone,
                    'first_name' => $contact->first_name, 'last_name' => $contact->last_name,
                ];
            }
        }

        return (string) json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
