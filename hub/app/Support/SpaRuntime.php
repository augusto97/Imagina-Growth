<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Configuración de la SPA que depende del servidor y no del build (docs/despliegue.md §3):
 * el paquete de versión se compila una vez para todas las instalaciones, así que la clave y
 * el host públicos de Reverb llegan en el HTML como `window.igrRuntime`. Solo valores
 * públicos: la clave de la app de Reverb no es un secreto (el secreto es REVERB_APP_SECRET).
 */
final class SpaRuntime
{
    /** @return array{version: string, reverb: array{key: string, host: string, port: int, scheme: string}|null, registration: bool} */
    public static function config(): array
    {
        $key = (string) config('broadcasting.connections.reverb.key', '');
        $reverb = null;
        if (config('broadcasting.default') === 'reverb' && $key !== '') {
            $scheme = (string) config('broadcasting.connections.reverb.public.scheme', 'https');
            $reverb = [
                'key' => $key,
                'host' => (string) config('broadcasting.connections.reverb.public.host', ''),
                'port' => (int) config('broadcasting.connections.reverb.public.port', $scheme === 'https' ? 443 : 80),
                'scheme' => $scheme === 'http' ? 'http' : 'https',
            ];
        }

        return ['version' => AppVersion::current(), 'reverb' => $reverb, 'registration' => Registration::isOpen()];
    }

    /**
     * Las fuentes del texto que se piden junto con el CSS (el subconjunto latino de cada fuente variable del build): sin
     * esto el navegador las descubre cuando React pinta el primer texto y el texto cambia de forma al llegar
     * (docs/rendimiento.md §4). Sin build (pruebas) o con `npm run dev` no hay nada que precargar.
     *
     * @return list<string> URL de cada archivo
     */
    public static function fontPreloads(?string $manifest = null): array
    {
        $manifest ??= public_path('build/manifest.json');
        if (is_file(public_path('hot')) || ! is_file($manifest)) {
            return [];
        }
        $entries = json_decode((string) file_get_contents($manifest), true);
        $urls = [];
        foreach (is_array($entries) ? $entries : [] as $source => $entry) {
            if (is_string($source) && preg_match('#/files/[a-z0-9-]+-latin-wght-normal\.woff2$#', $source) === 1 && is_string($entry['file'] ?? null)) {
                $urls[] = asset('build/'.$entry['file']);
            }
        }

        return $urls;
    }
}
