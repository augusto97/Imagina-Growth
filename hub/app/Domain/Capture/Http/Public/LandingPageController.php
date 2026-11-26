<?php

declare(strict_types=1);

namespace App\Domain\Capture\Http\Public;

use App\Domain\Capture\Actions\SubmitLandingPage;
use App\Domain\Capture\Models\LandingPage;
use App\Domain\Capture\Models\WhatsAppLink;
use App\Domain\Capture\Support\CaptureCounters;
use App\Domain\Capture\Support\CaptureUrls;
use App\Domain\Capture\Support\LandingImages;
use App\Domain\Capture\Support\LandingToken;
use App\Domain\Onsite\Support\PublicWidgetConfig;
use App\Domain\Templates\Support\BusinessProfile;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * Página de captura pública (`/p/{slug}`, Blade, rediseño v2 §11.3): rápida (sin la SPA, CSS mínimo en línea y un
 * solo script de 3 líneas), accesible y en el celular primero. Sin sesión ni CSRF (routes/public): el formulario lleva
 * un token firmado de un solo uso (LandingToken), un campo trampa para robots y límites por IP con hash. Recibe UTM,
 * `ref`, identificadores de clic y `aid` en la URL y los lleva al envío para la atribución; con UTM `qr` la visita y
 * el envío cuentan como del QR impreso. Lleva noindex salvo que la página pida indexarse.
 */
