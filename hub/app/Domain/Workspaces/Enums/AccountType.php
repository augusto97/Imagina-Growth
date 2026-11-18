<?php

declare(strict_types=1);

namespace App\Domain\Workspaces\Enums;

enum AccountType: string
{
    case Agency = 'agency';
    case Direct = 'direct';
}
