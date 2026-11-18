<?php

declare(strict_types=1);

namespace App\Domain\Referrals\Support;

use App\Domain\Contacts\Models\Contact;
use App\Domain\Contacts\Models\ContactIdentity;
use App\Domain\Identity\SiteToken;
use App\Domain\Sites\Models\Site;

/**
 * El cliente de un `igrUser.token` (api-connector.md §7.1): el sitio firma su
 * `customer_ref` y el hub lo busca en las identidades del contacto. Debe correr
 * dentro del contexto del espacio de trabajo.
 */
final class SiteCustomer
{
    public static function fromToken(Site $site, ?string $token): ?Contact
    {
        $payload = SiteToken::verify($site, $token);
        $ref = $payload['ref'] ?? null;
        if (! is_string($ref) || preg_match('/^[a-z]+:[A-Za-z0-9_-]{1,64}$/', $ref) !== 1) {
            return null;
        }
        $contactId = ContactIdentity::query()->where('type', 'customer_ref')->where('value', $site->id.':'.$ref)->value('contact_id');
        $contact = is_string($contactId) ? Contact::query()->find($contactId) : null;
        for ($i = 0; $contact !== null && $contact->getAttribute('merged_into_id') !== null && $i < 5; $i++) {
            $contact = Contact::query()->find((string) $contact->getAttribute('merged_into_id'));
        }

        return $contact;
    }
}
