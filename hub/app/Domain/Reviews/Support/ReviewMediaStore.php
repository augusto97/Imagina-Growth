<?php

declare(strict_types=1);

namespace App\Domain\Reviews\Support;

use App\Domain\Reviews\Models\Review;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Fotos y videos de las reseñas (M15). Se guardan en un disco privado con
 * nombres aleatorios (`reviews/{workspace}/{reseña}/{nombre}`) y se sirven por
 * /r/m/{reseña}/{nombre} solo mientras la reseña está publicada, o con una URL
 * firmada y temporal para la moderación.
 *
 * - **Fotos:** se validan por contenido (no por la extensión), se rechazan las
 *   de más de `max_pixels`, se enderezan con la orientación EXIF, se reducen y
 *   se vuelven a codificar en JPEG con GD. Volver a codificar descarta todos los
 *   metadatos (EXIF, GPS, XMP). Se genera una miniatura cuadrada. Sin GD no se
 *   aceptan fotos: no se podrían limpiar.
 * - **Videos:** MP4 o QuickTime. Los metadatos de usuario (`udta` y `meta` en
 *   `moov` y en cada `trak`, donde los teléfonos guardan la ubicación) se
 *   convierten en cajas `free` y su contenido se borra con ceros, sin mover
 *   ningún byte del resto del archivo (los desplazamientos de `stco` siguen
 *   valiendo). No tienen miniatura.
 */
final class ReviewMediaStore
{
    public static function supportsImages(): bool
    {
        return function_exists('imagecreatetruecolor') && function_exists('imagejpeg') && function_exists('imagecreatefromstring');
    }

    public function disk(): Filesystem
    {
        return Storage::disk((string) config('igr-reviews.disk', 'local'));
    }

    public static function dir(string $workspaceId, string $reviewId): string
    {
        return 'reviews/'.$workspaceId.'/'.$reviewId;
    }

    /**
     * Procesa y guarda un archivo subido.
     *
     * @return array{key: string, type: string, mime: string, size: int, width?: int, height?: int, thumb_key?: string}
     *
     * @throws InvalidReviewMedia
     */
    public function store(UploadedFile $file, string $workspaceId, string $reviewId): array
    {
        $path = $file->getRealPath();
        if ($path === false || ! is_file($path)) {
            throw new InvalidReviewMedia(__('No pudimos leer el archivo.'));
        }
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        $cfg = (array) config('igr-reviews.uploads');

        if (in_array($mime, (array) $cfg['image_mimes'], true)) {
            if (! self::supportsImages()) {
                throw new InvalidReviewMedia(__('Por ahora no podemos recibir fotos.'));
            }
            if ($file->getSize() > (int) $cfg['image_max_kb'] * 1024) {
                throw new InvalidReviewMedia(__('La foto pesa más de :mb MB.', ['mb' => intdiv((int) $cfg['image_max_kb'], 1024)]));
            }

            return $this->storeImage($path, $mime, $workspaceId, $reviewId, $cfg);
        }
        if (in_array($mime, (array) $cfg['video_mimes'], true)) {
            if ($file->getSize() > (int) $cfg['video_max_kb'] * 1024) {
                throw new InvalidReviewMedia(__('El video pesa más de :mb MB.', ['mb' => intdiv((int) $cfg['video_max_kb'], 1024)]));
            }

            return $this->storeVideo($path, $mime, $workspaceId, $reviewId);
        }

        throw new InvalidReviewMedia(__('Solo se aceptan fotos (JPG, PNG o WebP) y videos (MP4 o MOV).'));
    }

    /** URL pública del archivo (responde solo si la reseña está publicada). */
    public static function url(Review $review, string $key): string
    {
        return ReviewToken::base().'/r/m/'.$review->id.'/'.$key;
    }

    /** URL firmada de 30 minutos para ver un archivo en la moderación. */
    public static function signedUrl(Review $review, string $key): string
    {
        return URL::temporarySignedRoute('reviews.media', now()->addMinutes(30), ['review' => $review->id, 'name' => $key]);
    }

    /**
     * Medios de una reseña para la API (url, tipo y miniatura).
     *
     * @return list<array{url: string, type: string, thumb: string|null}>
     */
    public static function present(Review $review, bool $signed = false): array
    {
        $out = [];
        foreach ($review->media ?? [] as $m) {
            if (! is_array($m)) {
                continue;
            }
            $type = ($m['type'] ?? 'image') === 'video' ? 'video' : 'image';
            if (is_string($m['url'] ?? null) && ! is_string($m['key'] ?? null)) {
                // Importada: la URL es la de origen (Judge.me, CSV).
                $out[] = ['url' => (string) $m['url'], 'type' => $type, 'thumb' => is_string($m['thumb'] ?? null) ? $m['thumb'] : null];

                continue;
            }
            if (! is_string($m['key'] ?? null)) {
                continue;
            }
            $link = fn (string $key) => $signed ? self::signedUrl($review, $key) : self::url($review, $key);
            $out[] = ['url' => $link($m['key']), 'type' => $type, 'thumb' => is_string($m['thumb_key'] ?? null) ? $link($m['thumb_key']) : null];
        }

        return $out;
    }

    /** Borra los archivos de una reseña. */
    public function deleteFor(Review $review): void
    {
        $this->disk()->deleteDirectory(self::dir($review->workspace_id, $review->id));
    }

