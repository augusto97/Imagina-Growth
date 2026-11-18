<?php

declare(strict_types=1);

namespace App\Domain\Onsite\Actions;

use App\Domain\Consent\Actions\RecordConsent;
use App\Domain\Consent\Models\ContactConsent;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Workspaces\Models\Workspace;
use Carbon\CarbonImmutable;

/**
 * Doble opt-in (M12): el clic en el enlace del correo convierte el consentimiento
 * `pending_double_optin` en `granted` con RecordConsent (queda la prueba, con el
 * origen y la versión del texto que la persona aceptó en el widget, y sale
 * consent.granted, que arranca la bienvenida). Debe correr dentro del contexto del
 * espacio de trabajo.
 */
final class ConfirmDoubleOptIn
{
    public const CONFIRMED = 'confirmed';

    public const ALREADY = 'already';

    public const INVALID = 'invalid';

    public function __construct(private readonly RecordConsent $consents) {}

    /** @param  array<string, mixed>  $evidence */
    public function handle(Workspace $workspace, string $contactId, array $evidence = []): string
    {
        // Si el perfil se fusionó después de la captura, el consentimiento vive en el perfil final.
        $contact = self::canonical($contactId);
        if ($contact === null) {
            return self::INVALID;
        }
        $consent = ContactConsent::query()->whereIn('contact_id', [$contactId, $contact->id])
            ->where('channel', 'email')->where('purpose', 'marketing')->orderByRaw('contact_id = ? DESC', [$contact->id])->first();
        if ($consent === null) {
            return self::INVALID;
        }
        if ($consent->getAttribute('status') === 'granted') {
            return self::ALREADY;
        }
        if ($consent->getAttribute('status') !== 'pending_double_optin') {
            return self::INVALID;
        }
        $this->consents->handle(
            $workspace, (string) $consent->getAttribute('contact_id'), 'email', 'marketing', 'granted',
            (string) ($consent->getAttribute('source') ?: 'widget'), $consent->getAttribute('legal_text_version'),
            CarbonImmutable::now(), $evidence + ['double_opt_in' => true], authoritative: true,
        );

        return self::CONFIRMED;
    }

    /** El contacto, o el perfil en el que se fusionó. */
    public static function canonical(?string $id): ?Contact
    {
        $contact = $id !== null ? Contact::query()->whereKey($id)->first() : null;
        for ($i = 0; $contact !== null && is_string($contact->getAttribute('merged_into_id')) && $i < 10; $i++) {
            $contact = Contact::query()->whereKey((string) $contact->getAttribute('merged_into_id'))->first();
        }

        return $contact;
    }
}
