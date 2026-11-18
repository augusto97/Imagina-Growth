<?php

declare(strict_types=1);

namespace App\Domain\Loyalty\Contracts;

use App\Domain\Contacts\Models\Contact;
use App\Domain\Sites\Models\Site;

/**
 * Sección que otro módulo agrega a «Mis recompensas» (`GET /p/{key}/me`,
 * api-connector.md §8.3) sin tocar el controlador de M13. Por ejemplo M14
 * agrega `referral`. Se registra con una etiqueta del contenedor:
 *
 *     $this->app->tag([ReferralMeSection::class], MeSection::TAG);
 *
 * Corre dentro del contexto del espacio de trabajo y solo con un igrUser.token
 * válido; `contact` es null si la persona aún no está en el hub.
 */
interface MeSection
{
    public const TAG = 'igr.loyalty.me_sections';

    /** Clave en la respuesta (`referral`). */
    public function key(): string;

    /** @return array<string, mixed>|null null = la sección no aparece */
    public function forContact(Site $site, ?Contact $contact): ?array;
}
