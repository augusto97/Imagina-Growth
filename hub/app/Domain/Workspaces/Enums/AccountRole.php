<?php

declare(strict_types=1);

namespace App\Domain\Workspaces\Enums;

enum AccountRole: string
{
    case AgencyOwner = 'agency_owner';
    case AgencyMember = 'agency_member';
}
