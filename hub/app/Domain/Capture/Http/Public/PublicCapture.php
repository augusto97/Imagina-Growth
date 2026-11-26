<?php

declare(strict_types=1);

namespace App\Domain\Capture\Http\Public;

use App\Domain\Billing\Contracts\Entitlements;
use App\Domain\Capture\Models\LandingPage;
use App\Domain\Templates\Support\BusinessProfile;
use App\Domain\Workspaces\Contracts\Branding;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Tenancy\WorkspaceLocale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lo común de las páginas públicas de la captación: la página por su slug (con su espacio de trabajo), el módulo del
 * plan (`onsite`), los límites por IP con hash (nunca se guarda la IP), la marca y los encabezados (sin caché, noindex
 * salvo que la página pida indexarse, sin marcos ajenos y una CSP estricta con el nonce del único script).
 */
final class PublicCapture
{
    public function __construct(private readonly Branding $branding, private readonly Entitlements $entitlements) {}

    /** @return array{0: Workspace, 1: LandingPage}|null */
    public function resolve(string $slug, bool $publishedOnly = true): ?array
    {
        $page = LandingPage::withoutWorkspaceScope()->where('slug', $slug)
            ->when($publishedOnly, fn ($q) => $q->where('status', 'published'), fn ($q) => $q->where('status', '!=', 'archived'))->first();
        $workspace = $page !== null ? Workspace::query()->find($page->workspace_id) : null;

        return $page !== null && $workspace !== null ? [$workspace, $page] : null;
    }

    /** ¿El plan incluye la captación (M21)? Sin ella la página no se muestra ni recibe datos. */
    public function available(Workspace $workspace): bool
    {
        return $this->entitlements->allows($workspace, 'onsite');
    }

    /** true si pasó el límite (y cuenta el intento). */
    public function limited(Request $request, string $bucket, int $max, int $decay = 60, string $scope = ''): bool
    {
        $key = 'igr-capture:'.$bucket.':'.($scope !== '' ? $scope : hash_hmac('sha256', (string) $request->ip(), (string) config('app.key')));
        if (RateLimiter::tooManyAttempts($key, $max)) {
            return true;
        }
        RateLimiter::hit($key, $decay);

        return false;
    }

    /** @param array<string, mixed> $data */
    public function view(string $view, ?Workspace $workspace, ?LandingPage $page, array $data, int $status = 200): Response
    {
        $nonce = Str::random(24);
        $indexable = $page !== null && $page->isPublished() && (bool) $page->setting('indexable', false) && empty($data['preview']);
        $render = function () use ($view, $workspace, $page, $data, $status, $nonce, $indexable): Response {
            $profile = $workspace !== null ? BusinessProfile::for($workspace) : null;
            $brand = $workspace !== null ? $this->branding->for($workspace) : null;
            $color = $page?->text('color');
            $accent = is_string($color) && preg_match('/^#[0-9a-fA-F]{6}$/', $color) === 1 ? $color
                : ($brand !== null && preg_match('/^#[0-9a-fA-F]{6}$/', $brand->primaryColor) === 1 ? $brand->primaryColor : '#2563eb');

            return response()->view($view, $data + [
                'business' => $profile !== null ? ($profile['name'] !== '' ? $profile['name'] : (string) $workspace?->name) : 'Imagina Growth',
                'accent' => $accent,
                'accentFg' => self::readableOn($accent),
                'poweredBy' => $brand !== null ? $brand->name : 'Imagina Growth',
                'lang' => $workspace !== null ? str_replace('_', '-', WorkspaceLocale::of($workspace)) : 'es',
                'indexable' => $indexable,
                'nonce' => $nonce,
            ], $status);
        };
        $response = $workspace !== null ? WorkspaceLocale::run($workspace, $render) : $render();

        return $response->withHeaders([
            'Cache-Control' => 'no-store, private',
            'X-Robots-Tag' => $indexable ? 'all' : 'noindex, nofollow',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'X-Frame-Options' => 'SAMEORIGIN',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self' https: data:; style-src 'unsafe-inline'; script-src 'nonce-{$nonce}'; form-action 'self' https:; frame-ancestors 'self'; base-uri 'none'",
        ]);
    }

    public function missing(?Workspace $workspace = null, int $status = 404): Response
    {
        return $this->view('capture.missing', $workspace, null, ['status' => $status], $status);
    }

    /**
     * Color de texto legible sobre el color de la marca (contraste WCAG con blanco o con casi negro).
     */
    public static function readableOn(string $hex): string
    {
        $channel = function (string $h): float {
            $c = hexdec($h) / 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        };
        $l = 0.2126 * $channel(substr($hex, 1, 2)) + 0.7152 * $channel(substr($hex, 3, 2)) + 0.0722 * $channel(substr($hex, 5, 2));
        $white = 1.05 / ($l + 0.05);
        $dark = ($l + 0.05) / 0.0589; // luminancia de #0f172a + 0,05

        return $white >= $dark ? '#ffffff' : '#0f172a';
    }
}
