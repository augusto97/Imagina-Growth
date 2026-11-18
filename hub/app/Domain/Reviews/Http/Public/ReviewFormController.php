<?php

declare(strict_types=1);

namespace App\Domain\Reviews\Http\Public;

use App\Domain\Contacts\Models\Contact;
use App\Domain\Reviews\Actions\RewardReview;
use App\Domain\Reviews\Actions\SubmitReview;
use App\Domain\Reviews\Models\Review;
use App\Domain\Reviews\Models\ReviewRequest;
use App\Domain\Reviews\Support\ReviewMediaStore;
use App\Domain\Reviews\Support\ReviewSettings;
use App\Domain\Reviews\Support\ReviewToken;
use App\Domain\Templates\Support\BusinessProfile;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * /r/{token}: formulario de reseña de 1 toque, sin iniciar sesión (M15,
 * api-connector.md §8.3). El token va firmado por la solicitud, el pedido y el
 * contacto. Una reseña por producto; se puede reseñar la tienda. Sin sesión ni
 * CSRF (routes/public): lo protege el token.
 *
 * La página de gracias ofrece el enlace de Google Business Profile a todos, sin
 * importar la calificación (filtrar a los satisfechos va contra las políticas de
 * Google, M15).
 */
final class ReviewFormController
{
    public function __construct(private readonly CurrentWorkspace $current) {}

    /** GET /r/{token}?p=88&nota=5 */
    public function show(Request $request, string $token): Response
    {
        $ctx = $this->context($token);
        if ($ctx instanceof Response) {
            return $ctx;
        }

        return $this->current->run($ctx['workspace'], fn () => $this->form($ctx, $request, [], []));
    }

    /** POST /r/{token} (multipart) */
    public function store(Request $request, string $token, SubmitReview $submit): Response
    {
        $ctx = $this->context($token);
        if ($ctx instanceof Response) {
            return $ctx;
        }

        return $this->current->run($ctx['workspace'], function () use ($ctx, $request, $token, $submit): Response {
            $cfg = (array) config('igr-reviews.uploads');
            $products = $this->products($ctx['workspace'], $ctx['request']);
            $validator = Validator::make($request->all(), [
                'product' => ['nullable', 'string', 'max:64'],
                'rating' => ['required', 'integer', 'between:1,5'],
                'title' => ['nullable', 'string', 'max:120'],
                'body' => ['nullable', 'string', 'max:3000'],
                'author_name' => ['required', 'string', 'min:2', 'max:40'],
                'media' => ['nullable', 'array', 'max:'.(int) $cfg['max_files']],
                'media.*' => ['file', 'max:'.max((int) $cfg['image_max_kb'], (int) $cfg['video_max_kb'])],
            ], [], [
                'rating' => __('calificación'), 'title' => __('título'), 'body' => __('comentario'),
                'author_name' => __('nombre'), 'media' => __('fotos o video'),
            ]);
            $product = (string) $request->input('product', '');
            if ($product !== '' && ! array_key_exists($product, $products)) {
                $validator->after(fn ($v) => $v->errors()->add('product', __('Ese producto no está en tu pedido.')));
            }
            if ($validator->fails()) {
                return $this->form($ctx, $request, $validator->errors()->toArray(), $request->except('media'), 422);
            }
            $files = array_values(array_filter((array) $request->file('media', []), fn ($f) => $f instanceof UploadedFile));

            try {
                $review = $submit->handle(
                    $ctx['workspace'], $ctx['request'], $product === '' ? null : $product, (int) $request->input('rating'),
                    self::clean($request->input('title')), self::clean($request->input('body')),
                    (string) self::clean($request->input('author_name')), $files,
                );
            } catch (ValidationException $e) {
                return $this->form($ctx, $request, $e->errors(), $request->except('media'), 422);
            }

            return redirect()->to(ReviewToken::base().'/r/'.$token.'/gracias?rv='.$review->publicId(), 303)
                ->withHeaders(['Cache-Control' => 'no-store, private']);
        });
    }

    /** GET /r/{token}/gracias?rv=rv_… */
    public function thanks(Request $request, string $token, RewardReview $rewards): Response
    {
        $ctx = $this->context($token);
        if ($ctx instanceof Response) {
            return $ctx;
        }

        return $this->current->run($ctx['workspace'], function () use ($ctx, $request, $token, $rewards): Response {
            $settings = ReviewSettings::for($ctx['workspace']);
            $review = Review::findByPublicId(is_string($request->query('rv')) ? $request->query('rv') : null);
            if ($review !== null && $review->request_id !== $ctx['request']->id) {
                $review = null;
            }
            $products = $this->products($ctx['workspace'], $ctx['request']);
            $pending = array_filter($products, fn (array $p) => ! $p['reviewed']);
            $preview = $rewards->preview();
            $points = $review !== null && $review->source === 'request' ? $preview['review'] + ($review->hasMedia() ? $preview['media'] : 0) : 0;

            return $this->view('reviews.thanks', $ctx['workspace'], [
                'review' => $review,
                'points' => $points,
                'published' => $review?->status === 'published',
                'google' => $settings['google_review_url'],
                'pending' => $pending,
                'formUrl' => ReviewToken::base().'/r/'.$token,
            ]);
        });
    }

