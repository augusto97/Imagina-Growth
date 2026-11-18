<?php

declare(strict_types=1);

namespace App\Domain\Loyalty\Actions;

use App\Domain\Contacts\Models\Contact;
use App\Domain\Loyalty\Contracts\LoyaltyLedger;

/**
 * Puntos por registrarse (`customer.registered`) y por completar el perfil
 * (nombre, correo, WhatsApp, ciudad y cumpleaños: datos que la persona da, sin
 * pedir documentos). Una sola vez por contacto.
 */
final class ProfileRewards
{
    public function __construct(private readonly LoyaltyLedger $ledger) {}

    public function handle(string $contactId, string $event): void
    {
        if (! $this->ledger->isActive()) {
            return;
        }
        if ($event === 'customer.registered') {
            $this->ledger->earnPoints($contactId, 0, 'signup', 'signup:'.$contactId, 'contact', $contactId);
        }
        $contact = Contact::query()->whereKey($contactId)->first();
        if ($contact !== null && self::isComplete($contact)) {
            $this->ledger->earnPoints($contactId, 0, 'profile', 'profile:'.$contactId, 'contact', $contactId);
        }
    }

    public static function isComplete(Contact $contact): bool
    {
        foreach (['first_name', 'email', 'phone', 'city', 'birthday'] as $field) {
            $value = $contact->getAttribute($field);
            if ($value === null || $value === '') {
                return false;
            }
        }

        return true;
    }
}
