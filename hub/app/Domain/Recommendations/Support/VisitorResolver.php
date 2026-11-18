<?php

declare(strict_types=1);

namespace App\Domain\Recommendations\Support;

use App\Domain\Contacts\Models\Contact;
use App\Domain\Contacts\Models\ContactIdentity;
use App\Domain\Identity\SiteToken;
use App\Domain\Sites\Models\Site;

/**
 * Quién es el visitante de un endpoint público (api-connector.md §8.3), dentro
 * del espacio de trabajo activo:
 *
 * - `igrUser.token` válido → el cliente con sesión (`customer_ref` del sitio).
 *   Solo con token se pueden mostrar datos personales (nombre, puntos).
 * - `anonymous_id` → el contacto al que ya se vinculó ese navegador, para
 *   personalizar sin mostrar datos personales.
 *
 * Sigue las fusiones (`merged_into_id`).
 */
final class VisitorResolver
{
    /** ID de contacto de un `igrUser.token` válido, o null. */
    public function fromToken(Site $site, ?string $token): ?string
    {
        $payload = SiteToken::verify($site, $token);
        $ref = is_array($payload) && is_string($payload['ref'] ?? null) ? $payload['ref'] : null;
        if ($ref === null || $ref === '') {
            return null;
        }
        $id = ContactIdentity::query()->where('type', 'customer_ref')->where('value', $site->id.':'.$ref)->value('contact_id');

        return is_string($id) ? $this->live($id) : null;
    }

    /** ID del contacto vinculado a un navegador, o null. */
    public function fromAnonymous(?string $anonymousId): ?string
    {
        $anonymousId = self::cleanAnonymousId($anonymousId);
        if ($anonymousId === null) {
            return null;
        }
        $id = ContactIdentity::query()->where('type', 'anonymous_id')->where('value', $anonymousId)->value('contact_id');

        return is_string($id) ? $this->live($id) : null;
    }

    /**
     * Los navegadores vinculados a un contacto (los más recientes primero).
     *
     * @return list<string>
     */
    public function anonymousIds(string $contactId, int $limit = 20): array
    {
        return array_values(ContactIdentity::query()->where('contact_id', $contactId)->where('type', 'anonymous_id')
            ->orderByDesc('last_seen_at')->limit($limit)->pluck('value')->map(fn ($v) => (string) $v)->all());
    }

    public static function cleanAnonymousId(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[A-Za-z0-9._-]{8,64}$/', $value) === 1 ? $value : null;
    }

    private function live(string $contactId): ?string
    {
        for ($hops = 0; $hops < 5; $hops++) {
            $row = Contact::withTrashed()->whereKey($contactId)->first(['id', 'merged_into_id', 'deleted_at']);
            if ($row === null) {
                return null;
            }
            $next = $row->getAttribute('merged_into_id');
            if (! is_string($next) || $next === '') {
                return $row->getAttribute('deleted_at') === null ? $row->id : null;
            }
            $contactId = $next;
        }

        return null;
    }
}
