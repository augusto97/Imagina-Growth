<?php

declare(strict_types=1);

namespace App\Domain\Templates\Support;

use App\Domain\Workspaces\Models\Workspace;

/**
 * Datos del negocio para el pie legal de los correos (cumplimiento.md §8:
 * identificación del remitente y dirección física o de contacto) y para
 * `tienda.*` en las plantillas. Viven en `workspaces.settings.business_profile`.
 */
final class BusinessProfile
{
    public const FIELDS = ['name', 'address', 'contact', 'whatsapp', 'logo_url'];

    /** @return array{name: string, address: string, contact: string, whatsapp: string, logo_url: string} */
    public static function for(Workspace $workspace): array
    {
        $stored = $workspace->setting('business_profile', []);
        $stored = is_array($stored) ? $stored : [];
        $out = [];
        foreach (self::FIELDS as $field) {
            $out[$field] = is_string($stored[$field] ?? null) ? $stored[$field] : '';
        }

        /** @var array{name: string, address: string, contact: string, whatsapp: string, logo_url: string} $out */
        return $out;
    }

    /** ¿Tiene lo mínimo para el pie legal? */
    public static function isComplete(Workspace $workspace): bool
    {
        $p = self::for($workspace);

        return trim($p['address']) !== '' || trim($p['contact']) !== '';
    }
}
