<?php

declare(strict_types=1);

namespace App\Domain\Onsite\Http\Public;

use App\Domain\Consent\Actions\RecordConsent;
use App\Domain\Onsite\Actions\ConfirmDoubleOptIn;
use App\Domain\Onsite\Actions\SubmitWidget;
use App\Domain\Onsite\Models\OnsiteWidget;
use App\Domain\Onsite\Queries\VisitorProfile;
use App\Domain\Onsite\Support\OptInToken;
use App\Domain\Onsite\Support\PublicSite;
use App\Domain\Onsite\Support\PublicWidgetConfig;
use App\Support\Ids\PrefixedId;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Endpoints públicos de captación (api-connector.md §8.3): `visitor.json`, el envío
 * de un widget y la página de doble opt-in. Sin sesión, CSRF ni firma: el sitio se
 * identifica por su clave pública; límites por IP con hash y por navegador.
 */
final class OnsitePublicController
{
    public function __construct(private readonly PublicSite $sites, private readonly CurrentWorkspace $current) {}

    /** GET /p/{publicKey}/visitor.json?aid= */
    public function visitor(Request $request, string $publicKey, VisitorProfile $profile, PublicWidgetConfig $config): JsonResponse
    {
        [$site, $workspace] = $this->sites->resolve($publicKey) ?? [null, null];
        if ($site === null || $workspace === null) {
            return response()->json(['error' => 'unknown_site'], 404);
        }
        $aid = is_string($request->query('aid')) ? mb_substr((string) $request->query('aid'), 0, 64) : null;
        $headers = PublicSite::cors($request, $site) + ['Cache-Control' => 'private, no-store'];
        if (PublicSite::tooMany('visitor', $request, $aid, 240, 60)) {
            return response()->json(['error' => 'rate_limited'], 429)->withHeaders($headers);
        }
        $body = $this->current->run($workspace, fn () => $profile->for($workspace, $aid, PublicSite::country($request), $config->live($site)));

        return response()->json($body)->withHeaders($headers);
    }

    /** POST /p/{publicKey}/widgets/{widgetId}/submit (text/plain con JSON) */
    public function submit(Request $request, string $publicKey, string $widgetId, SubmitWidget $submit): JsonResponse
    {
        [$site, $workspace] = $this->sites->resolve($publicKey) ?? [null, null];
        if ($site === null || $workspace === null) {
            return response()->json(['ok' => false, 'error' => 'unknown_site'], 404);
        }
        $cors = PublicSite::cors($request, $site) + ['Cache-Control' => 'private, no-store'];
        $body = PublicSite::body($request);
        $aid = is_string($body['anonymous_id'] ?? null) ? $body['anonymous_id'] : null;
        if (PublicSite::tooMany('submit', $request, $aid, 30, 8)) {
            return response()->json(['ok' => false, 'error' => 'rate_limited', 'message' => __('Demasiados intentos. Espera un momento e inténtalo de nuevo.')], 429)->withHeaders($cors);
        }
        if ($body === null) {
            return response()->json(['ok' => false, 'message' => __('La solicitud no es válida.'), 'errors' => ['body' => [__('La solicitud no es válida.')]]], 422)->withHeaders($cors);
        }

        [$status, $payload] = $this->current->run($workspace, function () use ($workspace, $site, $widgetId, $body, $request, $submit): array {
            $id = PrefixedId::decode(OnsiteWidget::ID_PREFIX, $widgetId);
            $widget = $id === null ? null : OnsiteWidget::query()->with('quiz')->find($id);
            if ($widget === null || ! $widget->isLive() || ! $widget->captures() || ($widget->site_id !== null && $widget->site_id !== $site->id)) {
                return [404, ['ok' => false, 'error' => 'unknown_widget', 'message' => __('Este formulario ya no está disponible.')]];
            }

            return $submit->handle($workspace, $site, $widget, $body, [
                'ip' => $request->ip(),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 500),
                'country' => PublicSite::country($request),
            ]);
        });

        return response()->json($payload, $status)->withHeaders($cors);
    }

    /** GET /p/{publicKey}/confirmar/{token}: doble opt-in por correo. */
    public function confirm(Request $request, string $publicKey, string $token, ConfirmDoubleOptIn $confirm): Response
    {
        $headers = ['Cache-Control' => 'no-store, private', 'X-Robots-Tag' => 'noindex'];
        [$site, $workspace] = $this->sites->resolve($publicKey) ?? [null, null];
        $data = OptInToken::parse($token);
        if ($site === null || $workspace === null || $data === null || $data['w'] !== $workspace->id) {
            return response()->view('onsite.confirm', ['state' => ConfirmDoubleOptIn::INVALID, 'brand' => $workspace->name ?? '', 'shop' => null], 404)->withHeaders($headers);
        }
        $state = $this->current->run($workspace, fn () => $confirm->handle($workspace, $data['c'], array_filter([
            'widget_id' => OnsiteWidget::ID_PREFIX.'_'.$data['g'],
            'ip_hash' => RecordConsent::evidenceHash($request->ip()),
            'ua_hash' => RecordConsent::evidenceHash($request->userAgent()),
        ])));

        return response()->view('onsite.confirm', [
            'state' => $state,
            'brand' => (string) ($workspace->getAttribute('legal_name') ?: $workspace->name),
            'shop' => $site->getAttribute('url'),
        ], $state === ConfirmDoubleOptIn::INVALID ? 404 : 200)->withHeaders($headers);
    }
}
