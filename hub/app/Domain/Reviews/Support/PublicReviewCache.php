<?php

declare(strict_types=1);

namespace App\Domain\Reviews\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Versión de la caché de los endpoints públicos de reseñas por sitio: publicar,
 * retirar o responder una reseña la cambia y las respuestas en Redis se renuevan
 * al instante (la del CDN dura como máximo sus 5 minutos).
 */
final class PublicReviewCache
{
    public static function bump(string $siteId): void
    {
        Cache::forever('igr:reviews:ver:'.$siteId, (string) microtime(true));
    }

    public static function version(string $siteId): string
    {
        $v = Cache::get('igr:reviews:ver:'.$siteId);

        return is_string($v) ? $v : '0';
    }
}
