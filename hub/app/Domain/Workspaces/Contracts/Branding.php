<?php

declare(strict_types=1);

namespace App\Domain\Workspaces\Contracts;

use App\Domain\Workspaces\Data\BrandingData;
use App\Domain\Workspaces\Models\Workspace;

/**
 * La marca con la que el cliente ve todo lo que no es la tienda (M22): el panel,
 * los correos del sistema, el reporte mensual en PDF, la página de reservas, el
 * portal de creadores. Con marca blanca es la de la agencia; si no, Imagina
 * Growth. Por defecto `DefaultBranding`; la agencia la reemplaza.
 */
interface Branding
{
    public function for(Workspace $workspace): BrandingData;
}
