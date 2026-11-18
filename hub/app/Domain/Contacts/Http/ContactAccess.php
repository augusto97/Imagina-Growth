<?php

declare(strict_types=1);

namespace App\Domain\Contacts\Http;

use App\Domain\Contacts\Models\Contact;
use App\Support\Ids\PrefixedId;
use App\Support\Pii\Pii;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/**
 * Reglas de acceso a contactos por rol (modulos.md M01): owner, admin y
 * marketing ven todos; agent solo los asignados (conversaciones, Fase 4: hoy
 * ninguno). Los datos de contacto se enmascaran salvo para quien puede exportar.
 */
final class ContactAccess
{
    /** @param Builder<Contact> $query */
    public static function scope(Builder $query): void
    {
        if (! Gate::allows('ws.viewAllContacts')) {
            $query->whereRaw('1 = 0');
        }
    }

    /** Busca un contacto vigente al que la persona tiene acceso, o 404. */
    public static function find(string $publicId): Contact
    {
        Gate::authorize('ws.viewAssignedContacts');
        $id = PrefixedId::decode(Contact::ID_PREFIX, $publicId);
        $query = Contact::query()->whereKey($id ?? '-');
        self::scope($query);

        return $query->first() ?? abort(404);
    }

    public static function canSeePii(): bool
    {
        return Gate::allows('ws.exportContacts');
    }

    public static function email(?string $email): ?string
    {
        return $email === null ? null : (self::canSeePii() ? $email : Pii::maskEmail($email));
    }

    public static function phone(?string $phone): ?string
    {
        return $phone === null ? null : (self::canSeePii() ? $phone : Pii::maskPhone($phone));
    }
}
