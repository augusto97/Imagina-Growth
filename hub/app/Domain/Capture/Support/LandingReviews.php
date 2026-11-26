<?php

declare(strict_types=1);

namespace App\Domain\Capture\Support;

use App\Domain\Reviews\Models\Review;
use App\Domain\Workspaces\Models\Workspace;
use Illuminate\Support\Facades\Cache;

/**
 * La prueba social de una página de captura (M12 con M15): el promedio y el total de las reseñas **publicadas** del
 * negocio y hasta tres con texto y 4 o 5 estrellas, las más recientes. Lo mismo que ya muestra el widget público de
 * reseñas (nombre como lo dejó la persona, sin correo ni teléfono). En caché 10 minutos por espacio de trabajo.
 */
final class LandingReviews
{
    public const TTL_SECONDS = 600;

    /** @return array{avg: float, count: int, items: list<array{rating: int, title: string|null, body: string, author: string}>}|null */
    public static function summary(Workspace $workspace): ?array
    {
        /** @var array{avg: float, count: int, items: list<array{rating: int, title: string|null, body: string, author: string}>}|null $cached */
        $cached = Cache::remember('ws:'.$workspace->id.':landing:reviews', self::TTL_SECONDS, function () use ($workspace): ?array {
            $base = fn () => Review::withoutWorkspaceScope()->where('workspace_id', $workspace->id)->where('status', 'published');
            $count = (int) $base()->count();
            if ($count === 0) {
                return null;
            }
            $items = $base()->where('rating', '>=', 4)->whereNotNull('body')->where('body', '!=', '')
                ->orderByDesc('published_at')->orderByDesc('id')->limit(3)->get(['rating', 'title', 'body', 'author_name'])
                ->map(fn (Review $r) => [
                    'rating' => (int) $r->rating,
                    'title' => $r->title,
                    'body' => mb_substr((string) $r->body, 0, 280),
                    'author' => $r->author_name ?: (string) __('Cliente'),
                ])->values()->all();

            return ['avg' => round((float) $base()->avg('rating'), 1), 'count' => $count, 'items' => $items];
        });

        return $cached;
    }
}
