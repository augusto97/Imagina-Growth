<?php

declare(strict_types=1);

namespace App\Domain\Quotes\Support;

use App\Domain\Bookings\Support\BookingSettings;
use App\Domain\Capture\Http\Public\PublicCapture;
use App\Domain\Capture\Support\CaptureUrls;
use App\Domain\Quotes\Http\Public\PublicQuoteController;
use App\Domain\Quotes\Models\Quote;
use App\Domain\Quotes\Models\QuoteCalculator;
use App\Domain\Sites\Models\Site;
use App\Domain\Templates\Support\BusinessProfile;
use App\Domain\Workspaces\Contracts\Branding;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Tenancy\WorkspaceLocale;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * La página del cotizador en el hub (M18): `/p/{clave}/cotizar/{qc_…}`, para compartir por enlace o QR sin WordPress.
 * Vive en el dominio propio verificado de las páginas públicas (el de la agenda, M22) o en el del hub, con la marca
 * del negocio (o la de la agencia en el pie). Las preguntas se arman aquí una vez (`steps`) y las pinta la misma
 * vista en la página pública y en la vista previa del panel.
 */
final class QuotePage
{
    /** UTM del QR impreso: así se cuentan aparte las cotizaciones que vinieron del papel. */
    public const QR_UTM = ['utm_source' => 'qr', 'utm_medium' => 'qr'];

    /** Días que se puede volver a ver el resultado con su enlace. */
    public const RESULT_DAYS = 30;

    /** La clave pública de los enlaces: la del sitio del cotizador, o la de la agenda (sitio o espacio de trabajo). */
    public static function key(Workspace $workspace, QuoteCalculator $calculator): string
    {
        if ($calculator->site_id !== null) {
            $site = Site::withoutWorkspaceScope()->where('workspace_id', $workspace->id)->whereKey($calculator->site_id)->first();
            if ($site !== null && is_string($site->public_key) && $site->public_key !== '') {
                return $site->public_key;
            }
        }

        return BookingSettings::publicKey($workspace);
    }

    public static function url(Workspace $workspace, QuoteCalculator $calculator, ?string $origin = null): string
    {
        return ($origin ?? BookingSettings::origin($workspace)).'/p/'.self::key($workspace, $calculator).'/cotizar/'.$calculator->publicId();
    }

    public static function qrUrl(Workspace $workspace, QuoteCalculator $calculator): string
    {
        return self::url($workspace, $calculator).'?'.http_build_query(self::QR_UTM + ['utm_campaign' => 'cotizador']);
    }

    /** Firma del enlace del resultado: solo quien pidió la cotización lo tiene. */
    public static function signature(Quote $quote): string
    {
        return substr(hash_hmac('sha256', 'quote-result:'.$quote->id, (string) config('app.key')), 0, 32);
    }

    /**
     * Las preguntas en pasos, ya con lo que muestra cada tarjeta (emoji, descripción y, si los precios son públicos,
     * cuánto suma o multiplica).
     *
     * @param  array<string, mixed>  $definition  la definición guardada (con precios)
     * @return list<array<string, mixed>>
     */
    public static function steps(array $definition, bool $showPrices, string $currency): array
    {
        $steps = [];
        $packages = (array) ($definition['packages'] ?? []);
        if ($packages !== []) {
            $steps[] = [
                'key' => 'package', 'type' => 'package', 'label' => __('Elige un paquete'), 'help' => null, 'icon' => null, 'required' => true,
                'options' => array_map(fn (array $p) => [
                    'value' => (string) $p['key'], 'label' => (string) $p['label'], 'icon' => $p['icon'] ?? null, 'description' => $p['description'] ?? null,
                    'price' => $showPrices && (float) $p['price'] > 0 ? self::money((float) $p['price'], $currency) : null,
                ], $packages),
            ];
        }
        foreach ((array) ($definition['fields'] ?? []) as $f) {
            $step = ['key' => (string) $f['key'], 'type' => (string) $f['type'], 'label' => (string) $f['label'], 'help' => $f['help'] ?? null,
                'icon' => $f['icon'] ?? null, 'required' => (bool) ($f['required'] ?? false)];
            if ($f['type'] === 'number') {
                $step += ['unit' => $f['unit'] ?? null, 'min' => (float) ($f['min'] ?? 0), 'max' => isset($f['max']) ? (float) $f['max'] : null,
                    'step' => (float) ($f['step'] ?? 1), 'default' => isset($f['default']) ? (float) $f['default'] : null,
                    'price' => $showPrices && (float) ($f['unit_price'] ?? 0) > 0
                        ? __(':price por :unit', ['price' => self::money((float) $f['unit_price'], $currency), 'unit' => $f['unit'] ?? __('unidad')]) : null];
            } else {
                $step['options'] = array_map(fn (array $o) => [
                    'value' => (string) $o['value'], 'label' => (string) $o['label'], 'icon' => $o['icon'] ?? null, 'description' => $o['description'] ?? null,
                    'price' => $showPrices ? self::optionPrice((float) ($o['price'] ?? 0), (float) ($o['multiplier'] ?? 1), $currency) : null,
                ], (array) ($f['options'] ?? []));
            }
            $steps[] = $step;
        }

        return $steps;
    }

