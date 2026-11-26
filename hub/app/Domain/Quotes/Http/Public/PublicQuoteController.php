<?php

declare(strict_types=1);

namespace App\Domain\Quotes\Http\Public;

use App\Domain\Billing\Contracts\Entitlements;
use App\Domain\Loyalty\Http\Public\PublicSite;
use App\Domain\Onsite\Support\PublicWidgetConfig;
use App\Domain\Quotes\Actions\SubmitQuote;
use App\Domain\Quotes\Models\QuoteCalculator;
use App\Domain\Quotes\Support\QuoteCapture;
use App\Domain\Quotes\Support\QuoteEngine;
use App\Domain\Sites\Models\Site;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * El cotizador en la tienda (M18, api-connector.md §8.3): la definición pública
 * (sin notas internas, y sin precios si el precio se da a cambio del contacto) y
 * el cálculo, siempre en el hub. Nunca devuelve datos personales.
 */
final class PublicQuoteController
{
    public function __construct(private readonly PublicSite $public, private readonly CurrentWorkspace $current) {}

    /** GET /p/{key}/quotes/{id} — en caché de CDN 5 minutos */
    public function show(Request $request, string $publicKey, string $calculator, PublicWidgetConfig $widgets): JsonResponse
    {
        $ctx = $this->public->resolve($publicKey);
        if ($ctx === null) {
            return response()->json(['ok' => false, 'error' => ['code' => 'unknown_site']], 404);
        }
        [$site, $workspace] = $ctx;
        if ($this->public->limited($request, 'quote-show', 120)) {
            return $this->public->error($request, $site, 429, 'rate_limited');
        }
        // Sin el cotizador en el plan (M21) responde como si no existiera: el espacio del sitio se oculta.
        if (! app(Entitlements::class)->allows($workspace, 'quotes')) {
            return $this->public->error($request, $site, 404, 'unknown_calculator', __('Este cotizador no está disponible.'));
        }

        return $this->current->run($workspace, function () use ($request, $site, $workspace, $calculator, $widgets): JsonResponse {
            $model = $this->calculator($calculator, $site);
            if ($model === null) {
                return $this->public->error($request, $site, 404, 'unknown_calculator', __('Este cotizador no está disponible.'));
            }
            $capture = QuoteCapture::for($model->capture);
            $consents = array_values(array_filter($widgets->legalConsents($workspace), fn (array $c) => in_array($c['channel'], $capture['consent_channels'], true)
                && ($c['channel'] !== 'email' || in_array('email', $capture['fields'], true))
                && ($c['channel'] === 'email' || in_array('phone', $capture['fields'], true))));

            return response()->json([
                'ok' => true,
                'calculator' => [
                    'id' => $model->publicId(), 'name' => $model->name, 'kind' => $model->kind,
                    'currency' => (string) (($model->definition['currency'] ?? null) ?: ($workspace->currency ?: 'COP')),
                    'definition' => QuoteEngine::publicDefinition($model, ! $capture['required']),
                    'capture' => [
                        'required' => $capture['required'], 'fields' => $capture['fields'], 'require' => $capture['require'], 'consents' => $consents,
                        'title' => $capture['title'] ?? __('¿A dónde te enviamos tu cotización?'), 'cta' => $capture['cta'] ?? __('Ver mi cotización'),
                    ],
                ],
            ])->withHeaders($this->public->cors($request, $site) + ['Cache-Control' => 'public, max-age=300']);
        });
    }

