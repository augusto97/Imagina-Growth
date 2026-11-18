<?php

declare(strict_types=1);

namespace App\Domain\Api\Http\V1;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Paginación por cursor de la API pública: `?limit=` (1 a 100, 25 por defecto) y
 * `?cursor=` con el `next_cursor` de la página anterior. Orden: lo más nuevo primero.
 */
trait Paginates
{
    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  callable(TModel): array<string, mixed>  $map
     */
    protected function paginate(Builder $query, Request $request, callable $map): JsonResponse
    {
        $limit = max(1, min(100, (int) $request->query('limit', '25')));
        $page = $query->orderByDesc($query->getModel()->getQualifiedKeyName())->cursorPaginate($limit);

        return new JsonResponse([
            'data' => array_map($map, $page->items()),
            'next_cursor' => $page->nextCursor()?->encode(),
        ]);
    }

    protected function since(Request $request, string $param): ?string
    {
        $value = $request->query($param);
        if (! is_string($value) || $value === '') {
            return null;
        }
        $time = strtotime($value);

        return $time === false ? null : gmdate('Y-m-d H:i:s', $time);
    }
}