    /**
     * @param  array<string, mixed>  $cfg
     * @return array{key: string, type: string, mime: string, size: int, width: int, height: int, thumb_key: string}
     */
    private function storeImage(string $path, string $mime, string $workspaceId, string $reviewId, array $cfg): array
    {
        $info = @getimagesize($path);
        if ($info === false || $info[0] < 1 || $info[1] < 1) {
            throw new InvalidReviewMedia(__('La foto está dañada o no es una imagen.'));
        }
        if ((int) $cfg['max_pixels'] < $info[0] * $info[1]) {
            throw new InvalidReviewMedia(__('La foto es demasiado grande.'));
        }
        $raw = (string) file_get_contents($path);
        $src = @imagecreatefromstring($raw);
        if ($src === false) {
            throw new InvalidReviewMedia(__('La foto está dañada o no es una imagen.'));
        }
        $src = $this->orient($src, $mime === 'image/jpeg' ? $path : null);

        $name = self::name();
        $full = $this->fit($src, (int) $cfg['image_max_side']);
        $thumb = $this->square($src, (int) $cfg['thumb_side']);
        imagedestroy($src);
        $bytes = $this->jpeg($full, (int) $cfg['jpeg_quality']);
        $width = imagesx($full);
        $height = imagesy($full);
        imagedestroy($full);
        $thumbBytes = $this->jpeg($thumb, (int) $cfg['jpeg_quality']);
        imagedestroy($thumb);

        $dir = self::dir($workspaceId, $reviewId);
        if (! $this->disk()->put($dir.'/'.$name.'.jpg', $bytes) || ! $this->disk()->put($dir.'/'.$name.'_t.jpg', $thumbBytes)) {
            throw new RuntimeException('No se pudo guardar la foto de la reseña.');
        }

        return ['key' => $name.'.jpg', 'type' => 'image', 'mime' => 'image/jpeg', 'size' => strlen($bytes), 'width' => $width, 'height' => $height, 'thumb_key' => $name.'_t.jpg'];
    }

    /** @return array{key: string, type: string, mime: string, size: int} */
    private function storeVideo(string $path, string $mime, string $workspaceId, string $reviewId): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'igrv');
        if ($tmp === false || ! copy($path, $tmp)) {
            throw new RuntimeException('No se pudo copiar el video de la reseña.');
        }
        try {
            if (! Mp4Metadata::strip($tmp)) {
                throw new InvalidReviewMedia(__('El video está dañado o no es un MP4 o MOV.'));
            }
            $ext = $mime === 'video/quicktime' ? 'mov' : 'mp4';
            $name = self::name().'.'.$ext;
            $stream = fopen($tmp, 'rb');
            if ($stream === false || ! $this->disk()->writeStream(self::dir($workspaceId, $reviewId).'/'.$name, $stream)) {
                throw new RuntimeException('No se pudo guardar el video de la reseña.');
            }
            if (is_resource($stream)) {
                fclose($stream);
            }

            return ['key' => $name, 'type' => 'video', 'mime' => $mime, 'size' => (int) filesize($tmp)];
        } finally {
            @unlink($tmp);
        }
    }

    private static function name(): string
    {
        return Str::lower(Str::random(32));
    }

    /** Endereza la foto según la orientación EXIF (antes de descartar los metadatos). */
    private function orient(\GdImage $img, ?string $jpegPath): \GdImage
    {
        if ($jpegPath === null || ! function_exists('exif_read_data')) {
            return $img;
        }
        $exif = @exif_read_data($jpegPath);
        $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;
        $rotated = match ($orientation) {
            3 => imagerotate($img, 180, 0),
            6 => imagerotate($img, -90, 0),
            8 => imagerotate($img, 90, 0),
            default => $img,
        };
        if ($rotated === false) {
            return $img;
        }
        if (in_array($orientation, [2, 4, 5, 7], true)) {
            imageflip($rotated, $orientation === 4 ? IMG_FLIP_VERTICAL : IMG_FLIP_HORIZONTAL);
        }

        return $rotated;
    }

    private function fit(\GdImage $src, int $maxSide): \GdImage
    {
        $w = imagesx($src);
        $h = imagesy($src);
        $scale = min(1.0, $maxSide / max($w, $h));
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));

        return $this->copy($src, 0, 0, $w, $h, $nw, $nh);
    }

    private function square(\GdImage $src, int $side): \GdImage
    {
        $w = imagesx($src);
        $h = imagesy($src);
        $crop = min($w, $h);
        $target = min($side, $crop);

        return $this->copy($src, intdiv($w - $crop, 2), intdiv($h - $crop, 2), $crop, $crop, $target, $target);
    }

    private function copy(\GdImage $src, int $sx, int $sy, int $sw, int $sh, int $dw, int $dh): \GdImage
    {
        $dst = imagecreatetruecolor(max(1, $dw), max(1, $dh));
        if ($dst === false) {
            throw new RuntimeException('GD no pudo crear la imagen.');
        }
        // Fondo blanco: las transparencias de PNG y WebP no existen en JPEG.
        imagefill($dst, 0, 0, (int) imagecolorallocate($dst, 255, 255, 255));
        imagecopyresampled($dst, $src, 0, 0, $sx, $sy, max(1, $dw), max(1, $dh), $sw, $sh);

        return $dst;
    }

    private function jpeg(\GdImage $img, int $quality): string
    {
        ob_start();
        imageinterlace($img, true);
        imagejpeg($img, null, $quality);

        return (string) ob_get_clean();
    }
}
