<?php

declare(strict_types=1);

namespace App\Domain\Capture\Http;

use App\Domain\Capture\Actions\SyncPageWhatsAppLink;
use App\Domain\Capture\Models\LandingPage;
use App\Domain\Capture\Models\LandingSubmission;
use App\Domain\Capture\Support\CaptureCounters;
use App\Domain\Capture\Support\CaptureUrls;
use App\Domain\Capture\Support\LandingImages;
use App\Domain\Capture\Support\LandingSchema;
use App\Domain\Capture\Support\QrImage;
use App\Domain\Capture\Support\WhatsAppNumber;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Messaging\Enums\Channel;
use App\Domain\Messaging\Models\ChannelAccount;
use App\Domain\Onsite\Support\PublicWidgetConfig;
use App\Domain\Sites\Models\Site;
use App\Domain\Workspaces\Actions\RecordAuditLog;
use App\Domain\Workspaces\Models\User;
use App\Support\Pii\Pii;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

/**
 * API de las páginas de captura (modulos.md M12 «Páginas de captura y QR») para la SPA, dentro de `/api/w/{ws}`.
 * Ver: `viewDashboards`; crear, editar, publicar, despublicar, subir imágenes y archivar: `manageMarketing` (queda en
 * la auditoría). Crear y publicar exigen el módulo `onsite` del plan. Los envíos con los datos de la persona solo se
 * ven completos con `viewAllContacts`; si no, enmascarados.
 */
final class LandingPageController
{
    public function __construct(private readonly CurrentWorkspace $current, private readonly RecordAuditLog $audit) {}

    /** GET capture/options: lo que necesita el editor. */
    public function options(PublicWidgetConfig $widgets): JsonResponse
    {
        Gate::authorize('ws.viewDashboards');
        $ws = $this->current->get();
        $accounts = ChannelAccount::query()->where('channel', Channel::WhatsApp->value)->where('status', 'active')->orderByDesc('is_default')->get()
            ->map(fn (ChannelAccount $a) => ['id' => $a->publicId(), 'name' => $a->display_name, 'number' => $a->identity, 'is_default' => $a->is_default])->values();
        $sites = Site::query()->orderBy('created_at')->get()
            ->map(fn (Site $s) => ['id' => $s->publicId(), 'name' => (string) ($s->getAttribute('name') ?: $s->getAttribute('host')), 'status' => $s->getAttribute('status')])->values();

        return response()->json([
            'base_url' => CaptureUrls::page($ws, new LandingPage(['slug' => ''])),
            'fields' => array_map(fn (string $key, string $type) => ['key' => $key, 'type' => $type], array_keys(LandingSchema::BUILTIN), LandingSchema::BUILTIN),
            'custom_types' => LandingSchema::CUSTOM_TYPES,
            'max_fields' => (int) config('igr-capture.max_fields', 12),
            'consent_texts' => $widgets->legalConsents($ws),
            'whatsapp_accounts' => $accounts,
            'whatsapp_number' => ($digits = WhatsAppNumber::digits($ws, WhatsAppNumber::account(null))) !== null ? '+'.$digits : null,
            'sites' => $sites,
            'privacy_policy_url' => $ws->getAttribute('privacy_policy_url'),
            'images' => ['max_kb' => (int) config('igr-capture.images.max_kb'), 'supported' => LandingImages::supported()],
        ]);
    }

    /** GET capture/pages?status=&days=30 */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('ws.viewDashboards');
        $ws = $this->current->get();
        $status = $request->query('status');
        $pages = LandingPage::query()
            ->when(is_string($status) && in_array($status, LandingPage::STATUSES, true), fn ($q) => $q->where('status', $status), fn ($q) => $q->where('status', '!=', 'archived'))
            ->orderByRaw("FIELD(status, 'published', 'draft', 'archived')")->orderByDesc('updated_at')->limit(200)->get();
        $from = $this->from($request);
        $summary = CaptureCounters::summary($ws->id, CaptureCounters::PAGE, array_values($pages->pluck('id')->map(fn ($id) => (string) $id)->all()), $from);

