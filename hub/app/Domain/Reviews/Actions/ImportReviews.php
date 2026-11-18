<?php

declare(strict_types=1);

namespace App\Domain\Reviews\Actions;

use App\Domain\Contacts\Models\Contact;
use App\Domain\Reviews\Contracts\ReviewClassifier;
use App\Domain\Reviews\Models\Review;
use App\Domain\Reviews\Support\PublicReviewCache;
use App\Domain\Sites\Models\Site;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Pii\BlindIndex;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use SplFileObject;
use Throwable;

/**
 * Importa reseñas de un CSV propio o de una exportación de Judge.me (M15).
 *
 * - Columnas reconocidas (encabezados en español o inglés): producto
 *   (`product_id`, `sku` o `product_handle`), `rating`, `title`, `body`,
 *   `reviewer_name`, `reviewer_email`, `review_date`, `picture_urls`, `reply` y,
 *   de Judge.me, `curated` (ok → publicada, spam → rechazada, otro → pendiente).
 * - El correo solo sirve para unir la reseña con el contacto (índice ciego) y
 *   marcarla de comprador verificado si compró ese producto; no se guarda.
 * - No duplica: `import_key` es una huella del sitio, el producto, el autor, la
 *   fecha y el texto. Volver a importar el mismo archivo no crea nada.
 * - No pasa por WooCommerce ni da puntos: son reseñas del pasado.
 */
final class ImportReviews
{
    public const MAX_ROWS = 5000;

    private const ALIASES = [
        'product_id' => ['product_id', 'producto', 'producto_id', 'id_producto'],
        'sku' => ['sku', 'product_sku', 'referencia'],
        'handle' => ['product_handle', 'handle', 'slug'],
        'rating' => ['rating', 'calificacion', 'calificación', 'estrellas', 'stars'],
        'title' => ['title', 'titulo', 'título'],
        'body' => ['body', 'comentario', 'review', 'texto', 'content'],
        'author' => ['reviewer_name', 'author', 'autor', 'nombre', 'name'],
        'email' => ['reviewer_email', 'email', 'correo'],
        'date' => ['review_date', 'date', 'fecha', 'created_at'],
        'photos' => ['picture_urls', 'photos', 'fotos', 'images', 'imagenes', 'imágenes'],
        'reply' => ['reply', 'respuesta'],
        'curated' => ['curated', 'estado', 'status'],
        'verified' => ['verified', 'verificada', 'verified_buyer'],
    ];

    public function __construct(private readonly ReviewClassifier $classifier) {}

    /**
     * @return array{rows: int, created: int, duplicates: int, errors: list<array{row: int, message: string}>}
     */
    public function handle(Workspace $workspace, Site $site, string $path, string $format): array
    {
        $file = new SplFileObject($path);
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::READ_AHEAD | SplFileObject::DROP_NEW_LINE);
        $first = strtok((string) file_get_contents($path, false, null, 0, 4096), "\n") ?: '';
        $file->setCsvControl(substr_count($first, ';') > substr_count($first, ',') ? ';' : ',');

        $map = null;
        $out = ['rows' => 0, 'created' => 0, 'duplicates' => 0, 'errors' => []];
        foreach ($file as $i => $row) {
            if (! is_array($row) || $row === [null]) {
                continue;
            }
            if ($map === null) {
                $map = $this->header($row);
                if (! isset($map['rating'])) {
                    $out['errors'][] = ['row' => 1, 'message' => __('Falta la columna de la calificación (rating).')];

                    return $out;
                }

                continue;
            }
            if (++$out['rows'] > self::MAX_ROWS) {
                $out['errors'][] = ['row' => (int) $i + 1, 'message' => __('Máximo :n filas por archivo.', ['n' => self::MAX_ROWS])];
                break;
            }
            try {
                $result = $this->row($workspace, $site, $map, $row, $format);
                if ($result === 'created') {
                    $out['created']++;
                } elseif ($result === 'duplicate') {
                    $out['duplicates']++;
                } else {
                    $out['errors'][] = ['row' => (int) $i + 1, 'message' => $result];
                }
            } catch (Throwable $e) {
                report($e);
                $out['errors'][] = ['row' => (int) $i + 1, 'message' => __('No se pudo importar esta fila.')];
            }
        }
        if ($out['created'] > 0) {
            PublicReviewCache::bump($site->id);
        }
        $out['errors'] = array_slice($out['errors'], 0, 100);

