<?php

declare(strict_types=1);

namespace App\Domain\Identity;

use App\Domain\Contacts\Actions\MergeContacts;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Contacts\Models\ContactIdentity;
use App\Domain\Sites\Models\Site;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Phone\Phone;
use App\Support\Pii\BlindIndex;
use App\Support\Pii\Pii;
use App\Support\Tenancy\RedisKey;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Resolución de identidad determinista (eventos.md §6.2). Nunca une perfiles
 * por probabilidad. Orden de fuerza: customer_ref > email > teléfono > anonymous_id.
 *
 * Crea o encuentra el contacto y agrega los identificadores que le faltan.
 * Si dos contactos comparten identificadores fuertes, se fusionan en el del
 * identificador más fuerte (auditado y reversible, MergeContacts), salvo que
 * sean dos cuentas distintas del mismo sitio. Un anonymous_id que aparece con
 * un identificador fuerte pasa a ese contacto sin fusionar (dispositivo
 * compartido).
 *
 * Debe ejecutarse con el espacio de trabajo activo (CurrentWorkspace).
 */
final class IdentityResolver
{
    private const RANK = ['customer_ref' => 1, 'email_hash' => 2, 'phone_hash' => 3, 'anonymous_id' => 6];

    public function __construct(private readonly MergeContacts $merge) {}

    /**
     * @return array{contact_id: string|null, created: bool, merged: bool, identity_map: list<string>}
     *                                                                                                 identity_map: anonymous_id que quedaron apuntando a contact_id (para la tabla identity_map de ClickHouse)
     */
    public function resolve(Workspace $workspace, Site $site, IdentityInput $in, CarbonInterface $seenAt): array
    {
        $none = ['contact_id' => null, 'created' => false, 'merged' => false, 'identity_map' => []];
        $identifiers = $this->identifiers($workspace, $site, $in);
        $strong = array_values(array_filter($identifiers, fn ($i) => $i['type'] !== 'anonymous_id'));
        if ($strong === [] && $in->anonymousId === null) {
            return $none;
        }

        // Solo anónimo (lo más común: vistas del tracker): caché Redis del mapa anónimo → contacto.
        if ($strong === []) {
            return ['contact_id' => $this->contactForAnonymous($workspace, (string) $identifiers[0]['value']), 'created' => false, 'merged' => false, 'identity_map' => []];
        }

        $existing = ContactIdentity::query()
            ->where(function ($q) use ($identifiers): void {
                foreach ($identifiers as $i) {
                    $q->orWhere(fn ($w) => $w->where('type', $i['type'])->where('value', $i['value']));
                }
            })
            ->get()
            ->keyBy(fn (ContactIdentity $i) => $i->type.'|'.$i->value);

        // Contactos de los identificadores fuertes, del más fuerte al más débil.
        $strongContacts = [];
        foreach ($strong as $i) {
            $found = $existing->get($i['type'].'|'.$i['value']);
            if ($found !== null && ! in_array($found->contact_id, $strongContacts, true)) {
                $strongContacts[] = $found->contact_id;
            }
        }

        // Una cuenta nueva del sitio no se pega al contacto de otra cuenta del mismo sitio
        // (p. ej. dos cuentas que comparten el teléfono de la casa).
        if ($in->customerRef !== null && ! $existing->has('customer_ref|'.$site->id.':'.$in->customerRef)) {
            $strongContacts = array_values(array_filter($strongContacts, fn (string $id) => ! $this->hasOtherAccount($id, $site, $in->customerRef)));
        }

        $created = false;
        $merged = false;
        $identityMap = [];
        if ($strongContacts === []) {
            // Se guarda una sola vez, con el perfil completo, al final de updateProfile().
            $contact = new Contact([
                'workspace_id' => $workspace->id,
                'lifecycle_stage' => 'lead',
                'first_seen_at' => $seenAt,
                'last_seen_at' => $seenAt,
            ]);
            $contact->setAttribute('id', $contact->newUniqueId());
            $created = true;
        } else {
            $contact = $this->live(Contact::query()->find($strongContacts[0]));
            if ($contact === null) {
                return $none;
            }
            // Dos contactos con un identificador fuerte en común se fusionan (eventos.md §6.2).
            foreach (array_slice($strongContacts, 1) as $otherId) {
                $other = $this->live(Contact::query()->find($otherId));
                if ($other === null || $other->id === $contact->id) {
                    continue;
                }
                if ($this->distinctAccounts($contact, $other)) {
                    Log::info('igr.identity.conflict', ['workspace' => $workspace->id, 'contact' => $contact->id, 'other' => $other->id, 'reason' => 'cuentas distintas del mismo sitio']);

                    continue;
                }
                $result = $this->merge->handle($contact, $other, 'identity:'.implode(',', array_column($strong, 'type')));
                array_push($identityMap, ...$result['anonymous_ids']);
                $merged = true;
            }
            if ($merged) {
                $contact->refresh();
                $existing = ContactIdentity::query()
                    ->whereIn('value', array_column($identifiers, 'value'))
                    ->get()
                    ->keyBy(fn (ContactIdentity $i) => $i->type.'|'.$i->value);
            }
        }

        /** @var array<string, string> $owners tipo|valor => contacto dueño */
        $owners = [];
        foreach ($identifiers as $i) {
            $key = $i['type'].'|'.$i['value'];
            $found = $existing->get($key);
            $owners[$key] = $found !== null ? $found->contact_id : $contact->id;
            if ($found === null) {
                ContactIdentity::query()->create([
                    'workspace_id' => $workspace->id,
                    'contact_id' => $contact->id,
                    'type' => $i['type'],
                    'value' => $i['value'],
                    'site_id' => $site->id,
                    'first_seen_at' => $seenAt,
                    'last_seen_at' => $seenAt,
                ]);
                $owners[$key] = $contact->id;
                if ($i['type'] === 'anonymous_id') {
                    $identityMap[] = $i['value'];
                }
            } elseif ($i['type'] === 'anonymous_id' && $found->contact_id !== $contact->id) {
                // Un dispositivo compartido: el anónimo pasa al contacto del identificador fuerte (último visto gana).
                $found->forceFill(['contact_id' => $contact->id, 'last_seen_at' => $seenAt])->save();
                $owners[$key] = $contact->id;
                $identityMap[] = $i['value'];
            } elseif ($found->contact_id === $contact->id && ($found->last_seen_at === null || $found->last_seen_at->lt($seenAt->subHour()))) {
                // Con resolución de una hora basta; así no se escribe en cada evento.
                $found->forceFill(['last_seen_at' => $seenAt])->save();
            }
        }

        $this->updateProfile($contact, $workspace, $in, $seenAt, $owners);
        foreach (array_unique($identityMap) as $anon) {
            Cache::put(RedisKey::for($workspace->id, 'anon:'.$anon), $contact->id, now()->addDay());
        }

        return ['contact_id' => $contact->id, 'created' => $created, 'merged' => $merged, 'identity_map' => array_values(array_unique($identityMap))];
    }

