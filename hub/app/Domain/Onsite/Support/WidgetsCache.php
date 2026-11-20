<?php

declare(strict_types=1);

namespace App\Domain\Onsite\Support;

use App\Domain\Sites\Models\Site;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * Caché de `GET /p/{key}/widgets.json` (R11): cada página del sitio lo pide, y armarlo consulta widgets, quiz,
 * encuesta y textos legales. Se guarda 5 minutos por sitio, tipo de página y ruta, con una versión por espacio de
 * trabajo que cambia al guardar o borrar un widget, un quiz, una encuesta o un texto legal (observadores en
 * `Onsite\ModuleServiceProvider`): un cambio se ve en la siguiente petición. Un widget programado
 * (`starts_at`/`ends_at`) puede tardar hasta 5 minutos en aparecer o irse, lo mismo que la caché del CDN.
 * Si la caché no responde, se arma en el momento.
 */
final class WidgetsCache
{
    public const TTL = 300;

    public static function bump(string $workspaceId): void
    {
        if ($workspaceId === '') {
            return;
        }
        try {
            Cache::forever(self::versionKey($workspaceId), (string) Str::ulid());
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * @param  Closure(): array<string, mixed>  $build
     * @return array<string, mixed>
     */
    public static function remember(Site $site, ?string $pageType, ?string $path, Closure $build): array
    {
        $key = fn (): string => 'ws:'.$site->workspace_id.':widgets:'.self::version($site->workspace_id).':'.$site->id.':'.sha1(($pageType ?? '').'|'.($path ?? ''));
        try {
            $hit = Cache::get($key());
        } catch (Throwable) {
            return $build();
        }
        if (is_array($hit)) {
            return $hit;
        }
        $value = $build();
        try {
            // La versión se vuelve a leer: armar la respuesta puede crear los textos legales por defecto (y renovarla).
            Cache::put($key(), $value, self::TTL);
        } catch (Throwable) {
            // Sin caché, la próxima petición la arma otra vez.
        }

        return $value;
    }

    private static function version(string $workspaceId): string
    {
        $version = Cache::get(self::versionKey($workspaceId));

        return is_string($version) ? $version : '0';
    }

    private static function versionKey(string $workspaceId): string
    {
        return 'ws:'.$workspaceId.':widgets:version';
    }
}
