<?php

declare(strict_types=1);

namespace App\Domain\Api\Actions;

use App\Domain\Contacts\Models\Contact;
use App\Domain\Identity\IdentityInput;
use App\Domain\Identity\IdentityResolver;
use App\Domain\Sites\Models\Site;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Phone\Phone;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Crea o une un contacto con los datos que manda la API (la misma resolución de identidad
 * de la ingesta: correo y teléfono con índice ciego). Devuelve el contacto y si es nuevo.
 */
final class ResolveApiContact
{
    public function __construct(private readonly IdentityResolver $identity) {}

    /**
     * El sitio con el que se registra la identidad: el pedido o el primero conectado.
     */
    public static function site(?string $publicId): ?Site
    {
        if ($publicId !== null && $publicId !== '') {
            return Site::findByPublicId($publicId);
        }

        return Site::query()->whereIn('status', [Site::STATUS_CONNECTED, Site::STATUS_DEGRADED])->orderBy('created_at')->first()
            ?? Site::query()->orderBy('created_at')->first();
    }

    /**
     * @param  array{email?: string|null, phone?: string|null, first_name?: string|null, last_name?: string|null, city?: string|null, country?: string|null, overwrite?: bool}  $data
     * @return array{0: Contact, 1: bool}
     */
    public function handle(Workspace $workspace, Site $site, array $data): array
    {
        $email = isset($data['email']) && filter_var(trim((string) $data['email']), FILTER_VALIDATE_EMAIL) ? trim((string) $data['email']) : null;
        $country = isset($data['country']) ? strtoupper((string) $data['country']) : null;
        $phone = isset($data['phone']) ? Phone::toE164((string) $data['phone'], $country ?? (string) $workspace->setting('default_phone_country', $workspace->country)) : null;
        if ($email === null && $phone === null) {
            throw new InvalidArgumentException(__('Escribe un correo o un WhatsApp válido.'));
        }
        $resolved = $this->identity->resolve($workspace, $site, new IdentityInput(
            email: $email,
            phone: $phone,
            firstName: isset($data['first_name']) ? mb_substr(trim((string) $data['first_name']), 0, 80) : null,
            lastName: isset($data['last_name']) ? mb_substr(trim((string) $data['last_name']), 0, 80) : null,
            city: $data['city'] ?? null,
            country: $country,
            overwriteProfile: (bool) ($data['overwrite'] ?? false),
        ), CarbonImmutable::now());

        return [Contact::query()->findOrFail((string) $resolved['contact_id']), (bool) $resolved['created']];
    }
}