    /** Contacto de un anónimo, con caché de un día (5 minutos si no se conoce). */
    private function contactForAnonymous(Workspace $workspace, string $anonymousId): ?string
    {
        $key = RedisKey::for($workspace->id, 'anon:'.$anonymousId);
        $cached = Cache::get($key);
        if (is_string($cached)) {
            return $cached === '-' ? null : $cached;
        }
        $contactId = ContactIdentity::query()->where('type', 'anonymous_id')->where('value', $anonymousId)->value('contact_id');
        $contactId = is_string($contactId) ? $this->followMerges($contactId) : null;
        Cache::put($key, $contactId ?? '-', $contactId === null ? now()->addMinutes(5) : now()->addDay());

        return $contactId;
    }

    /** Si el contacto fue fusionado, devuelve el ganador vigente. */
    private function live(?Contact $contact): ?Contact
    {
        for ($i = 0; $contact !== null && $contact->getAttribute('merged_into_id') !== null && $i < 5; $i++) {
            $contact = Contact::query()->whereKey((string) $contact->getAttribute('merged_into_id'))->first();
        }

        return $contact;
    }

    private function followMerges(string $contactId): ?string
    {
        return $this->live(Contact::query()->find($contactId))?->id;
    }

    private function hasOtherAccount(string $contactId, Site $site, string $customerRef): bool
    {
        return ContactIdentity::query()
            ->where('contact_id', $contactId)
            ->where('type', 'customer_ref')
            ->where('value', 'like', $site->id.':%')
            ->where('value', '!=', $site->id.':'.$customerRef)
            ->exists();
    }

    /** Dos cuentas distintas del mismo sitio (dos customer_ref) nunca se fusionan solas. */
    private function distinctAccounts(Contact $a, Contact $b): bool
    {
        $refs = ContactIdentity::query()->where('type', 'customer_ref')->whereIn('contact_id', [$a->id, $b->id])->get(['contact_id', 'value']);
        $bySite = [];
        foreach ($refs as $ref) {
            $site = explode(':', $ref->value, 2)[0];
            $bySite[$site][$ref->contact_id] = true;
        }
        foreach ($bySite as $contacts) {
            if (count($contacts) > 1) {
                return true;
            }
        }

        return false;
    }

