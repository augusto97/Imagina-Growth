<?php

declare(strict_types=1);

namespace App\Domain\Referrals\Http;

use App\Domain\Contacts\Models\Contact;
use App\Support\Pii\Pii;
use App\Support\Tenancy\CurrentWorkspace;

/**
 * Nombres de los contactos para las listas de M14. Quien puede ver todos los
 * contactos ve el nombre (o el correo enmascarado); el resto, nada.
 */
final class ContactNames
{
    /**
     * @param  list<string>  $ids
     * @return array<string, array{id: string, name: string}>
     */
    public static function for(array $ids): array
    {
        $role = app(CurrentWorkspace::class)->role();
        if ($ids === [] || $role === null || ! $role->can('viewAllContacts')) {
            return [];
        }
        $out = [];
        foreach (Contact::withTrashed()->whereIn('id', array_values(array_unique($ids)))->get() as $contact) {
            $name = $contact->displayName();
            if ($name === '') {
                $name = $contact->email !== null ? Pii::maskString($contact->email) : ($contact->phone !== null ? Pii::maskString($contact->phone) : $contact->publicId());
            }
            $out[$contact->id] = ['id' => $contact->publicId(), 'name' => $name];
        }

        return $out;
    }
}
