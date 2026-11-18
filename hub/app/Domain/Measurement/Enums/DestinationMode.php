<?php

declare(strict_types=1);

namespace App\Domain\Measurement\Enums;

/** Modos de M06: solo servidor, compatible con un píxel ya instalado, o completo (el tracker carga el píxel). */
enum DestinationMode: string
{
    case ServerOnly = 'server_only';
    case WithPixel = 'with_pixel';
    case Full = 'full';
}
