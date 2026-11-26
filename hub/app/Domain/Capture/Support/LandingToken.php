<?php

declare(strict_types=1);

namespace App\Domain\Capture\Support;

use App\Domain\Capture\Models\LandingPage;
use App\Support\Tenancy\RedisKey;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Token firmado del formulario de una página de captura, en lugar de la sesión y el CSRF (las rutas públicas no tienen
 * sesión ni cookies): `{hora}.{nonce}.{firma}`, con la firma HMAC de la clave de la app sobre la página, la hora y el
 * nonce. Vale una sola vez y por `max_hours`; un envío antes de `min_seconds` desde que se pintó la página es un robot.
 */
final class LandingToken
{
    public const OK = 'ok';

    public const INVALID = 'invalid';

    public const EXPIRED = 'expired';

    public const TOO_FAST = 'too_fast';

    public const USED = 'used';

    public static function issue(LandingPage $page, ?int $at = null): string
    {
        $at ??= now()->getTimestamp();
        $nonce = Str::random(16);

        return $at.'.'.$nonce.'.'.self::sign($page, $at, $nonce);
    }

    /** Revisa el token sin gastarlo. */
    public static function check(LandingPage $page, mixed $token): string
    {
        if (! is_string($token) || preg_match('/^(\d{9,11})\.([A-Za-z0-9]{16})\.([a-f0-9]{40})$/', $token, $m) !== 1) {
            return self::INVALID;
        }
        if (! hash_equals(self::sign($page, (int) $m[1], $m[2]), $m[3])) {
            return self::INVALID;
        }
        $age = now()->getTimestamp() - (int) $m[1];
        if ($age > (int) config('igr-capture.token.max_hours', 6) * 3600 || $age < -60) {
            return self::EXPIRED;
        }
        if ($age < (int) config('igr-capture.token.min_seconds', 2)) {
            return self::TOO_FAST;
        }

        return self::OK;
    }

    /** Gasta el token (un solo uso). Devuelve false si ya se había usado. */
    public static function spend(LandingPage $page, string $token): bool
    {
        $hours = (int) config('igr-capture.token.max_hours', 6);

        return Cache::add(RedisKey::for($page->workspace_id, 'lp:token:'.sha1($token)), 1, now()->addHours($hours + 1));
    }

    private static function sign(LandingPage $page, int $at, string $nonce): string
    {
        return substr(hash_hmac('sha256', 'landing|'.$page->id.'|'.$at.'|'.$nonce, (string) config('app.key')), 0, 40);
    }
}