    /** «+ $30.000», «+25 %», «−10 %» o nada. */
    private static function optionPrice(float $price, float $multiplier, string $currency): ?string
    {
        $parts = [];
        if ($price > 0) {
            $parts[] = '+ '.self::money($price, $currency);
        }
        if (abs($multiplier - 1) >= 0.005) {
            $pct = (int) round(($multiplier - 1) * 100);
            $parts[] = ($pct > 0 ? '+' : '−').abs($pct).' %';
        }

        return $parts !== [] ? implode(' · ', $parts) : null;
    }

    public static function money(float $value, string $currency): string
    {
        $decimals = $currency === 'COP' || floor($value) === $value ? 0 : 2;

        return '$'.number_format($value, $decimals, ',', '.');
    }

    /**
     * Respuestas de ejemplo para la vista previa: el valor por defecto o el mínimo, la primera opción y el primer paquete.
     *
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    public static function sampleAnswers(array $definition): array
    {
        $out = [];
        $packages = (array) ($definition['packages'] ?? []);
        if ($packages !== []) {
            $out['package'] = $packages[0]['key'];
        }
        foreach ((array) ($definition['fields'] ?? []) as $f) {
            $out[$f['key']] = match ($f['type']) {
                'number' => $f['default'] ?? max((float) ($f['min'] ?? 0), 1.0),
                'select' => $f['options'][0]['value'] ?? null,
                default => [],
            };
        }

        return $out;
    }

    /**
     * Las respuestas del formulario de la página (`a[campo]`, `a[campo][]`, `a[package]`) como las espera el motor.
     *
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    public static function answersFrom(Request $request, array $definition): array
    {
        $raw = (array) $request->input('a', []);
        $out = [];
        if ((array) ($definition['packages'] ?? []) !== [] && is_string($raw['package'] ?? null)) {
            $out['package'] = $raw['package'];
        }
        foreach ((array) ($definition['fields'] ?? []) as $f) {
            $value = $raw[$f['key']] ?? null;
            $out[$f['key']] = match ($f['type']) {
                'checkbox' => array_values(array_filter((array) $value, 'is_string')),
                'number' => is_string($value) && trim($value) !== '' ? str_replace(',', '.', trim($value)) : null,
                default => is_string($value) && $value !== '' ? $value : null,
            };
        }

        return $out;
    }

    /** Texto del rango, como en el widget: «$686.000 – $839.000 COP». */
    public static function range(string $min, string $max, string $currency): string
    {
        return PublicQuoteController::range($min, $max, $currency);
    }

    /**
     * Pinta una vista pública con la marca del negocio, en su idioma, sin caché, noindex y con una CSP estricta con el
     * nonce del único script.
     *
     * @param  array<string, mixed>  $data
     */
    public static function view(string $view, ?Workspace $workspace, array $data, int $status = 200): Response
    {
        $nonce = Str::random(24);
        $render = function () use ($view, $workspace, $data, $status, $nonce): Response {
            $profile = $workspace !== null ? BusinessProfile::for($workspace) : null;
            $brand = $workspace !== null ? app(Branding::class)->for($workspace) : null;
            $accent = $brand !== null && preg_match('/^#[0-9a-fA-F]{6}$/', $brand->primaryColor) === 1 ? $brand->primaryColor : '#4f46e5';

            return response()->view($view, $data + [
                'business' => $profile !== null ? ($profile['name'] !== '' ? $profile['name'] : (string) $workspace?->name) : 'Imagina Growth',
                'logo' => $profile !== null && $profile['logo_url'] !== '' ? $profile['logo_url'] : null,
                'accent' => $accent,
                'accentFg' => PublicCapture::readableOn($accent),
                'poweredBy' => $brand !== null ? $brand->name : 'Imagina Growth',
                'lang' => $workspace !== null ? str_replace('_', '-', WorkspaceLocale::of($workspace)) : 'es',
                'nonce' => $nonce,
                'preview' => $data['preview'] ?? false,
            ], $status);
        };
        $response = $workspace !== null ? WorkspaceLocale::run($workspace, $render) : $render();

        return $response->withHeaders([
            'Cache-Control' => 'no-store, private',
            'X-Robots-Tag' => 'noindex, nofollow',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'X-Frame-Options' => 'SAMEORIGIN',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self' https: data:; style-src 'unsafe-inline'; script-src 'nonce-{$nonce}'; form-action 'self' https:; frame-ancestors 'self'; base-uri 'none'",
        ]);
    }

    /** ¿El dominio de la petición puede servir las páginas de este espacio de trabajo? (el de otra agencia, no). */
    public static function hostAllows(Request $request, Workspace $workspace): bool
    {
        return CaptureUrls::hostAllows($request, $workspace);
    }

    /** Origen de los enlaces de la página servida (el dominio propio por el que entró, si es suyo). */
    public static function origin(Request $request, Workspace $workspace): string
    {
        return CaptureUrls::origin($request, $workspace);
    }
}
