<?php

declare(strict_types=1);

namespace App\Domain\Webhooks\Http;

use App\Domain\Webhooks\Models\WebhookDelivery;
use App\Domain\Webhooks\Models\WebhookSubscription;

final class WebhookPresenter
{
    /** @return array<string, mixed> */
    public static function subscription(WebhookSubscription $s): array
    {
        return [
            'id' => $s->publicId(),
            'name' => $s->name,
            'url' => $s->url,
            'events' => array_values((array) $s->events),
            'include_pii' => (bool) $s->include_pii,
            'preset' => $s->preset,
            'status' => $s->status,
            'consecutive_failures' => (int) $s->consecutive_failures,
            'last_success_at' => $s->last_success_at?->toIso8601String(),
            'created_at' => $s->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public static function delivery(WebhookDelivery $d): array
    {
        return [
            'id' => 'whd_'.$d->id,
            'event' => $d->event,
            'event_id' => $d->event_id,
            'attempt' => $d->attempt,
            'kind' => $d->kind,
            'state' => $d->state(),
            'status_code' => $d->status_code,
            'duration_ms' => $d->duration_ms,
            'error' => $d->error,
            'next_attempt_at' => $d->next_attempt_at?->toIso8601String(),
            'created_at' => $d->created_at->toIso8601String(),
        ];
    }
}
