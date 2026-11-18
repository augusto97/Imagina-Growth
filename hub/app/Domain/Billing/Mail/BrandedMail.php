<?php

declare(strict_types=1);

namespace App\Domain\Billing\Mail;

use App\Domain\Workspaces\Data\BrandingData;
use Illuminate\Mail\Mailables\Address;

/** El remitente de los correos de facturación es la marca del cliente (Imagina Growth o la agencia). */
trait BrandedMail
{
    protected function brandFrom(BrandingData $brand): ?Address
    {
        return filter_var($brand->senderEmail, FILTER_VALIDATE_EMAIL) !== false ? new Address($brand->senderEmail, $brand->senderName) : null;
    }
}
