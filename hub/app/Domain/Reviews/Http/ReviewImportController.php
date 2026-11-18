<?php

declare(strict_types=1);

namespace App\Domain\Reviews\Http;

use App\Domain\Reviews\Actions\ImportReviews;
use App\Domain\Sites\Models\Site;
use App\Domain\Workspaces\Actions\RecordAuditLog;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * POST reviews/imports (multipart): CSV propio o exportación de Judge.me (M15).
 * Las reseñas nativas de WooCommerce llegan solas con la importación histórica
 * y en vivo (review.submitted). manageMarketing; queda en la auditoría.
 */
final class ReviewImportController
{
    public function __invoke(Request $request, ImportReviews $import, CurrentWorkspace $current, RecordAuditLog $audit): JsonResponse
    {
        Gate::authorize('ws.manageMarketing');
        $data = $request->validate([
            'file' => ['required', 'file', 'max:5120', 'mimetypes:text/plain,text/csv,application/csv,application/vnd.ms-excel'],
            'format' => ['required', 'in:csv,judgeme'],
            'site_id' => ['nullable', 'string', 'max:40'],
        ]);
        $site = isset($data['site_id']) && $data['site_id'] !== ''
            ? Site::findByPublicId((string) $data['site_id'])
            : Site::query()->orderBy('created_at')->first();
        if ($site === null) {
            throw ValidationException::withMessages(['site_id' => __('Conecta un sitio antes de importar reseñas.')]);
        }
        $result = $import->handle($current->get(), $site, (string) $request->file('file')?->getRealPath(), $data['format']);
        $audit->handle('reviews.imported', $site, ['format' => $data['format'], 'rows' => $result['rows'], 'created' => $result['created']], $current->id());

        return response()->json($result);
    }
}