        return $out;
    }

    /**
     * @param  array<int, string|null>  $row
     * @return array<string, int>
     */
    private function header(array $row): array
    {
        $map = [];
        foreach ($row as $index => $name) {
            $key = Str::lower(trim((string) preg_replace('/^\xEF\xBB\xBF/', '', (string) $name)));
            foreach (self::ALIASES as $field => $aliases) {
                if (! isset($map[$field]) && in_array($key, $aliases, true)) {
                    $map[$field] = (int) $index;
                }
            }
        }

        return $map;
    }

    /**
     * @param  array<string, int>  $map
     * @param  array<int, string|null>  $row
     * @return 'created'|'duplicate'|string mensaje de error
     */
    private function row(Workspace $workspace, Site $site, array $map, array $row, string $format): string
    {
        $get = fn (string $field): string => isset($map[$field]) ? trim((string) ($row[$map[$field]] ?? '')) : '';
        $rating = (int) round((float) str_replace(',', '.', $get('rating')));
        if ($rating < 1 || $rating > 5) {
            return __('Calificación inválida (debe ser de 1 a 5).');
        }
        $product = $this->product($site, $get('product_id'), $get('sku'), $get('handle'));
        if ($product === false) {
            return __('No encontramos el producto en el catálogo del sitio.');
        }
        $body = mb_substr($get('body'), 0, 5000);
        $title = mb_substr($get('title'), 0, 200);
        $author = mb_substr($get('author'), 0, 120);
        $date = $this->date($get('date')) ?? CarbonImmutable::now();
        $key = hash('sha256', implode('|', [$site->id, $product['external'] ?? '', Str::lower($author), $date->toDateString(), $rating, md5($body)]));
        if (Review::query()->where('import_key', $key)->exists()) {
            return 'duplicate';
        }

        $contactId = null;
        $email = $get('email');
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
            $contactId = Contact::query()->where('email_bidx', BlindIndex::email($workspace->id, $email))->value('id');
        }
        $verified = in_array(Str::lower($get('verified')), ['1', 'true', 'yes', 'si', 'sí'], true);
        if (! $verified && is_string($contactId) && ($product['external'] ?? null) !== null) {
            $verified = DB::table('orders')->join('order_items', 'order_items.order_id', '=', 'orders.id')
                ->where('orders.workspace_id', $workspace->id)->where('orders.contact_id', $contactId)
                ->where('order_items.external_product_id', $product['external'])->exists();
        }
        $status = $format === 'judgeme'
            ? match (Str::lower($get('curated'))) {
                'ok', 'published', 'publicada' => 'published', 'spam' => 'rejected', default => 'pending'
            }
        : match (Str::lower($get('curated'))) {
            'pending', 'pendiente' => 'pending', 'rejected', 'rechazada', 'spam' => 'rejected', default => 'published'
        };
        $photos = [];
        foreach (preg_split('/[\s,]+/', $get('photos')) ?: [] as $url) {
            if (preg_match('#^https://[^\s]+$#i', $url) === 1 && count($photos) < 5) {
                $photos[] = ['url' => $url, 'type' => 'image'];
            }
        }
        $reply = mb_substr($get('reply'), 0, 3000);

        try {
            $review = new Review;
            $review->forceFill([
                'workspace_id' => $workspace->id,
                'site_id' => $site->id,
                'product_id' => $product['internal'] ?? null,
                'external_product_id' => $product['external'] ?? null,
                'contact_id' => $contactId,
                'rating' => $rating,
                'title' => $title !== '' ? $title : null,
                'body' => $body !== '' ? $body : null,
                'author_name' => $author !== '' ? self::shortName($author) : null,
                'media' => $photos === [] ? null : $photos,
                'status' => $status,
                'reject_reason' => $status === 'rejected' ? 'spam' : null,
                'published_at' => $status === 'published' ? $date : null,
                'verified_buyer' => $verified,
                'source' => 'import',
                'owner_reply' => $reply !== '' ? $reply : null,
                'replied_at' => $reply !== '' ? $date : null,
                'ai_flags' => $this->classifier->classify($title, $body, $author),
                'sync_status' => 'skipped',
                'import_key' => $key,
                'created_at' => $date,
            ])->save();
        } catch (UniqueConstraintViolationException) {
            return 'duplicate';
        }

        return 'created';
    }

    /** @return array{internal: string|null, external: string|null}|false null = reseña de la tienda */
    private function product(Site $site, string $externalId, string $sku, string $handle): array|false
    {
        if ($externalId === '' && $sku === '' && $handle === '') {
            return ['internal' => null, 'external' => null];
        }
        $q = DB::table('products')->where('site_id', $site->id)->whereNull('deleted_at');
        $row = null;
        // La columna de producto acepta el ID de la tienda, el SKU o el slug.
        foreach ([['external_id', $externalId], ['sku', $sku !== '' ? $sku : $externalId], ['slug', $handle !== '' ? $handle : $externalId]] as [$column, $value]) {
            if ($row === null && $value !== '') {
                $row = (clone $q)->where($column, $value)->first(['id', 'external_id']);
            }
        }

        return $row === null ? false : ['internal' => (string) $row->id, 'external' => (string) $row->external_id];
    }

    private function date(string $value): ?CarbonImmutable
    {
        if ($value === '') {
            return null;
        }
        try {
            $date = CarbonImmutable::parse($value)->utc();

            return $date->isFuture() ? null : $date;
        } catch (Throwable) {
            return null;
        }
    }

    /** «Ana María Rodríguez» → «Ana R.» (lo que se publica). */
    public static function shortName(string $name): string
    {
        $parts = preg_split('/\s+/u', trim($name)) ?: [];
        $first = mb_convert_case(mb_strtolower($parts[0] ?? ''), MB_CASE_TITLE);
        if (count($parts) < 2) {
            return $first;
        }
        $last = (string) end($parts);

        return $first.' '.mb_strtoupper(mb_substr(rtrim($last, '.'), 0, 1)).'.';
    }
}
