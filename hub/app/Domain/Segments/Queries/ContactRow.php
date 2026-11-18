<?php

declare(strict_types=1);

namespace App\Domain\Segments\Queries;

use App\Domain\Contacts\Models\Contact;
use App\Support\Pii\Pii;

/** Fila de contacto para listas y muestras, enmascarada según el rol (igual que Contactos). */
final class ContactRow
{
    /** @return array<string, mixed> */
    public static function make(Contact $c, bool $unmasked): array
    {
        return [
            'id' => $c->publicId(),
            'name' => $c->displayName(),
            'email' => $c->email === null ? null : ($unmasked ? $c->email : Pii::maskEmail($c->email)),
            'phone' => $c->phone === null ? null : ($unmasked ? $c->phone : Pii::maskPhone($c->phone)),
            'city' => $c->getAttribute('city'),
            'lifecycle_stage' => $c->getAttribute('lifecycle_stage'),
            'rfm_segment' => $c->getAttribute('rfm_segment'),
            'orders_count' => (int) $c->getAttribute('orders_count'),
            'revenue_total_ws' => (string) $c->getAttribute('revenue_total_ws'),
            'last_order_at' => $c->getAttribute('last_order_at')?->toIso8601String(),
            'last_seen_at' => $c->last_seen_at?->toIso8601String(),
        ];
    }
}
