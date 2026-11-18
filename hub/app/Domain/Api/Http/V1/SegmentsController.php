<?php

declare(strict_types=1);

namespace App\Domain\Api\Http\V1;

use App\Domain\Api\Support\ApiError;
use App\Domain\Segments\Models\Segment;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Segmentos (M04) con su número de miembros (solo lectura).
 */
#[Group('Segmentos', weight: 4)]
final class SegmentsController
{
    use Paginates;

    /**
     * Listar segmentos
     *
     * Con `member_count` del último cálculo. Requiere `segments:read`.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate(['limit' => ['sometimes', 'integer', 'min:1', 'max:100']]);

        return $this->paginate(Segment::query(), $request, fn (Segment $s) => Presenters::segment($s));
    }

    /**
     * Ver un segmento
     *
     * Requiere `segments:read`.
     */
    public function show(string $segment): JsonResponse
    {
        $model = Segment::findByPublicId($segment);
        if ($model === null) {
            return ApiError::make(404, 'not_found', __('No existe ese segmento.'));
        }

        return new JsonResponse(['data' => Presenters::segment($model)]);
    }
}