    /** POST /p/{key}/quotes/{id}/estimate (text/plain con JSON) */
    public function estimate(Request $request, string $publicKey, string $calculator, SubmitQuote $submit): JsonResponse
    {
        $ctx = $this->public->resolve($publicKey);
        if ($ctx === null) {
            return response()->json(['ok' => false, 'error' => ['code' => 'unknown_site']], 404);
        }
        [$site, $workspace] = $ctx;
        $body = PublicSite::body($request);
        $aid = is_string($body['anonymous_id'] ?? null) && preg_match('/^[0-9a-zA-Z-]{8,64}$/', $body['anonymous_id']) ? (string) $body['anonymous_id'] : null;
        if ($this->public->limited($request, 'quote-estimate', 20) || ($aid !== null && $this->limitedAid($aid))) {
            return $this->public->error($request, $site, 429, 'rate_limited', __('Espera un momento antes de volver a intentarlo.'));
        }
        if (! app(Entitlements::class)->allows($workspace, 'quotes')) {
            return $this->public->error($request, $site, 404, 'unknown_calculator', __('Este cotizador no está disponible.'));
        }

        return $this->current->run($workspace, function () use ($request, $site, $workspace, $calculator, $body, $aid, $submit): JsonResponse {
            $model = $this->calculator($calculator, $site);
            if ($model === null) {
                return $this->public->error($request, $site, 404, 'unknown_calculator', __('Este cotizador no está disponible.'));
            }
            $settings = QuoteCapture::for($model->capture);
            try {
                $result = $submit->handle(
                    $workspace, $site, $model, is_array($body['answers'] ?? null) ? $body['answers'] : [],
                    is_array($body['fields'] ?? null) ? $body['fields'] : [], is_array($body['consents'] ?? null) ? $body['consents'] : [],
                    $aid, SubmitQuote::str($body['page_url'] ?? null, 500), $request->ip(), (string) $request->userAgent(), self::source($body),
                );
            } catch (ValidationException $e) {
                return $this->invalid($request, $site, $e->errors());
            }
            ['quote' => $quote, 'estimate' => $estimate] = $result;
            $currency = $quote->currency;

            return response()->json([
                'ok' => true,
                'quote_id' => $quote->publicId(),
                'estimate' => ['min' => $estimate['min'], 'max' => $estimate['max'], 'currency' => $currency,
                    'text' => self::range($estimate['min'], $estimate['max'], $currency)],
                'lines' => $estimate['lines'],
                'note' => $model->definition['note'] ?? null,
                'message' => $settings['success'] ?? __('Es un valor estimado. Te contactaremos para confirmar el precio final.'),
                'whatsapp' => self::whatsapp($settings['whatsapp_number'], $model->name, $estimate['min'], $estimate['max'], $currency, $quote->publicId()),
            ])->withHeaders($this->public->cors($request, $site) + ['Cache-Control' => 'private, no-store']);
        });
    }

    /** wa.me del negocio con la cotización y su referencia, o null si el cotizador no tiene número. */
    public static function whatsapp(?string $number, string $name, string $min, string $max, string $currency, string $ref): ?string
    {
        return $number !== null ? 'https://wa.me/'.$number.'?text='.rawurlencode(
            __('Hola, pedí una cotización de :name (:range). Ref: :ref', ['name' => $name, 'range' => self::range($min, $max, $currency), 'ref' => $ref])
        ) : null;
    }

    private function calculator(string $id, Site $site): ?QuoteCalculator
    {
        $model = QuoteCalculator::findByPublicId($id);

        return $model !== null && $model->status === 'active' && ($model->site_id === null || $model->site_id === $site->id) ? $model : null;
    }

    private function limitedAid(string $aid): bool
    {
        $key = 'igr-quote:aid:'.hash('sha256', $aid);
        if (RateLimiter::tooManyAttempts($key, 10)) {
            return true;
        }
        RateLimiter::hit($key, 60);

        return false;
    }

    /** @param array<string, array<int, string>|string> $errors */
    private function invalid(Request $request, Site $site, array $errors): JsonResponse
    {
        $errors = array_map(fn ($e) => array_values((array) $e), $errors);
        $first = (string) (array_values($errors)[0][0] ?? __('Revisa los datos.'));

        return response()->json(['ok' => false, 'message' => $first, 'errors' => $errors], 422)
            ->withHeaders($this->public->cors($request, $site) + ['Cache-Control' => 'private, no-store']);
    }

    public static function range(string $min, string $max, string $currency): string
    {
        $decimals = $currency === 'COP' ? 0 : 2;
        $fmt = fn (string $v) => '$'.number_format((float) $v, $decimals, ',', '.');

        return $min === $max ? $fmt($min).' '.$currency : $fmt($min).' – '.$fmt($max).' '.$currency;
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, string>
     */
    public static function source(array $body, string $channel = 'quote_widget'): array
    {
        $out = ['channel' => $channel];
        foreach (['utm_source', 'utm_medium', 'utm_campaign', 'gclid', 'fbclid', 'ttclid'] as $field) {
            if (is_string($body['utm'][$field] ?? null) && $body['utm'][$field] !== '') {
                $out[$field] = mb_substr((string) $body['utm'][$field], 0, 200);
            }
        }
        if (is_string($body['page_url'] ?? null)) {
            $out['page'] = mb_substr((string) $body['page_url'], 0, 300);
        }

        return $out;
    }
}
