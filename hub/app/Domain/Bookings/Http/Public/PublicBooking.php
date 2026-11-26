<?php

declare(strict_types=1);

namespace App\Domain\Bookings\Http\Public;

use App\Domain\Billing\Contracts\Entitlements;
use App\Domain\Bookings\Support\BookingSettings;
use App\Domain\Capture\Http\Public\PublicCapture;
use App\Domain\Sites\Models\Site;
use App\Domain\Templates\Support\BusinessProfile;
use App\Domain\Workspaces\Contracts\Branding;
use App\Domain\Workspaces\Models\Workspace;
use App\Ingest\Http\SiteResolver;
use App\Support\Ids\PrefixedId;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lo común de las páginas públicas de la agenda (M18): el negocio por la clave
 * pública de su sitio (`pk_…`) o por el ID público del espacio de trabajo
 * (`ws_…`), la marca de la página y los límites por IP con hash.
 */
final class PublicBooking
{
    public function __construct(private readonly SiteResolver $sites, private readonly Branding $branding) {}

    /**
     * ¿El plan del negocio incluye la agenda (M21)? Sin ella no se ofrecen horarios ni se
     * reciben reservas nuevas; las citas que ya existen se pueden seguir viendo y cambiando.
     */
    public function bookable(Workspace $workspace): bool
    {
        // Se resuelve en cada llamada: el controlador queda en caché en la ruta.
        return app(Entitlements::class)->allows($workspace, 'bookings');
    }

    /** @return array{0: Workspace, 1: Site|null}|null */
    public function resolve(string $key): ?array
    {
        if (str_starts_with($key, 'pk_')) {
            $resolved = $this->sites->byPublicKey($key);
            if ($resolved === null || ! $resolved[0]->isActive()) {
                return null;
            }
            $workspace = Workspace::query()->find($resolved[0]->workspace_id);

            return $workspace === null ? null : [$workspace, $resolved[0]];
        }
        $id = PrefixedId::decode(Workspace::ID_PREFIX, $key);
        $workspace = $id !== null ? Workspace::query()->find($id) : null;

        return $workspace === null ? null : [$workspace, BookingSettings::site($workspace)];
    }

    /** true si pasó el límite (y cuenta el intento). */
    public function limited(Request $request, string $bucket, int $perMinute): bool
    {
        $key = 'igr-booking:'.$bucket.':'.hash_hmac('sha256', (string) $request->ip(), (string) config('app.key'));
        if (RateLimiter::tooManyAttempts($key, $perMinute)) {
            return true;
        }
        RateLimiter::hit($key, 60);

        return false;
    }

    /** @param  array<string, mixed>  $data */
    public function view(string $view, ?Workspace $workspace, array $data, int $status = 200): Response
    {
        $profile = $workspace !== null ? BusinessProfile::for($workspace) : null;
        $brand = $workspace !== null ? $this->branding->for($workspace) : null;
        $business = $profile !== null ? ($profile['name'] !== '' ? $profile['name'] : (string) $workspace?->name) : 'Imagina Growth';
        $accent = self::accent($workspace, $brand?->primaryColor);

        return response()->view($view, $data + [
            'business' => $business,
            'initial' => mb_strtoupper(mb_substr(trim($business), 0, 1)) ?: 'A',
            'logo' => $profile !== null && str_starts_with($profile['logo_url'], 'https://') ? $profile['logo_url'] : null,
            'accent' => $accent,
            'accentFg' => PublicCapture::readableOn($accent),
            'poweredBy' => $brand !== null ? $brand->name : 'Imagina Growth',
            'zone' => self::zone($workspace?->timezone ?: 'America/Bogota'),
        ], $status)->withHeaders([
            'Cache-Control' => 'no-store, private',
            'X-Robots-Tag' => 'noindex',
            'Referrer-Policy' => 'no-referrer',
            'X-Frame-Options' => 'SAMEORIGIN',
        ]);
    }

    /**
     * El color de la página: el que eligió el negocio en la agenda, si no el de la marca (la agencia con marca blanca)
     * y si no el azul de siempre.
     */
    public static function accent(?Workspace $workspace, ?string $brandColor): string
    {
        $own = $workspace !== null ? BookingSettings::for($workspace)['color'] : null;
        foreach ([$own, $brandColor] as $color) {
            if (is_string($color) && preg_match('/^#[0-9a-fA-F]{6}$/', $color) === 1) {
                return $color;
            }
        }

        return '#2563eb';
    }

    public function missing(?Workspace $workspace = null, int $status = 404): Response
    {
        return $this->view('bookings.missing', $workspace, [], $status);
    }

    /** «Bogotá», «Ciudad de México»: la ciudad de la zona horaria, para decir en qué hora están los horarios. */
    public static function zone(string $timezone): string
    {
        $city = str_replace('_', ' ', (string) last(explode('/', $timezone)));

        return match ($city) {
            'Bogota' => 'Bogotá', 'Mexico City' => 'Ciudad de México', 'Merida' => 'Mérida', 'Cancun' => 'Cancún',
            default => $city,
        };
    }

    /** Fecha local para mostrar: «jueves 20 de noviembre». */
    public static function day(CarbonImmutable $at, string $timezone): string
    {
        return self::es($at->setTimezone($timezone))->isoFormat('dddd D [de] MMMM');
    }

    /** La fecha con los nombres de días y meses en español. */
    public static function es(CarbonImmutable $at): CarbonImmutable
    {
        $local = $at->locale('es');

        return $local instanceof CarbonImmutable ? $local : $at;
    }

    public static function time(CarbonImmutable $at, string $timezone): string
    {
        return $at->setTimezone($timezone)->format('g:i a');
    }
}
