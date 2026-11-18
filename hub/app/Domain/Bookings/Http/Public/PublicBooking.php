<?php

declare(strict_types=1);

namespace App\Domain\Bookings\Http\Public;

use App\Domain\Bookings\Support\BookingSettings;
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

        return response()->view($view, $data + [
            'business' => $profile !== null ? ($profile['name'] !== '' ? $profile['name'] : (string) $workspace?->name) : 'Imagina Growth',
            'logo' => $profile !== null && $profile['logo_url'] !== '' ? $profile['logo_url'] : null,
            'accent' => $brand !== null && preg_match('/^#[0-9a-fA-F]{6}$/', $brand->primaryColor) ? $brand->primaryColor : '#2563eb',
            'poweredBy' => $brand !== null ? $brand->name : 'Imagina Growth',
            'zone' => self::zone($workspace?->timezone ?: 'America/Bogota'),
        ], $status)->withHeaders([
            'Cache-Control' => 'no-store, private',
            'X-Robots-Tag' => 'noindex',
            'Referrer-Policy' => 'no-referrer',
            'X-Frame-Options' => 'SAMEORIGIN',
        ]);
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
