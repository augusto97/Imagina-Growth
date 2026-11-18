<?php

declare(strict_types=1);

namespace App\Domain\Webhooks\Listeners;

use App\Domain\Contacts\Models\Contact;
use App\Domain\Flows\Support\EventProps;
use App\Domain\Webhooks\Actions\QueueWebhookDelivery;
use App\Domain\Webhooks\Models\WebhookSubscription;
use App\Domain\Webhooks\Support\SubscriptionIndex;
use App\Domain\Webhooks\Support\WebhookPayload;
use App\Ingest\Events\EventIngested;
use App\Support\Ids\PrefixedId;
use Illuminate\Support\Facades\Cache;
use Ramsey\Uuid\Uuid;

/**
 * De la ingesta (eventos del sitio y del hub) a los webhooks suscritos. Consulta un índice en
 * caché: si nadie quiere el evento no toca la base. Además genera `contact.created` cuando el
 * evento trae un contacto creado en los últimos 2 minutos (una sola vez por contacto).
 */
final class FanOutWebhooks
{
    public function __construct(private readonly QueueWebhookDelivery $queue) {}

    public function handle(EventIngested $event): void
    {
        $ids = SubscriptionIndex::matching($event->workspaceId, $event->event);
        $wantsContact = $event->contactId !== null ? SubscriptionIndex::matching($event->workspaceId, 'contact.created') : [];
        if ($ids === [] && $wantsContact === []) {
            return;
        }
        $properties = is_array($event->envelope['properties'] ?? null) ? $event->envelope['properties'] : [];
        $occurredAt = is_string($event->envelope['occurred_at'] ?? null) ? $event->envelope['occurred_at'] : now()->toIso8601String();

        foreach ($this->subscriptions($ids) as $sub) {
            $payload = WebhookPayload::build($sub, $event->event, $event->eventId, $occurredAt, $event->siteId, $event->contactId, $properties);
            $this->queue->handle($sub, $event->event, $event->eventId, $payload);
        }

        if ($wantsContact !== [] && $event->contactId !== null) {
            $this->contactCreated($event, $wantsContact);
        }
    }

    /** @param list<string> $subscriptionIds */
    private function contactCreated(EventIngested $event, array $subscriptionIds): void
    {
        /** @var Contact|null $contact */
        $contact = Contact::withoutWorkspaceScope()->where('workspace_id', $event->workspaceId)->find($event->contactId);
        $createdAt = $contact?->getAttribute('created_at');
        if ($contact === null || $createdAt === null || $createdAt->lt(now()->subMinutes(2))) {
            return;
        }
        if (! Cache::add('webhooks:contact_created:'.$contact->id, 1, 86400)) {
            return;
        }
        $eventId = Uuid::uuid5(Uuid::NAMESPACE_URL, 'igr:contact.created:'.$contact->id)->toString();
        $data = [
            'contact_id' => PrefixedId::encode('ct', $contact->id),
            'first_event' => $event->event,
            'first_source' => EventProps::sanitize((array) ($contact->getAttribute('first_source') ?? [])),
        ];
        foreach ($this->subscriptions($subscriptionIds) as $sub) {
            $payload = WebhookPayload::build($sub, 'contact.created', $eventId, $createdAt->toIso8601String(), $event->siteId, $contact->id, $data);
            $this->queue->handle($sub, 'contact.created', $eventId, $payload);
        }
    }

    /**
     * @param  list<string>  $ids
     * @return iterable<WebhookSubscription>
     */
    private function subscriptions(array $ids): iterable
    {
        if ($ids === []) {
            return [];
        }

        return WebhookSubscription::withoutWorkspaceScope()->whereIn('id', $ids)->where('status', '!=', WebhookSubscription::PAUSED)->get();
    }
}
