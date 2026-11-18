<?php

declare(strict_types=1);

namespace App\Domain\Workspaces\Data;

final class BrandingData
{
    public function __construct(
        public readonly string $name,                 // «Imagina Growth» o el de la agencia
        public readonly ?string $logoUrl,
        public readonly string $primaryColor,         // #RRGGBB
        public readonly string $senderName,
        public readonly string $senderEmail,
        public readonly ?string $supportEmail,
        public readonly ?string $panelUrl,            // dominio propio del panel, si está verificado
        public readonly bool $whiteLabel,             // oculta «Imagina Growth»
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'name' => $this->name, 'logo_url' => $this->logoUrl, 'primary_color' => $this->primaryColor,
            'sender_name' => $this->senderName, 'sender_email' => $this->senderEmail, 'support_email' => $this->supportEmail,
            'panel_url' => $this->panelUrl, 'white_label' => $this->whiteLabel,
        ];
    }
}
