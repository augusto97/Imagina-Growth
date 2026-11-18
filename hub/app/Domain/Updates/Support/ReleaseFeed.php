<?php

declare(strict_types=1);

namespace App\Domain\Updates\Support;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * La última versión publicada en GitHub Releases (`GET /repos/{repo}/releases/latest`). El repositorio es
 * público: no hace falta token. Solo sirve para mostrarla; growth-update descarga y verifica por su cuenta.
 */
final class ReleaseFeed
{
    /**
     * @return array{version: string, tag: string, name: string|null, notes: string|null, published_at: string|null, html_url: string|null}
     */
    public function latest(): array
    {
        $repo = (string) config('growth.update.repo');
        if (preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo) !== 1) {
            throw new RuntimeException('GROWTH_UPDATE_REPO no es válido.');
        }
        $url = rtrim((string) config('growth.update.api'), '/')."/repos/{$repo}/releases/latest";
        $response = Http::timeout((int) config('growth.update.timeout', 10))
            ->withHeaders(['Accept' => 'application/vnd.github+json', 'User-Agent' => 'imagina-growth-updater'])
            ->get($url);
        if (! $response->successful()) {
            throw new RuntimeException("GitHub respondió {$response->status()} al pedir la última versión.");
        }
        $tag = (string) $response->json('tag_name', '');
        $version = ltrim($tag, 'v');
        if (preg_match('/^\d+\.\d+\.\d+$/', $version) !== 1) {
            throw new RuntimeException("La última versión publicada («{$tag}») no tiene el formato vX.Y.Z.");
        }
        $notes = $response->json('body');

        return [
            'version' => $version,
            'tag' => $tag,
            'name' => is_string($response->json('name')) ? mb_substr((string) $response->json('name'), 0, 200) : null,
            'notes' => is_string($notes) ? mb_substr($notes, 0, 20_000) : null,
            'published_at' => is_string($response->json('published_at')) ? (string) $response->json('published_at') : null,
            'html_url' => is_string($response->json('html_url')) ? (string) $response->json('html_url') : null,
        ];
    }

    /** ¿`$candidate` es más nueva que `$current`? `dev` (desarrollo) nunca se actualiza desde el panel. */
    public static function isNewer(string $candidate, string $current): bool
    {
        if (preg_match('/^\d+\.\d+\.\d+$/', $candidate) !== 1 || preg_match('/^\d+\.\d+\.\d+/', $current) !== 1) {
            return false;
        }

        return version_compare($candidate, $current, '>');
    }
}