    /** @return list<array{type: string, value: string}> */
    private function identifiers(Workspace $workspace, Site $site, IdentityInput $in): array
    {
        $out = [];
        if ($in->customerRef !== null) {
            // customer_ref es por sitio: wc:77 de dos tiendas son personas distintas.
            $out[] = ['type' => 'customer_ref', 'value' => $site->id.':'.$in->customerRef];
        }
        if ($in->email !== null && filter_var($in->email, FILTER_VALIDATE_EMAIL)) {
            $out[] = ['type' => 'email_hash', 'value' => BlindIndex::email($workspace->id, $in->email)];
        }
        $e164 = Phone::toE164($in->phone, $in->country ?? $workspace->setting('default_phone_country', $workspace->country));
        if ($e164 !== null) {
            $out[] = ['type' => 'phone_hash', 'value' => BlindIndex::phone($workspace->id, $e164)];
        }
        if ($in->anonymousId !== null) {
            $out[] = ['type' => 'anonymous_id', 'value' => mb_substr($in->anonymousId, 0, 128)];
        }
        usort($out, fn ($a, $b) => self::RANK[$a['type']] <=> self::RANK[$b['type']]);

        return $out;
    }

    /**
     * @param  array<string, string>  $owners  dueño de cada identificador de este evento (tipo|valor => contacto)
     */
    private function updateProfile(Contact $contact, Workspace $workspace, IdentityInput $in, CarbonInterface $seenAt, array $owners): void
    {
        $set = function (string $field, ?string $value) use ($contact, $in): void {
            if ($value !== null && ($in->overwriteProfile || $contact->getAttribute($field) === null || $contact->getAttribute($field) === '')) {
                $contact->setAttribute($field, $value);
            }
        };

        if ($in->email !== null && filter_var($in->email, FILTER_VALIDATE_EMAIL)) {
            $bidx = BlindIndex::email($workspace->id, $in->email);
            if ($contact->email_bidx !== $bidx && ($contact->email_bidx === null || $in->overwriteProfile)) {
                // Solo si el correo no es de otro contacto.
                $owner = $owners['email_hash|'.$bidx] ?? null;
                if ($owner === null || $owner === $contact->id) {
                    $contact->email = BlindIndex::normalizeEmail($in->email);
                    $contact->email_bidx = $bidx;
                }
            }
        }
        $e164 = Phone::toE164($in->phone, $in->country ?? $workspace->setting('default_phone_country', $workspace->country));
        $phoneBidx = $e164 !== null ? BlindIndex::phone($workspace->id, $e164) : null;
        if ($e164 !== null && $phoneBidx !== $contact->phone_bidx && ($contact->phone_bidx === null || $in->overwriteProfile)) {
            $bidx = (string) $phoneBidx;
            $owner = $owners['phone_hash|'.$bidx] ?? null;
            if ($owner === null || $owner === $contact->id) {
                $contact->phone = $e164;
                $contact->phone_bidx = $bidx;
            }
        }
        $set('first_name', $in->firstName !== null ? mb_substr($in->firstName, 0, 120) : null);
        $set('last_name', $in->lastName !== null ? mb_substr($in->lastName, 0, 120) : null);
        $set('city', $in->city !== null ? mb_substr($in->city, 0, 120) : null);
        $set('region_code', $in->regionCode !== null ? mb_substr($in->regionCode, 0, 16) : null);
        $set('country', $in->country !== null && strlen($in->country) === 2 ? strtoupper($in->country) : null);
        $set('locale', $in->locale !== null ? mb_substr($in->locale, 0, 10) : null);

        if ($contact->first_name !== null && ($contact->isDirty('first_name') || $contact->isDirty('last_name') || $contact->name_prefix_bidx === null)) {
            $contact->name_prefix_bidx = self::namePrefixes($workspace->id, $contact->first_name.' '.($contact->last_name ?? ''));
        }
        if ($contact->first_seen_at === null || $contact->first_seen_at->gt($seenAt)) {
            $contact->first_seen_at = $seenAt;
        }
        if ($contact->last_seen_at === null || $contact->last_seen_at->lt($seenAt->subHour())) {
            $contact->last_seen_at = $seenAt;
        }
        if (! $contact->exists || $contact->isDirty()) {
            $contact->save();
        }
    }

    /**
     * Índices ciegos de los prefijos de cada palabra del nombre (2 a 6 letras),
     * para la búsqueda por prefijo de modulos.md M03 sin guardar el nombre en claro.
     *
     * @return list<string>
     */
    public static function namePrefixes(string $workspaceId, string $name): array
    {
        $out = [];
        $words = preg_split('/\s+/u', mb_strtolower(trim(\Normalizer::normalize($name, \Normalizer::FORM_D) ?: $name))) ?: [];
        foreach ($words as $word) {
            $word = (string) preg_replace('/\p{Mn}/u', '', $word);
            for ($len = 2; $len <= min(6, mb_strlen($word)); $len++) {
                $out[] = substr(BlindIndex::value($workspaceId, 'name', mb_substr($word, 0, $len)), 0, 16);
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Para logs: nunca el dato en claro.
     *
     * @return array<string, mixed>
     */
    public static function describe(IdentityInput $in): array
    {
        return Pii::mask(['email' => $in->email, 'phone' => $in->phone, 'customer_ref' => $in->customerRef]);
    }
}
