<?php

declare(strict_types=1);

namespace App\Domain\Capture\Support;

use App\Domain\Capture\Models\LandingPage;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * El logo y la imagen principal de una página de captura, para el negocio que no tiene dónde alojarlos. Se validan
 * por contenido, se reducen y se vuelven a codificar con GD (PNG si tienen transparencia, si no JPEG): volver a
 * codificar descarta todos los metadatos (EXIF, GPS). Se guardan con un nombre al azar en el disco
 * `igr-capture.disk` (`capture/{espacio}/{página}/{nombre}`) y se sirven por `/p/{slug}/i/{nombre}` mientras la
 * página no esté archivada.
 */
final class LandingImages
{
    public const KINDS = ['logo', 'image'];

    private const MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    public static function supported(): bool
    {
        return function_exists('imagecreatefromstring') && function_exists('imagejpeg') && function_exists('imagepng');
    }

    public function disk(): Filesystem
    {
        return Storage::disk((string) config('igr-capture.disk', 'local'));
    }

    public static function dir(LandingPage $page): string
    {
        return 'capture/'.$page->workspace_id.'/'.$page->id;
    }

    /**
     * @return string el nombre guardado (va en `content.logo_key` o `content.image_key`)
     *
     * @throws InvalidArgumentException con el mensaje para la persona
     */
    public function store(LandingPage $page, UploadedFile $file, string $kind): string
    {
        $cfg = (array) config('igr-capture.images');
        $path = $file->getRealPath();
        if (! self::supported() || $path === false || ! is_file($path)) {
            throw new InvalidArgumentException(__('No pudimos leer la imagen.'));
        }
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        if (! in_array($mime, self::MIMES, true)) {
            throw new InvalidArgumentException(__('Sube una imagen JPG, PNG o WebP.'));
        }
        if ((int) $file->getSize() > (int) $cfg['max_kb'] * 1024) {
            throw new InvalidArgumentException(__('La imagen pesa más de :mb MB.', ['mb' => intdiv((int) $cfg['max_kb'], 1024)]));
        }
        $info = @getimagesize($path);
        if ($info === false || $info[0] < 1 || $info[1] < 1 || (int) $cfg['max_pixels'] < $info[0] * $info[1]) {
            throw new InvalidArgumentException(__('La imagen está dañada o es demasiado grande.'));
        }
        $src = @imagecreatefromstring((string) file_get_contents($path));
        if ($src === false) {
            throw new InvalidArgumentException(__('La imagen está dañada o es demasiado grande.'));
        }
        $img = self::fit($src, (int) ($kind === 'logo' ? $cfg['logo_max_side'] : $cfg['hero_max_side']));
        $alpha = $mime !== 'image/jpeg' && self::hasAlpha($img);
        ob_start();
        $alpha ? imagepng($img, null, 8) : imagejpeg($img, null, (int) $cfg['jpeg_quality']);
        $bytes = (string) ob_get_clean();
        imagedestroy($img);
        $name = $kind.'-'.Str::lower(Str::random(24)).($alpha ? '.png' : '.jpg');
        if (! $this->disk()->put(self::dir($page).'/'.$name, $bytes)) {
            throw new RuntimeException('No se pudo guardar la imagen de la página de captura.');
        }

        return $name;
    }

    public function delete(LandingPage $page, ?string $name): void
    {
        if ($name !== null && self::validName($name)) {
            $this->disk()->delete(self::dir($page).'/'.$name);
        }
    }

    public static function validName(string $name): bool
    {
        return preg_match('/^(logo|image)-[a-z0-9]{24}\.(jpg|png)$/', $name) === 1;
    }

    private static function fit(\GdImage $src, int $maxSide): \GdImage
    {
        $w = imagesx($src);
        $h = imagesy($src);
        $scale = min(1, $maxSide / max($w, $h));
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));
        $dst = imagecreatetruecolor($nw, $nh);
        if ($dst === false) {
            return $src;
        }
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagefill($dst, 0, 0, (int) imagecolorallocatealpha($dst, 0, 0, 0, 127));
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($src);

        return $dst;
    }

    /** ¿Tiene algún pixel transparente? (se muestrea una rejilla para no recorrer toda la imagen). */
    private static function hasAlpha(\GdImage $img): bool
    {
        $w = imagesx($img);
        $h = imagesy($img);
        $step = max(1, (int) floor(min($w, $h) / 40));
        for ($x = 0; $x < $w; $x += $step) {
            for ($y = 0; $y < $h; $y += $step) {
                if (((imagecolorat($img, $x, $y) >> 24) & 0x7F) > 0) {
                    return true;
                }
            }
        }

        return false;
    }
}
