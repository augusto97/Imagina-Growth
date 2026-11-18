<?php

declare(strict_types=1);

namespace App\Domain\Reviews\Http\Public;

use App\Domain\Reviews\Models\Review;
use App\Domain\Reviews\Support\ReviewMediaStore;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /r/m/{reseña}/{nombre}: una foto, miniatura o video de una reseña. Solo
 * responde si la reseña está publicada (widgets y WooCommerce) o con una URL
 * firmada y temporal (moderación en el panel). Los nombres son aleatorios.
 */
final class ReviewMediaController
{
    public function __invoke(Request $request, string $review, string $name, ReviewMediaStore $store): Response
    {
        $model = Review::withoutWorkspaceScope()->find($review);
        if ($model === null) {
            abort(404);
        }
        $signed = $request->hasValidSignature();
        if ($model->status !== 'published' && ! $signed) {
            abort(404);
        }
        $entry = null;
        foreach ($model->media ?? [] as $m) {
            if (is_array($m) && (($m['key'] ?? null) === $name || ($m['thumb_key'] ?? null) === $name)) {
                $entry = $m;
                break;
            }
        }
        if ($entry === null) {
            abort(404);
        }
        $path = ReviewMediaStore::dir($model->workspace_id, $model->id).'/'.$name;
        $disk = $store->disk();
        if (! $disk->exists($path)) {
            abort(404);
        }
        $mime = str_ends_with($name, '.jpg') ? 'image/jpeg' : (string) ($entry['mime'] ?? 'application/octet-stream');
        $headers = [
            'Content-Type' => $mime,
            'Cache-Control' => $signed ? 'private, no-store' : 'public, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'",
            'Access-Control-Allow-Origin' => '*',
        ];
        if ($disk instanceof FilesystemAdapter && config('filesystems.disks.'.config('igr-reviews.disk', 'local').'.driver') === 'local') {
            // BinaryFileResponse: admite Range (los videos se pueden adelantar).
            return response()->file($disk->path($path), $headers);
        }

        return $disk->response($path, $name, $headers);
    }
}
