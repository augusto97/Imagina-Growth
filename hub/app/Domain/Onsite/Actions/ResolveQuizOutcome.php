<?php

declare(strict_types=1);

namespace App\Domain\Onsite\Actions;

use App\Domain\Commerce\Contracts\RecommendationService;
use App\Domain\Commerce\Data\RecommendationContext;
use App\Domain\Commerce\Models\Product;
use App\Domain\Onsite\Models\Quiz;
use App\Domain\Sites\Models\Site;
use Throwable;

/**
 * Resultado de un quiz (M12): valida las respuestas contra las preguntas, arma los
 * atributos del perfil (`attribute` de la pregunta y `attributes` de la opción) y
 * elige los productos con la primera regla que coincide (por IDs, categorías,
 * marcas y precio máximo) o, si no, con el respaldo (RecommendationService, si M16
 * lo registró, o los más vendidos del catálogo). Debe correr dentro del contexto del
 * espacio de trabajo.
 */
final class ResolveQuizOutcome
{
    /**
     * Respuestas válidas: `{pregunta: opción}` solo con preguntas y opciones que existen.
     *
     * @param  array<mixed>  $answers
     * @return array<string, string>|null null si hay una respuesta que no existe
     */
    public function answers(Quiz $quiz, array $answers): ?array
    {
        $valid = [];
        foreach ($answers as $qid => $key) {
            $question = collect($quiz->questions)->firstWhere('id', (string) $qid);
            if (! is_array($question) || ! is_string($key) || ! in_array($key, array_column((array) ($question['options'] ?? []), 'key'), true)) {
                return null;
            }
            $valid[(string) $qid] = $key;
        }

        return $valid;
    }

    /**
     * Atributos del perfil que dejan las respuestas.
     *
     * @param  array<string, string>  $answers
     * @return array<string, string>
     */
    public function attributes(Quiz $quiz, array $answers): array
    {
        $out = [];
        foreach ($quiz->questions as $question) {
            $key = $answers[(string) $question['id']] ?? null;
            if ($key === null) {
                continue;
            }
            if (is_string($question['attribute'] ?? null)) {
                $out[$question['attribute']] = $key;
            }
            foreach ((array) ($question['options'] ?? []) as $option) {
                if (($option['key'] ?? null) === $key) {
                    foreach ((array) ($option['attributes'] ?? []) as $k => $v) {
                        $out[(string) $k] = (string) $v;
                    }
                }
            }
        }

        return $out;
    }

    /**
     * @param  array<string, string>  $answers
     * @return array{rule: string|null, message: string, products: list<array<string, mixed>>}
     */
    public function outcome(Quiz $quiz, Site $site, array $answers, ?string $contactId, ?string $anonymousId): array
    {
        $outcomes = $quiz->outcomes;
        $limit = max(1, min(12, (int) ($outcomes['limit'] ?? 4)));
        foreach ((array) ($outcomes['rules'] ?? []) as $rule) {
            if (! is_array($rule) || ! $this->matches((array) ($rule['match'] ?? []), $answers)) {
                continue;
            }
            $products = $this->products($site, (array) ($rule['products'] ?? []), $limit, $contactId, $anonymousId);

            return ['rule' => (string) ($rule['id'] ?? ''), 'message' => (string) ($rule['message'] ?? ''), 'products' => $products];
        }
        $fallback = (array) ($outcomes['fallback'] ?? []);

        return [
            'rule' => null,
            'message' => (string) ($fallback['message'] ?? ''),
            'products' => $this->products($site, (array) ($fallback['products'] ?? ['kind' => 'trending']), $limit, $contactId, $anonymousId),
        ];
    }

    /**
     * @param  array<mixed>  $match
     * @param  array<string, string>  $answers
     */
    private function matches(array $match, array $answers): bool
    {
        foreach ($match as $qid => $keys) {
            $keys = (array) $keys;
            if ($keys !== [] && ! in_array($answers[(string) $qid] ?? null, $keys, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<mixed>  $rule
     * @return list<array<string, mixed>>
     */
    public function products(Site $site, array $rule, int $limit, ?string $contactId = null, ?string $anonymousId = null): array
    {
        $ids = array_values(array_filter((array) ($rule['product_ids'] ?? []), 'is_string'));
        $categories = array_values(array_filter((array) ($rule['category_ids'] ?? []), 'is_string'));
        $brands = array_values(array_filter((array) ($rule['brands'] ?? []), 'is_string'));
        $max = $rule['max_price'] ?? null;
        $kind = is_string($rule['kind'] ?? null) ? $rule['kind'] : null;

        if ($ids === [] && $categories === [] && $brands === [] && $max === null && $kind !== null && app()->bound(RecommendationService::class)) {
            try {
                $context = new RecommendationContext($site->id, $contactId, $anonymousId);

                return array_values(array_map(fn (array $p) => $p, app(RecommendationService::class)->recommend($kind, $context, $limit)));
            } catch (Throwable $e) {
                report($e);
            }
        }

        $query = Product::query()->where('site_id', $site->id)->where('status', 'publish')->whereNull('deleted_at')
            ->where(fn ($q) => $q->whereNull('stock_status')->orWhere('stock_status', '!=', 'outofstock'))
            ->whereNull('parent_external_id');
        if ($ids !== []) {
            $query->whereIn('external_id', $ids);
        }
        if ($categories !== []) {
            $query->whereRaw("JSON_OVERLAPS(COALESCE(category_ids, '[]'), ?)", [json_encode($categories)]);
        }
        if ($brands !== []) {
            $query->whereIn('brand', $brands);
        }
        if (is_numeric($max)) {
            $query->where('price', '<=', $max);
        }
        $rows = $query->orderByDesc('purchases_30d')->orderByDesc('views_30d')->orderBy('name')->limit($limit)->get();
        if ($ids !== []) {
            $rows = $rows->sortBy(fn (Product $p) => array_search($p->external_id, $ids, true))->values();
        }

        return array_values($rows->map(fn (Product $p) => self::card($p, $site))->all());
    }

    /** @return array{id: string, name: string, price: string, regular_price: string|null, sale_price: string|null, currency: string, image: string|null, url: string|null, rating_avg: float|null, rating_count: int} */
    public static function card(Product $p, Site $site): array
    {
        $money = fn (mixed $v) => $v === null ? null : number_format((float) $v, 2, '.', '');

        return [
            'id' => $p->external_id,
            'name' => (string) $p->getAttribute('name'),
            'price' => (string) $money($p->getAttribute('price')),
            'regular_price' => $money($p->getAttribute('regular_price')),
            'sale_price' => $money($p->getAttribute('sale_price')),
            'currency' => (string) ($p->getAttribute('currency') ?: $site->getAttribute('currency') ?: 'COP'),
            'image' => $p->getAttribute('image_url') ?: null,
            'url' => $p->getAttribute('permalink') ?: null,
            'rating_avg' => $p->getAttribute('rating_avg') !== null ? round((float) $p->getAttribute('rating_avg'), 2) : null,
            'rating_count' => (int) ($p->getAttribute('rating_count') ?? 0),
        ];
    }
}
