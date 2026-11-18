<?php

declare(strict_types=1);

namespace App\Domain\Identity;

use App\Domain\Contacts\Models\Contact;
use App\Domain\Contacts\Models\ContactIdentity;
use App\Domain\Sites\Models\Site;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Phone\Phone;
use App\Support\Pii\BlindIndex;
use App\Support\Pii\Pii;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;

/**
 * Resolución de identidad determinista (eventos.md §6.2). Nunca une perfiles
 * por probabilidad. Orden de fuerza: customer_ref > email > teléfono > anonymous_id.
 *
 * Fase 0: crea o encuentra el contacto y agrega los identificadores que le
 * faltan. Si dos contactos distintos comparten identificadores fuertes, gana
 * el más fuerte y el conflicto queda en el log; la fusión auditada y
 * reversible llega en la Fase 1 (M03).
 *
 * Debe ejecutarse con el espacio de trabajo activo (CurrentWorkspace).
 */
final class IdentityResolver
{
    private const RANK = ['customer_ref' => 1, 'email_hash' => 2, 'phone_hash' => 3, 'anonymous_id' => 6];

    /** @return array{contact: Contact|null, created: bool, linked_anonymous: bool} */
    public function resolve(Workspace $workspace, Site $site, IdentityInput $in, CarbonInterface $seenAt): array
    {
        $identifiers = $this->identifiers($workspace, $site, $in);
        $strong = array_filter($identifiers, fn ($i) => $i['type'] !== 'anonymous_id');
        if ($strong === [] && $in->anonymousId === null) {
            return ['contact' => null, 'created' => false, 'linked_anonymous' => false];
        }

        $existing = ContactIdentity::query()
            ->where(function ($q) use ($identifiers): void {
                foreach ($identifiers as $i) {
                    $q->orWhere(fn ($w) => $w->where('type', $i['type'])->where('value', $i['value']));
                }
            })
            ->get()
            ->keyBy(fn (ContactIdentity $i) => $i->type.'|'.$i->value);

        // El contacto del identificador más fuerte que ya existe.
        $contactId = null;
        $byContact = [];
        foreach ($identifiers as $i) {
            $found = $existing->get($i['type'].'|'.$i['value']);
            if ($found !== null) {
                $byContact[$found->contact_id][] = $i['type'];
                if ($i['type'] !== 'anonymous_id' || $strong === []) {
                    $contactId ??= $found->contact_id;
                }
            }
        }
        if (count(array_diff(array_keys($byContact), $contactId ? [$contactId] : [])) > 0 && $strong !== []) {
            Log::info('igr.identity.conflict', [
                'workspace' => $workspace->id,
                'winner' => $contactId,
                'others' => array_values(array_diff(array_keys($byContact), [$contactId])),
            ]);
        }

        $created = false;
        if ($contactId === null) {
            if ($strong === []) {
                // Solo anónimo y desconocido: no se crea contacto (es un visitante sin identificar).
                return ['contact' => null, 'created' => false, 'linked_anonymous' => false];
            }
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
            $contact = Contact::query()->find($contactId);
            if ($contact === null) {
                return ['contact' => null, 'created' => false, 'linked_anonymous' => false];
            }
        }

        $linkedAnonymous = false;
        /** @var array<string, string> $owners tipo|valor => contacto dueño */
        $owners = [];
        foreach ($identifiers as $i) {
            $found = $existing->get($i['type'].'|'.$i['value']);
            $owners[$i['type'].'|'.$i['value']] = $found !== null ? $found->contact_id : $contact->id;
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
                $linkedAnonymous = $linkedAnonymous || $i['type'] === 'anonymous_id';
            } elseif ($found->contact_id === $contact->id && ($found->last_seen_at === null || $found->last_seen_at->lt($seenAt->subHour()))) {
                // Con resolución de una hora basta; así no se escribe en cada evento.
                $found->forceFill(['last_seen_at' => $seenAt])->save();
            }
        }

        $this->updateProfile($contact, $workspace, $in, $seenAt, $owners);

        return ['contact' => $contact, 'created' => $created, 'linked_anonymous' => $linkedAnonymous];
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