final class LandingPageController
{
    /** Campos de la URL que viajan con el envío (atribución). */
    public const CARRY = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'gclid', 'gbraid', 'wbraid', 'fbclid', 'ttclid', 'msclkid', 'ref', 'aid', 'rh', 'via'];

    public function __construct(private readonly CurrentWorkspace $current, private readonly PublicCapture $public) {}

    /** GET /p/{slug}[?utm_…&ref=&gclid=&aid=] (con `vista_previa` firmada, el borrador para el panel) */
    public function show(Request $request, string $slug): Response
    {
        $preview = $request->has('vista_previa') && URL::hasValidSignature($request);
        $ctx = $this->public->resolve($slug, ! $preview);
        if ($ctx === null || $this->public->limited($request, 'view', (int) config('igr-capture.limits.views_per_minute', 120))) {
            return $this->public->missing(null, $ctx === null ? 404 : 429);
        }
        [$workspace, $page] = $ctx;
        if (! $this->public->available($workspace) || ! CaptureUrls::hostAllows($request, $workspace)) {
            return $this->public->missing($workspace);
        }
        $carry = self::carry($request);
        if (! $preview) {
            CaptureCounters::view($workspace->id, CaptureCounters::PAGE, $page->id, self::via($carry), $request->ip());
        }

        return $this->current->run($workspace, fn () => $this->page($request, $workspace, $page, $carry, [], [], 200, $preview));
    }

    /** POST /p/{slug} (formulario normal: funciona sin JavaScript) */
    public function submit(Request $request, string $slug, SubmitLandingPage $submit): Response
    {
        $ctx = $this->public->resolve($slug);
        if ($ctx === null) {
            return $this->public->missing();
        }
        [$workspace, $page] = $ctx;
        if (! $this->public->available($workspace) || ! CaptureUrls::hostAllows($request, $workspace)) {
            return $this->public->missing($workspace);
        }
        $carry = self::carry($request);
        $old = is_array($request->input('f')) ? (array) $request->input('f') : [];

        return $this->current->run($workspace, function () use ($request, $workspace, $page, $carry, $old, $submit): Response {
            if ($this->public->limited($request, 'submit', (int) config('igr-capture.limits.submits_per_minute', 10))
                || $this->public->limited($request, 'submit-page', (int) config('igr-capture.limits.submits_per_hour_per_page', 600), 3600, $page->id)) {
                return $this->page($request, $workspace, $page, $carry, ['form' => __('Recibimos muchos envíos seguidos. Espera un minuto e inténtalo de nuevo.')], $old, 429);
            }
            // Campo trampa: una persona no lo ve; un robot lo llena. Se le responde como si nada.
            if (trim((string) $request->input('website', '')) !== '') {
                return $this->thanks($request, $workspace, $page);
            }
            $token = $request->input('t');
            $check = LandingToken::check($page, $token);
            if ($check === LandingToken::TOO_FAST) {
                return $this->thanks($request, $workspace, $page);
            }
            if ($check !== LandingToken::OK) {
                return $this->page($request, $workspace, $page, $carry, ['form' => __('La página estuvo abierta mucho tiempo. Revisa tus datos y vuelve a enviarlos.')], $old, 422);
            }
            if (! LandingToken::spend($page, (string) $token)) {
                return $this->thanks($request, $workspace, $page); // doble clic o reenvío: ya se recibió
            }
            $result = $submit->handle($workspace, $page, $request->all(), [
                'ip' => $request->ip(), 'user_agent' => (string) $request->userAgent(), 'url' => CaptureUrls::page($workspace, $page),
            ], $carry);
            if (isset($result['errors'])) {
                return $this->page($request, $workspace, $page, $carry, $result['errors'], $old, 422);
            }

            return $this->thanks($request, $workspace, $page);
        });
    }

    /** GET /p/{slug}/gracias */
    public function thankYou(Request $request, string $slug): Response
    {
        $ctx = $this->public->resolve($slug);
        if ($ctx === null || $this->public->limited($request, 'view', (int) config('igr-capture.limits.views_per_minute', 120))) {
            return $this->public->missing(null, $ctx === null ? 404 : 429);
        }
        [$workspace, $page] = $ctx;
        if (! $this->public->available($workspace)) {
            return $this->public->missing($workspace);
        }

        return $this->current->run($workspace, fn () => $this->public->view('capture.thanks', $workspace, $page, [
            'title' => $page->text('success_title') ?: __('¡Gracias! Recibimos tus datos'),
            'message' => $page->text('success_message'),
            'logo' => self::logo($workspace, $page),
            'whatsapp' => $this->whatsapp($workspace, $page),
            'back' => CaptureUrls::origin($request, $workspace).'/p/'.$page->slug,
        ]));
    }

    /** GET /p/{slug}/i/{nombre}: el logo o la imagen subidos (sin metadatos). */
    public function image(LandingImages $images, string $slug, string $name): Response
    {
        $ctx = $this->public->resolve($slug, false);
        if ($ctx === null || ! LandingImages::validName($name) || ! in_array($name, [$ctx[1]->text('logo_key'), $ctx[1]->text('image_key')], true)) {
            return response('', 404);
        }
        $path = LandingImages::dir($ctx[1]).'/'.$name;
        if (! $images->disk()->exists($path)) {
            return response('', 404);
        }

        return response((string) $images->disk()->get($path), 200, [
            'Content-Type' => str_ends_with($name, '.png') ? 'image/png' : 'image/jpeg',
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * @param  array<string, string>  $carry
     * @param  array<string, string>  $errors
     * @param  array<mixed>  $old
     */
    private function page(Request $request, Workspace $workspace, LandingPage $page, array $carry, array $errors, array $old, int $status = 200, bool $preview = false): Response
    {
        $texts = collect(app(PublicWidgetConfig::class)->legalConsents($workspace))->keyBy('channel');
        $consents = [];
        foreach ((array) ($page->consent_channels ?? []) as $channel) {
            if (isset($texts[$channel])) {
                $consents[] = ['channel' => (string) $channel, 'label' => (string) $texts[$channel]['label'], 'version' => (string) $texts[$channel]['version'],
                    'after' => $channel === 'email' ? 'email' : 'phone', 'checked' => ! empty(((array) $request->input('consent', []))[$channel] ?? null) && $status !== 200];
            }
        }
        $privacy = $workspace->getAttribute('privacy_policy_url');

        return $this->public->view('capture.page', $workspace, $page, [
            'title' => $page->text('title'),
            'paragraphs' => array_values(array_filter(array_map('trim', preg_split('/\R{2,}/', $page->text('body')) ?: []), fn ($p) => $p !== '')),
            'button' => $page->text('button') ?: __('Enviar'),
            'logo' => self::logo($workspace, $page),
            'image' => $page->text('image_key') !== '' ? CaptureUrls::origin($request, $workspace).'/p/'.$page->slug.'/i/'.$page->text('image_key') : ($page->text('image_url') ?: null),
            'fields' => self::fields($page, $workspace),
            'consents' => $consents,
            'whatsapp' => $this->whatsapp($workspace, $page),
            'action' => CaptureUrls::origin($request, $workspace).'/p/'.$page->slug,
            'token' => LandingToken::issue($page),
            'carry' => $carry,
            'errors' => $errors,
            'old' => $old,
            'privacyUrl' => is_string($privacy) && str_starts_with($privacy, 'http') ? $privacy : null,
            'preview' => $preview,
        ], $status);
    }

    private function thanks(Request $request, Workspace $workspace, LandingPage $page): Response
    {
        $redirect = $page->text('redirect_url');
        $to = $redirect !== '' && str_starts_with($redirect, 'https://') ? $redirect : CaptureUrls::origin($request, $workspace).'/p/'.$page->slug.'/gracias';

        return redirect()->away($to, 303)->withHeaders(['Cache-Control' => 'no-store, private']);
    }

    /** @return array{url: string, label: string}|null */
    private function whatsapp(Workspace $workspace, LandingPage $page): ?array
    {
        $wa = (array) (($page->content ?? [])['whatsapp'] ?? []);
        if (! (bool) ($wa['enabled'] ?? false) || $page->wa_link_id === null) {
            return null;
        }
        $link = WhatsAppLink::query()->whereKey($page->wa_link_id)->where('status', 'active')->first();

        return $link === null ? null : ['url' => CaptureUrls::waLink($workspace, $link), 'label' => is_string($wa['label'] ?? null) && $wa['label'] !== '' ? $wa['label'] : __('Escríbenos por WhatsApp')];
    }

    private static function logo(Workspace $workspace, LandingPage $page): ?string
    {
        if ($page->text('logo_key') !== '') {
            return CaptureUrls::page($workspace, $page).'/i/'.$page->text('logo_key');
        }
        $url = $page->text('logo_url') ?: BusinessProfile::for($workspace)['logo_url'];

        return str_starts_with($url, 'https://') ? $url : null;
    }

    /**
     * Los campos con lo que necesita el HTML (tipo, autocompletado, teclado del celular).
     *
     * @return list<array<string, mixed>>
     */
    private static function fields(LandingPage $page, Workspace $workspace): array
    {
        $out = [];
        foreach ((array) ($page->fields ?? []) as $field) {
            $key = (string) ($field['key'] ?? '');
            $type = (string) ($field['type'] ?? 'text');
            $out[] = [
                'key' => $key,
                'id' => 'f-'.str_replace('_', '-', $key),
                'label' => (string) ($field['label'] ?? $key),
                'type' => $type,
                'required' => (bool) ($field['required'] ?? false),
                'options' => array_values((array) ($field['options'] ?? [])),
                'placeholder' => is_string($field['placeholder'] ?? null) ? $field['placeholder'] : ($key === 'phone' ? self::phoneExample($workspace) : ''),
                'autocomplete' => match ($key) {
                    'name' => 'name', 'email' => 'email', 'phone' => 'tel', default => 'off',
                },
                'inputmode' => match ($key) {
                    'email' => 'email', 'phone' => 'tel', default => null,
                },
            ];
        }

        return $out;
    }

    private static function phoneExample(Workspace $workspace): string
    {
        return match (strtoupper((string) $workspace->country)) {
            'MX' => __('Ej.: 55 1234 5678'),
            'VE' => __('Ej.: 412 123 4567'),
            default => __('Ej.: 300 123 4567'),
        };
    }

    /** @return array<string, string> UTM, `ref`, identificadores de clic, `aid`, el host del referente y la vía */
    public static function carry(Request $request): array
    {
        $out = [];
        foreach (self::CARRY as $field) {
            $value = $request->input($field);
            if (is_string($value) && trim($value) !== '') {
                $out[$field] = mb_substr(trim($value), 0, 200);
            }
        }
        if (isset($out['ref']) && preg_match('/^[A-Za-z0-9_-]{3,32}$/', $out['ref']) !== 1) {
            unset($out['ref']);
        }
        if (isset($out['aid']) && preg_match('/^[0-9a-f-]{36}$/i', $out['aid']) !== 1) {
            unset($out['aid']);
        }
        if (! isset($out['rh']) && $request->isMethod('GET')) {
            $host = parse_url((string) $request->headers->get('referer'), PHP_URL_HOST);
            if (is_string($host) && $host !== '' && $host !== $request->getHost()) {
                $out['rh'] = mb_substr(strtolower($host), 0, 120);
            }
        }
        if (isset($out['rh']) && preg_match('/^[a-z0-9.-]{3,120}$/', $out['rh']) !== 1) {
            unset($out['rh']);
        }
        $out['via'] = self::via($out);

        return $out;
    }

    /** @param array<string, string> $carry */
    public static function via(array $carry): string
    {
        return ($carry['via'] ?? '') === 'qr' || ($carry['utm_source'] ?? '') === 'qr' || ($carry['utm_medium'] ?? '') === 'qr' ? 'qr' : 'link';
    }
}