    /**
     * @param  array{workspace: Workspace, request: ReviewRequest, contact: Contact|null, token: string}  $ctx
     * @param  array<string, array<int, string>|string>  $errors
     * @param  array<string, mixed>  $old
     */
    private function form(array $ctx, Request $request, array $errors, array $old, int $status = 200): Response
    {
        $products = $this->products($ctx['workspace'], $ctx['request']);
        $selected = $old['product'] ?? $request->query('p');
        if (! is_string($selected) || ($selected !== '' && ! array_key_exists($selected, $products))) {
            $first = array_key_first(array_filter($products, fn (array $p) => ! $p['reviewed']));
            $selected = $first !== null ? (string) $first : '';
        }
        $rating = (int) ($old['rating'] ?? $request->query('nota', 0));
        $cfg = (array) config('igr-reviews.uploads');
        $rewards = app(RewardReview::class)->preview();

        return $this->view('reviews.form', $ctx['workspace'], [
            'action' => ReviewToken::base().'/r/'.$ctx['token'],
            'firstName' => $ctx['contact'] !== null ? explode(' ', trim((string) $ctx['contact']->first_name))[0] : '',
            'products' => $products,
            'selected' => $selected,
            'rating' => $rating >= 1 && $rating <= 5 ? $rating : 0,
            'old' => $old,
            'author' => is_string($old['author_name'] ?? null) ? $old['author_name'] : SubmitReview::defaultAuthor($ctx['contact']),
            'errors' => array_map(fn ($e) => is_array($e) ? (string) ($e[0] ?? '') : (string) $e, $errors),
            'photos' => ReviewMediaStore::supportsImages(),
            'maxFiles' => (int) $cfg['max_files'],
            'videoMb' => intdiv((int) $cfg['video_max_kb'], 1024),
            'rewards' => $rewards,
        ], $status);
    }

    /**
     * Productos del pedido que se piden reseñar (con nombre e imagen del catálogo),
     * más la reseña de la tienda con la clave ''.
     *
     * @return array<string, array{id: string, name: string, image: string|null, reviewed: bool}>
     */
    private function products(Workspace $workspace, ReviewRequest $request): array
    {
        $ids = array_values(array_map('strval', $request->products ?? []));
        $siteId = (string) $request->site_id;
        $catalog = DB::table('products')->where('workspace_id', $workspace->id)->where('site_id', $siteId)->whereIn('external_id', $ids)
            ->get(['external_id', 'name', 'image_url'])->keyBy('external_id');
        $items = DB::table('order_items')->where('workspace_id', $workspace->id)->where('order_id', $request->order_id)
            ->whereIn('external_product_id', $ids)->pluck('name', 'external_product_id');
        $done = Review::query()->where('request_id', $request->id)->get(['external_product_id'])
            ->map(fn (Review $r) => (string) $r->external_product_id)->flip();
        $out = [];
        foreach ($ids as $id) {
            $row = $catalog->get($id);
            $out[$id] = [
                'id' => $id,
                'name' => (string) ($row->name ?? $items[$id] ?? __('Producto')),
                'image' => is_string($row?->image_url) && $row->image_url !== '' ? $row->image_url : null,
                'reviewed' => $done->has($id),
            ];
        }
        $out[''] = ['id' => '', 'name' => __('La tienda en general'), 'image' => null, 'reviewed' => $done->has('')];

        return $out;
    }

    /**
     * @return array{workspace: Workspace, request: ReviewRequest, contact: Contact|null, token: string}|Response
     */
    private function context(string $token): array|Response
    {
        $parsed = ReviewToken::parse(ReviewToken::REVIEW, $token);
        if ($parsed['status'] !== 'ok') {
            return $this->invalid($parsed['status'] === 'expired' ? 410 : 404);
        }
        $claims = $parsed['claims'];
        $workspace = Workspace::query()->find((string) $claims['w']);
        if ($workspace === null) {
            return $this->invalid(404);
        }
        $request = $this->current->run($workspace, fn () => ReviewRequest::query()->whereKey((string) $claims['r'])
            ->where('order_id', (string) ($claims['o'] ?? ''))->where('contact_id', (string) ($claims['c'] ?? ''))->first());
        if ($request === null || in_array($request->status, ['expired'], true)) {
            return $this->invalid(404, $workspace);
        }
        $contact = $this->current->run($workspace, fn () => Contact::query()->find($request->contact_id));

        return ['workspace' => $workspace, 'request' => $request, 'contact' => $contact, 'token' => $token];
    }

    private function invalid(int $status, ?Workspace $workspace = null): Response
    {
        return $this->view('reviews.invalid', $workspace, ['expired' => $status === 410], $status);
    }

    /** @param  array<string, mixed>  $data */
    private function view(string $view, ?Workspace $workspace, array $data, int $status = 200): Response
    {
        $profile = $workspace !== null ? BusinessProfile::for($workspace) : null;

        return response()->view($view, $data + [
            'brand' => $profile !== null ? ($profile['name'] !== '' ? $profile['name'] : (string) $workspace?->name) : 'Imagina Growth',
            'logo' => $profile !== null && $profile['logo_url'] !== '' ? $profile['logo_url'] : null,
        ], $status)->withHeaders([
            'Cache-Control' => 'no-store, private',
            'X-Robots-Tag' => 'noindex',
            'Referrer-Policy' => 'no-referrer',
            'X-Frame-Options' => 'DENY',
        ]);
    }

    private static function clean(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '');

        return $value === '' ? null : $value;
    }
}