        return response()->json([
            'data' => $pages->map(fn (LandingPage $p) => CapturePresenter::page($ws, $p, false, $summary[$p->id] ?? ['views' => 0, 'submissions' => 0, 'qr_views' => 0]))->values(),
            'from' => $from,
        ]);
    }

    /** POST capture/pages {name, title?, slug?} */
    public function store(Request $request, SyncPageWhatsAppLink $whatsapp): JsonResponse
    {
        Gate::authorize('ws.manageMarketing');
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:160'],
            'title' => ['sometimes', 'nullable', 'string', 'max:120'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:80'],
        ]);
        $ws = $this->current->get();
        $slug = isset($data['slug']) && is_string($data['slug']) && $data['slug'] !== '' ? strtolower(trim($data['slug'])) : LandingSchema::uniqueSlug((string) $data['name']);
        if (($problem = LandingSchema::slugProblem($slug)) !== null) {
            throw ValidationException::withMessages(['slug' => $problem]);
        }
        $defaults = LandingSchema::defaults($ws, WhatsAppNumber::account(null) !== null);
        if (isset($data['title']) && is_string($data['title']) && trim($data['title']) !== '') {
            $defaults['content']['title'] = trim($data['title']);
        }
        /** @var User $user */
        $user = $request->user();
        $page = LandingPage::query()->create([
            'workspace_id' => $ws->id,
            'slug' => $slug,
            'name' => trim((string) $data['name']),
            'content' => $defaults['content'],
            'fields' => $defaults['fields'],
            'consent_channels' => $defaults['consent_channels'],
            'settings' => $defaults['settings'],
            'created_by' => $user->id,
        ]);
        $whatsapp->handle($page, $user->id);
        $this->audit->handle('landing_page.created', $page, ['name' => $page->name, 'slug' => $page->slug], $ws->id);

        return response()->json(CapturePresenter::page($ws, $page->refresh(), true), 201);
    }

    /** GET capture/pages/{page} */
    public function show(string $page): JsonResponse
    {
        Gate::authorize('ws.viewDashboards');

        return response()->json(CapturePresenter::page($this->current->get(), $this->find($page), true));
    }

    /** PATCH capture/pages/{page}: name, slug, content, fields, consent_channels, settings, site_id */
    public function update(Request $request, string $page, SyncPageWhatsAppLink $whatsapp): JsonResponse
    {
        Gate::authorize('ws.manageMarketing');
        $ws = $this->current->get();
        $model = $this->find($page);
        if ($model->status === 'archived') {
            abort(409, __('La página está archivada.'));
        }
        $attributes = LandingSchema::validate($ws, $model, $request->except(['site_id', 'status']));
        if ($request->exists('site_id')) {
            $site = is_string($request->input('site_id')) && $request->input('site_id') !== '' ? Site::findByPublicId((string) $request->input('site_id')) : null;
            if ($request->input('site_id') !== null && $request->input('site_id') !== '' && $site === null) {
                throw ValidationException::withMessages(['site_id' => __('El sitio no existe en este espacio de trabajo.')]);
            }
            $attributes['site_id'] = $site?->id;
        }
        $model->forceFill($attributes)->save();
        /** @var User $user */
        $user = $request->user();
        $whatsapp->handle($model, $user->id);
        $this->audit->handle('landing_page.updated', $model, ['fields' => array_keys($attributes)], $ws->id);

        return response()->json(CapturePresenter::page($ws, $model->refresh(), true));
    }

    /** POST capture/pages/{page}/publish */
    public function publish(string $page): JsonResponse
    {
        Gate::authorize('ws.manageMarketing');
        $ws = $this->current->get();
        $model = $this->find($page);
        if ($model->status === 'archived') {
            abort(409, __('La página está archivada.'));
        }
        $privacy = $ws->getAttribute('privacy_policy_url');
        if (strtoupper((string) $ws->country) === 'MX' && (! is_string($privacy) || $privacy === '')) {
            throw ValidationException::withMessages(['privacy_policy_url' => __('En México el aviso de privacidad es obligatorio junto a cada formulario. Agrega su enlace en Consentimiento y vuelve a publicar.')]);
        }
        if ($model->text('title') === '') {
            throw ValidationException::withMessages(['content.title' => __('Escribe el título de la página.')]);
        }
        $model->forceFill(['status' => 'published', 'published_at' => $model->published_at ?? now()])->save();
        $this->audit->handle('landing_page.published', $model, ['slug' => $model->slug], $ws->id);

        return response()->json(CapturePresenter::page($ws, $model, true));
    }

    /** POST capture/pages/{page}/unpublish */
    public function unpublish(string $page): JsonResponse
    {
        Gate::authorize('ws.manageMarketing');
        $ws = $this->current->get();
        $model = $this->find($page);
        if ($model->status === 'published') {
            $model->forceFill(['status' => 'draft'])->save();
            $this->audit->handle('landing_page.unpublished', $model, ['slug' => $model->slug], $ws->id);
        }

        return response()->json(CapturePresenter::page($ws, $model, true));
    }

    /** DELETE capture/pages/{page}: con envíos se archiva (las cifras y la atribución se conservan); sin envíos se borra. */
    public function destroy(string $page, LandingImages $images, SyncPageWhatsAppLink $whatsapp): JsonResponse
    {
        Gate::authorize('ws.manageMarketing');
        $ws = $this->current->get();
        $model = $this->find($page);
        if (LandingSubmission::query()->where('page_id', $model->id)->exists()) {
            $model->forceFill(['status' => 'archived'])->save();
            $whatsapp->handle($model);
            $this->audit->handle('landing_page.archived', $model, ['slug' => $model->slug], $ws->id);

            return response()->json(['archived' => true]);
        }
        $images->delete($model, $model->text('logo_key') ?: null);
        $images->delete($model, $model->text('image_key') ?: null);
        $model->forceFill(['status' => 'archived'])->save();
        $whatsapp->handle($model);
        $this->audit->handle('landing_page.deleted', $model, ['slug' => $model->slug], $ws->id);
        $model->delete();

        return response()->json(['deleted' => true]);
    }

    /** POST capture/pages/{page}/images {kind: logo|image, file} */
    public function uploadImage(Request $request, string $page, LandingImages $images): JsonResponse
    {
        Gate::authorize('ws.manageMarketing');
        $ws = $this->current->get();
        $model = $this->find($page);
        $data = $request->validate([
            'kind' => ['required', Rule::in(LandingImages::KINDS)],
            'file' => ['required', 'file', 'max:'.(int) config('igr-capture.images.max_kb', 5120)],
        ]);
        $column = $data['kind'] === 'logo' ? 'logo_key' : 'image_key';
        try {
            $name = $images->store($model, $request->file('file'), (string) $data['kind']);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }
        $images->delete($model, $model->text($column) ?: null);
        $content = (array) ($model->content ?? []);
        $content[$column] = $name;
        $model->forceFill(['content' => $content])->save();
        $this->audit->handle('landing_page.image_uploaded', $model, ['kind' => $data['kind']], $ws->id);

        return response()->json(CapturePresenter::page($ws, $model, true));
    }

    /** DELETE capture/pages/{page}/images/{kind} */
    public function deleteImage(string $page, string $kind, LandingImages $images): JsonResponse
    {
        Gate::authorize('ws.manageMarketing');
        abort_unless(in_array($kind, LandingImages::KINDS, true), 404);
        $ws = $this->current->get();
        $model = $this->find($page);
        $column = $kind === 'logo' ? 'logo_key' : 'image_key';
        $images->delete($model, $model->text($column) ?: null);
        $content = (array) ($model->content ?? []);
        $content[$column] = null;
        $model->forceFill(['content' => $content])->save();

        return response()->json(CapturePresenter::page($ws, $model, true));
    }

    /** GET capture/pages/{page}/stats?days=30: visitas, visitantes, envíos, leads y cuántos vinieron del QR. */
    public function stats(Request $request, string $page): JsonResponse
    {
        Gate::authorize('ws.viewDashboards');
        $ws = $this->current->get();
        $model = $this->find($page);
        $from = $this->from($request);
        $stats = CaptureCounters::read($ws->id, CaptureCounters::PAGE, $model->id, $from);
        $submissions = LandingSubmission::query()->where('page_id', $model->id)->where('created_at', '>=', $from);
        $leads = (clone $submissions)->whereNotNull('lead_id')->count();
        $qrLeads = (clone $submissions)->whereNotNull('lead_id')->where('via', 'qr')->count();
        $new = (clone $submissions)->where('was_known', false)->count();
        $consented = (clone $submissions)->whereNotNull('consents')->where('consents', '!=', '[]')->count();
        $totals = $stats['totals'];

        return response()->json([
            'from' => $from,
            'totals' => $totals + [
                'leads' => $leads,
                'qr_leads' => $qrLeads,
                'new_contacts' => $new,
                'with_consent' => $consented,
                'conversion_pct' => $totals['visitors'] > 0 ? round(100 * $totals['submissions'] / $totals['visitors'], 1) : null,
            ],
            'daily' => $stats['daily'],
        ]);
    }

    /** GET capture/pages/{page}/submissions: los últimos 50 envíos. */
    public function submissions(Request $request, string $page): JsonResponse
    {
        Gate::authorize('ws.viewDashboards');
        $model = $this->find($page);
        $full = Gate::allows('ws.viewAllContacts');
        $rows = LandingSubmission::query()->where('page_id', $model->id)->orderByDesc('created_at')->limit(50)->get();
        $contacts = Contact::query()->whereIn('id', $rows->pluck('contact_id')->filter()->all())->get()->keyBy('id');

        return response()->json(['data' => $rows->map(function (LandingSubmission $s) use ($contacts, $full): array {
            $contact = $s->contact_id !== null ? $contacts->get($s->contact_id) : null;
            $data = (array) ($s->data ?? []);

            return [
                'id' => $s->publicId(),
                'contact' => $contact !== null ? [
                    'id' => $contact->publicId(),
                    'name' => trim(($contact->first_name ?? '').' '.($contact->last_name ?? '')) ?: null,
                    'email' => $full ? $contact->email : ($contact->email !== null ? Pii::maskEmail($contact->email) : null),
                    'phone' => $full ? $contact->phone : ($contact->phone !== null ? Pii::maskPhone($contact->phone) : null),
                ] : null,
                'lead_id' => $s->lead_id !== null ? 'ld_'.$s->lead_id : null,
                'via' => $s->via,
                'source' => $s->source,
                'consents' => $s->consents ?? [],
                'was_known' => $s->was_known,
                'answers' => $full ? array_diff_key($data, ['email' => true, 'phone' => true, 'name' => true]) : null,
                'created_at' => $s->created_at->toIso8601String(),
            ];
        })->values()]);
    }

    /** GET capture/pages/{page}/qr?format=svg|png&size=&download=1: el QR de la página con UTM `qr`. */
    public function qr(Request $request, string $page): Response
    {
        Gate::authorize('ws.viewDashboards');
        $ws = $this->current->get();
        $model = $this->find($page);

        return self::qrResponse($request, CaptureUrls::pageQr($ws, $model), 'qr-'.$model->slug);
    }

    public static function qrResponse(Request $request, string $url, string $filename): Response
    {
        $format = in_array($request->query('format'), QrImage::FORMATS, true) ? (string) $request->query('format') : 'svg';
        $size = is_numeric($request->query('size')) ? (int) $request->query('size') : null;
        $headers = ['Content-Type' => QrImage::mime($format), 'Cache-Control' => 'private, max-age=300', 'X-Content-Type-Options' => 'nosniff'];
        if ($request->boolean('download')) {
            $headers['Content-Disposition'] = 'attachment; filename="'.$filename.'.'.$format.'"';
        }

        return response(QrImage::render($format, $url, $size), 200, $headers);
    }

    private function find(string $publicId): LandingPage
    {
        $page = LandingPage::findByPublicId($publicId);
        abort_if($page === null, 404);

        return $page;
    }

    private function from(Request $request): string
    {
        $days = max(1, min(365, (int) ($request->query('days') ?? 30)));

        return CarbonImmutable::now()->utc()->subDays($days - 1)->toDateString();
    }
}
