<?php

declare(strict_types=1);

namespace App\Ingest\Schema;

/**
 * Eventos que el navegador puede enviar por `/v1/b` (docs/eventos.md §4.1).
 *
 * La clave pública del tracker está en el HTML de cada página: cualquiera puede usarla. Por eso
 * el navegador solo informa navegación; los pedidos, carritos, clientes, formularios, reseñas
 * y catálogo llegan firmados por el plugin (`/v1/s`).
 */
final class BrowserEvents
{
    /** @var list<string> */
    public const NAMES = [
        'page.viewed',
        'page.engaged',
        'product.viewed',
        'category.viewed',
        'search.performed',
        'cta.clicked',
        'widget.shown',
        'widget.submitted',
        'widget.closed',
        'identify',
    ];

    /** Margen para relojes adelantados del visitante o del servidor. */
    public const FUTURE_TOLERANCE_SECONDS = 300;

    /** El tracker reintenta lo pendiente al volver; un evento más viejo no es de esta visita. */
    public const MAX_AGE_SECONDS = 7 * 86400;

    public static function allows(string $event): bool
    {
        return in_array($event, self::NAMES, true) || str_starts_with($event, 'custom.');
    }
}
