<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Ai\Features\Summaries;

use App\Domain\Intelligence\Ai\Data\AiRequest;
use App\Domain\Intelligence\Ai\Support\NarrativeWriter;
use App\Domain\Intelligence\Ai\Support\PiiScrubber;
use App\Domain\Reviews\Models\Review;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Tenancy\CurrentWorkspace;
use App\Support\Tenancy\RedisKey;
use Illuminate\Support\Facades\Cache;

/**
 * Resumen de las reseñas de un producto con IA (inteligencia.md §6.6): lo que más
 * gusta y lo que más se critica, solo de las reseñas publicadas y sin nombres de
 * autores. Se guarda en caché hasta que cambien las reseñas del producto.
 */
final class ReviewSummary
{
    public const MIN_REVIEWS = 3;

    public function __construct(private readonly NarrativeWriter $writer, private readonly CurrentWorkspace $current) {}

    /** @return array{available: bool, summary: string|null, positives: list<string>, negatives: list<string>, source: string|null, count: int, average: float|null} */
    public function forProduct(Workspace $workspace, string $externalProductId, ?string $siteId = null): array
    {
        return $this->current->run($workspace, function () use ($workspace, $externalProductId, $siteId): array {
            $query = Review::query()->where('status', 'published')->where('external_product_id', $externalProductId)
                ->when($siteId !== null, fn ($q) => $q->where('site_id', $siteId));
            $count = (clone $query)->count();
            $average = $count > 0 ? round((float) (clone $query)->avg('rating'), 1) : null;
            if ($count < self::MIN_REVIEWS) {
                return ['available' => false, 'summary' => null, 'positives' => [], 'negatives' => [], 'source' => null, 'count' => $count, 'average' => $average];
            }
            $version = md5($count.'|'.(clone $query)->max('updated_at'));
            $key = RedisKey::for($workspace->id, 'ai:reviews:'.md5(($siteId ?? '').'|'.$externalProductId).':'.$version);

            return Cache::remember($key, now()->addDays(30), function () use ($workspace, $query, $count, $average): array {
                $reviews = (clone $query)->latest('published_at')->limit(60)->get(['rating', 'title', 'body'])
                    ->map(fn (Review $r) => array_filter([
                        'estrellas' => (int) $r->rating,
                        'titulo' => $r->title !== null ? PiiScrubber::scrub(mb_substr((string) $r->title, 0, 120)) : null,
                        'texto' => PiiScrubber::scrub(mb_substr((string) $r->body, 0, 400)),
                    ]))->all();
                $distribution = [];
                foreach ((clone $query)->selectRaw('rating, count(*) as c')->groupBy('rating')->pluck('c', 'rating') as $rating => $c) {
                    $distribution[(string) $rating] = (int) $c;
                }
                $facts = ['total_resenas' => $count, 'promedio' => $average, 'distribucion' => $distribution, 'resenas' => $reviews];
                $result = $this->writer->write(
                    new AiRequest($workspace->id, 'review_summary', self::system(), [['role' => 'user', 'content' => "Reseñas publicadas (JSON):\n".json_encode($facts, JSON_UNESCAPED_UNICODE)]], self::schema()),
                    $facts,
                    fn () => __(':count reseñas con un promedio de :avg estrellas.', ['count' => $count, 'avg' => number_format((float) $average, 1, ',', '.')]),
                );
                $json = $result->json ?? [];

                return [
                    'available' => true,
                    'summary' => $result->fromAi() ? (string) ($json['resumen'] ?? '') : $result->text,
                    'positives' => $result->fromAi() ? array_values(array_map('strval', (array) ($json['lo_bueno'] ?? []))) : [],
                    'negatives' => $result->fromAi() ? array_values(array_map('strval', (array) ($json['lo_malo'] ?? []))) : [],
                    'source' => $result->source,
                    'count' => $count,
                    'average' => $average,
                ];
            });
        });
    }

    public static function system(): string
    {
        return 'Resumes las reseñas de un producto de una tienda en línea para su dueño, en español neutro. '
            .'«resumen»: 1 o 2 frases con la opinión general. «lo_bueno» y «lo_malo»: hasta 3 puntos cortos cada uno, solo lo que se repite en varias reseñas. '
            .'No cites nombres ni datos personales. Usa solo cifras de los datos (total, promedio o las de las reseñas).';
    }

    /** @return array<string, mixed> */
    public static function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'resumen' => ['type' => 'string'],
                'lo_bueno' => ['type' => 'array', 'items' => ['type' => 'string']],
                'lo_malo' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
            'required' => ['resumen', 'lo_bueno', 'lo_malo'],
            'additionalProperties' => false,
        ];
    }
}
