<?php

declare(strict_types=1);

namespace App\Domain\Workspaces\Data;

use Spatie\LaravelData\Attributes\Validation\In;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Timezone;
use Spatie\LaravelData\Data;

final class WorkspaceData extends Data
{
    public function __construct(
        #[Min(2), Max(120)]
        public string $name,
        #[In(['CO', 'MX', 'VE', 'PE', 'CL', 'EC', 'AR', 'US', 'ES', 'PA', 'CR', 'GT', 'DO'])]
        public string $country = 'CO',
        #[Timezone]
        public string $timezone = 'America/Bogota',
        #[In(['COP', 'MXN', 'USD', 'PEN', 'CLP', 'ARS', 'EUR', 'VES'])]
        public string $currency = 'COP',
        #[In(['es-CO', 'es-MX', 'es-VE'])]
        public string $locale = 'es-CO',
        #[Max(40)]
        public ?string $industry = null,
    ) {}
}
