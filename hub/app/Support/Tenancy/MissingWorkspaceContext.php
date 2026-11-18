<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use LogicException;

final class MissingWorkspaceContext extends LogicException
{
    public function __construct()
    {
        parent::__construct('No hay un espacio de trabajo activo. Toda consulta de negocio debe ir filtrada por workspace_id.');
    }
}
