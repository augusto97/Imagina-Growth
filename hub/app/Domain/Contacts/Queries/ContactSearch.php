<?php

declare(strict_types=1);

namespace App\Domain\Contacts\Queries;

use App\Domain\Contacts\Models\Contact;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Phone\Phone;
use App\Support\Pii\BlindIndex;
use Illuminate\Database\Eloquent\Builder;

/**
 * Búsqueda de contactos con el índice ciego (CLAUDE.md §3.9): correo y teléfono exactos, y el nombre por el comienzo
 * de la primera palabra. Las llaves del índice son del espacio de trabajo, así que un mismo correo nunca encuentra
 * contactos de otro espacio. La usan la lista de contactos y la paleta ⌘K.
 */
final class ContactSearch
{
    /** @param Builder<Contact> $query */
    public static function apply(Builder $query, Workspace $workspace, string $q): void
    {
        $q = trim($q);
        if ($q === '') {
            return;
        }
        $wsId = $workspace->id;
        $query->where(function (Builder $w) use ($q, $wsId, $workspace): void {
            $w->where('email_bidx', BlindIndex::email($wsId, $q));
            $digits = preg_replace('/[^0-9+]/', '', $q) ?? '';
            if (strlen($digits) >= 7) {
                $e164 = Phone::toE164($digits, $workspace->country);
                if ($e164 !== null) {
                    $w->orWhere('phone_bidx', BlindIndex::phone($wsId, $e164));
                }
            }
            $prefixes = self::namePrefixes($wsId, $q);
            if ($prefixes !== []) {
                // Cada palabra escrita debe estar al comienzo de alguna palabra del nombre («ana ru» → Ana Ruiz).
                $w->orWhere(function (Builder $n) use ($prefixes): void {
                    foreach ($prefixes as $prefix) {
                        $n->whereJsonContains('name_prefix_bidx', $prefix);
                    }
                });
            }
        });
    }

    /**
     * Las mismas llaves que guarda IdentityResolver::namePrefixes (sin tildes, 2 a 6 letras), una por palabra.
     *
     * @return list<string>
     */
    public static function namePrefixes(string $workspaceId, string $q): array
    {
        if (str_contains($q, '@')) {
            return [];
        }
        $normalized = mb_strtolower(trim(\Normalizer::normalize($q, \Normalizer::FORM_D) ?: $q));
        $out = [];
        foreach (array_slice(preg_split('/\s+/u', $normalized) ?: [], 0, 3) as $word) {
            $word = (string) preg_replace('/\p{Mn}/u', '', $word);
            if (mb_strlen($word) >= 2) {
                $out[] = substr(BlindIndex::value($workspaceId, 'name', mb_substr($word, 0, 6)), 0, 16);
            }
        }

        return $out;
    }
}
