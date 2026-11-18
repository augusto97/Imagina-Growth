<?php

declare(strict_types=1);

namespace App\Domain\Reviews\Http\Public;

use App\Domain\Reviews\Models\Review;
use App\Domain\Reviews\Support\PublicReviewCache;
use App\Domain\Reviews\Support\ReviewMediaStore;
use App\Domain\Sites\Models\Site;
use App\Ingest\Http\SiteResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Reseñas publicadas para los widgets del tracker (api-connector.md §8.3).
 * Públicos y en caché 5 minutos (CDN y Redis). Solo las publicadas, con el autor
 * como «Ana R.» y sin datos del contacto. `body` y `title` son texto plano: el
 * tracker los escapa al pintarlos.
 *
 * - `GET /p/{key}/reviews?product_id=&page=&per_page=&with_media=&sort=recent|rating`:
 *   sin `product_id` responde las reseñas de la tienda; con `scope=all`, todas
 *   las del sitio (página «Todas las reseñas»). El resumen cuenta todas las
 *   publicadas; la lista solo las que tienen texto o medios.
 * - `GET /p/{key}/reviews/badges?product_ids=88,91` → `{"88": {"avg": 4.8, "count": 23}}`.
 */
final class PublicReviewsController
{
    private const CACHE_SECONDS = 300;

    public function __construct(private readonly SiteResolver $sites) {}

    public function index(Request $request, string $publicKey): JsonResponse
    {
        $site = $this->site($publicKey);
        if ($site === null) {
            return response()->json(['error' => 'unknown_site'], 404);
        }
        $productId = $request->query('product_id');
        $productId = is_string($productId) && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $productId) === 1 ? $productId : null;
        $scopeAll = $request->query('scope') === 'all' && $productId === null;
        $page = max(1, min(500, (int) $request->query('page', '1')));
        $perPage = max(1, min(50, (int) $request->query('per_page', '10')));
        $withMedia = $request->boolean('with_media');
        $sort = $request->query('sort') === 'rating' ? 'rating' : 'recent';

        $key = 'igr:reviews:public:'.$site->id.':'.PublicReviewCache::version($site->id).':'.md5(json_encode([$productId, $scopeAll, $page, $perPage, $withMedia, $sort]) ?: '');
        $data = Cache::remember($key, self::CACHE_SECONDS, function () use ($site, $productId, $scopeAll, $page, $perPage, $withMedia, $sort): array {
            $base = fn (): Builder => Review::withoutWorkspaceScope()->where('workspace_id', $site->workspace_id)->where('site_id', $site->id)
                ->where('status', 'published')
                ->when(! $scopeAll, fn (Builder $q) => $productId === null ? $q->whereNull('external_product_id') : $q->where('external_product_id', $productId));

            $distribution = $base()->selectRaw('rating, COUNT(*) AS c')->groupBy('rating')->pluck('c', 'rating');
            $count = (int) $distribution->sum();
            $sum = 0;
            $dist = [];
            foreach ([5, 4, 3, 2, 1] as $stars) {
                $c = (int) ($distribution[$stars] ?? 0);
                $dist[(string) $stars] = $c;
                $sum += $stars * $c;
            }

            $list = $base()
                ->where(fn (Builder $q) => $q->whereNotNull('body')->where('body', '!=', '')->orWhereNotNull('media'))
                ->when($withMedia, fn (Builder $q) => $q->whereNotNull('media'))
                ->when($sort === 'rating', fn (Builder $q) => $q->orderByDesc('rating'))
                ->orderByDesc('published_at')->orderByDesc('id')
                ->offset(($page - 1) * $perPage)->limit($perPage + 1)
                ->get(['id', 'workspace_id', 'external_product_id', 'rating', 'title', 'body', 'author_name', 'verified_buyer', 'media', 'owner_reply', 'published_at', 'created_at']);
            $more = $list->count() > $perPage;

            return [
                'summary' => ['avg' => $count > 0 ? round($sum / $count, 1) : null, 'count' => $count, 'distribution' => $dist],
                'reviews' => $list->take($perPage)->map(fn (Review $r) => [
                    'id' => $r->publicId(),
                    'product_id' => $r->external_product_id,
                    'rating' => $r->rating,
                    'title' => $r->title,
                    'body' => (string) ($r->body ?? ''),
                    'author' => $r->author_name ?? __('Cliente'),
                    'verified' => $r->verified_buyer,
                    'media' => ReviewMediaStore::present($r),
                    'created_at' => ($r->published_at ?? $r->created_at)?->toIso8601String(),
                    'reply' => $r->owner_reply,
                ])->values()->all(),
                'next_page' => $more ? $page + 1 : null,
            ];
        });

        return $this->json($request, $site, $data);
    }

    public function badges(Request $request, string $publicKey): JsonResponse
    {
        $site = $this->site($publicKey);
        if ($site === null) {
            return response()->json(['error' => 'unknown_site'], 404);
        }
        $ids = array_values(array_unique(array_filter(
            array_map('trim', explode(',', (string) $request->query('product_ids', ''))),
            fn (string $id) => preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id) === 1,
        )));
        $ids = array_slice($ids, 0, 50);
        sort($ids);
        if ($ids === []) {
            return $this->json($request, $site, []);
        }
        $data = Cache::remember('igr:reviews:badges:'.$site->id.':'.PublicReviewCache::version($site->id).':'.md5(implode(',', $ids)), self::CACHE_SECONDS, function () use ($site, $ids): array {
            $rows = Review::withoutWorkspaceScope()->where('workspace_id', $site->workspace_id)->where('site_id', $site->id)
                ->where('status', 'published')->whereIn('external_product_id', $ids)
                ->groupBy('external_product_id')->selectRaw('external_product_id, AVG(rating) AS avg_rating, COUNT(*) AS c')->get();
            $out = [];
            foreach ($rows as $row) {
                $out[(string) $row->getAttribute('external_product_id')] = ['avg' => round((float) $row->getAttribute('avg_rating'), 1), 'count' => (int) $row->getAttribute('c')];
            }

            return $out;
        });

        return $this->json($request, $site, $data);
    }

    private function site(string $publicKey): ?Site
    {
        $resolved = $this->sites->byPublicKey($publicKey);

        return $resolved !== null && $resolved[0]->isActive() ? $resolved[0] : null;
    }

    /** @param  array<string, mixed>  $data */
    private function json(Request $request, Site $site, array $data): JsonResponse
    {
        $origin = (string) $request->header('Origin');
        $host = parse_url($origin, PHP_URL_HOST);
        $allowed = is_string($host) && in_array(strtolower($host), array_map('strtolower', $site->domains ?? []), true);

        return response()->json($data === [] ? new \stdClass : $data, 200, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ->withHeaders(($allowed ? ['Access-Control-Allow-Origin' => $origin] : []) + [
                'Cache-Control' => 'public, max-age='.self::CACHE_SECONDS,
                'Vary' => 'Origin',
            ]);
    }
}
