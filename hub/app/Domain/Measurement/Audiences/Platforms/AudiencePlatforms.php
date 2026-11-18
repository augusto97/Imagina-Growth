<?php

declare(strict_types=1);

namespace App\Domain\Measurement\Audiences\Platforms;

use App\Domain\Measurement\Enums\Platform;

/** La plataforma de audiencias de cada destino de anuncios. */
final class AudiencePlatforms
{
    public function __construct(
        private readonly MetaAudiences $meta,
        private readonly GoogleAudiences $google,
        private readonly TikTokAudiences $tiktok,
    ) {}

    public function for(Platform $platform): AudiencePlatform
    {
        return match ($platform) {
            Platform::Meta => $this->meta,
            Platform::Google => $this->google,
            Platform::TikTok => $this->tiktok,
        };
    }
}
