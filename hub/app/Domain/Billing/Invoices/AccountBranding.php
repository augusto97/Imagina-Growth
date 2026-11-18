<?php

declare(strict_types=1);

namespace App\Domain\Billing\Invoices;

use App\Domain\Workspaces\Contracts\Branding;
use App\Domain\Workspaces\Data\BrandingData;
use App\Domain\Workspaces\Models\Account;
use App\Domain\Workspaces\Models\Workspace;

/**
 * La marca de la facturación de una cuenta: la de su primer espacio de trabajo
 * (con marca blanca, la de la agencia). Sin espacios, la de la plataforma.
 */
final class AccountBranding
{
    public function __construct(private readonly Branding $branding) {}

    public function for(Account $account): BrandingData
    {
        $workspace = Workspace::query()->where('account_id', $account->id)->orderBy('created_at')->first();
        if ($workspace !== null) {
            return $this->branding->for($workspace);
        }
        $from = (string) config('mail.from.address', 'hola@growth.imagina.cloud');

        return new BrandingData('Imagina Growth', null, '#4F46E5', 'Imagina Growth', $from, $from, null, false);
    }

    public static function panelUrl(Account $account): string
    {
        $workspace = Workspace::query()->where('account_id', $account->id)->orderBy('created_at')->first();

        return rtrim((string) config('app.url'), '/').($workspace !== null ? '/w/'.$workspace->publicId().'/facturacion' : '');
    }
}
