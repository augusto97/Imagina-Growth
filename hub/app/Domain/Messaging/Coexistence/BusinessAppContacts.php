<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Coexistence;

use App\Domain\Contacts\Models\Contact;
use App\Domain\Contacts\Models\ContactIdentity;
use App\Domain\Identity\IdentityResolver;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Phone\Phone;
use App\Support\Pii\BlindIndex;
use Illuminate\Support\Str;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberUtil;

/**
 * Contactos que llegan de la app de WhatsApp Business de un número en coexistencia (la libreta, el historial y lo
 * que el negocio escribe desde el celular). Busca por el teléfono (índice ciego) o por el BSUID que Meta da a cada
 * cliente (`wa_bsuid`), y crea el contacto solo si hay teléfono.
 *
 * **Nunca crea consentimiento:** que alguien esté en la libreta del celular o haya escrito antes no autoriza
 * marketing (cumplimiento.md §3 y §5.1). El contacto nuevo queda como `lead` con su origen en `first_source`
 * (`{channel: whatsapp, via: whatsapp_business_app}`), y el nombre solo llena lo que estaba vacío.
 */
final class BusinessAppContacts
{
    public const ORIGIN = 'whatsapp_business_app';

    /** Identidad con el BSUID de WhatsApp (ID del cliente por portafolio de negocio). */
    public const BSUID = 'wa_bsuid';

    /**
     * El contacto del cliente, o null si no hay forma de identificarlo. Corre con el espacio de trabajo activo.
     *
     * @return array{0: Contact, 1: bool}|null el contacto y si se creó
     */
    public function resolve(Workspace $workspace, ?string $digits, ?string $userId, ?string $fullName = null, ?string $firstName = null): ?array
    {
        $e164 = $digits !== null ? Phone::toE164('+'.$digits, strtoupper((string) ($workspace->country ?: 'CO'))) : null;
        $bidx = $e164 !== null ? BlindIndex::phone($workspace->id, $e164) : null;
        $contact = null;
        if ($bidx !== null) {
            $contact = Contact::query()->where('phone_bidx', $bidx)->whereNull('merged_into_id')->first()
                ?? self::follow(ContactIdentity::query()->where('type', 'phone_hash')->where('value', $bidx)->value('contact_id'));
        }
        if ($contact === null && $userId !== null) {
            $contact = self::follow(ContactIdentity::query()->where('type', self::BSUID)->where('value', $userId)->value('contact_id'));
        }
        $created = false;
        if ($contact === null) {
            if ($e164 === null || $bidx === null) {
                return null;
            }
            $contact = Contact::query()->create([
                'workspace_id' => $workspace->id,
                'phone' => $e164,
                'phone_bidx' => $bidx,
                'country' => self::countryOf($e164),
                'lifecycle_stage' => 'lead',
                'first_source' => ['channel' => 'whatsapp', 'via' => self::ORIGIN, 'at' => now()->toIso8601String()],
                'first_seen_at' => now(),
                'last_seen_at' => now(),
            ]);
            $this->identity($workspace, $contact, 'phone_hash', $bidx);
            $created = true;
        }
        if ($userId !== null) {
            $this->identity($workspace, $contact, self::BSUID, $userId);
        }
        $this->fillName($contact, $fullName, $firstName);

        return [$contact, $created];
    }

    /** Llena el nombre solo si estaba vacío: el del pedido o el que escribió una persona gana sobre la libreta. */
    private function fillName(Contact $contact, ?string $fullName, ?string $firstName): void
    {
        if ($contact->first_name !== null && $contact->first_name !== '') {
            return;
        }
        $full = trim((string) ($fullName ?? $firstName));
        if ($full === '') {
            return;
        }
        $first = trim((string) $firstName);
        if ($first === '' || ! str_starts_with(mb_strtolower($full), mb_strtolower($first))) {
            $first = (string) (preg_split('/\s+/u', $full)[0] ?? $full);
        }
        $last = trim(mb_substr($full, mb_strlen($first)));
        $contact->first_name = mb_substr($first, 0, 100);
        $contact->last_name = $last !== '' && ($contact->last_name === null || $contact->last_name === '') ? mb_substr($last, 0, 100) : $contact->last_name;
        $contact->name_prefix_bidx = IdentityResolver::namePrefixes($contact->workspace_id, $contact->first_name.' '.($contact->last_name ?? ''));
        $contact->save();
    }

    private function identity(Workspace $workspace, Contact $contact, string $type, string $value): void
    {
        ContactIdentity::query()->insertOrIgnore([
            'id' => strtoupper((string) Str::ulid()), 'workspace_id' => $workspace->id, 'contact_id' => $contact->id,
            'type' => $type, 'value' => mb_substr($value, 0, 128), 'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);
    }

    /** El contacto vigente (sigue las fusiones). */
    private static function follow(mixed $id): ?Contact
    {
        $contact = is_string($id) ? Contact::query()->find($id) : null;
        for ($hops = 0; $contact !== null && is_string($contact->getAttribute('merged_into_id')) && $hops < 5; $hops++) {
            $contact = Contact::query()->find($contact->getAttribute('merged_into_id'));
        }

        return $contact;
    }

    private static function countryOf(string $e164): ?string
    {
        try {
            $util = PhoneNumberUtil::getInstance();

            return $util->getRegionCodeForNumber($util->parse($e164)) ?: null;
        } catch (NumberParseException) {
            return null;
        }
    }
}
