<?php

declare(strict_types=1);

namespace App\Domain\Workspaces\Support;

use App\Domain\Workspaces\Contracts\Branding;
use App\Domain\Workspaces\Data\BrandingData;
use App\Domain\Workspaces\Models\Workspace;

/** Imagina Growth, sin marca blanca. */
final class DefaultBranding implements Branding
{
    public function for(Workspace $workspace): BrandingData
    {
        return new BrandingData(
            name: 'Imagina Growth',
            logoUrl: null,
            primaryColor: '#2563EB',
            senderName: (string) config('mail.from.name', 'Imagina Growth'),
            senderEmail: (string) config('mail.from.address'),
            supportEmail: null,
            panelUrl: rtrim((string) config('app.url'), '/'),
            whiteLabel: false,
        );
    }
}
