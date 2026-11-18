<?php

declare(strict_types=1);

namespace App\Domain\Reviews\Support;

/**
 * Borra los metadatos de usuario de un MP4 o QuickTime (ISO BMFF) sin cambiar
 * el tamaño del archivo: las cajas `udta` y `meta` de `moov` y de cada `trak`
 * (donde los teléfonos guardan la ubicación, el modelo y la fecha) pasan a ser
 * `free` y su contenido se sobrescribe con ceros. Como ningún byte se mueve, los
 * desplazamientos de `stco`/`co64` siguen apuntando bien y el video se reproduce.
 */
final class Mp4Metadata
{
    private const STRIP = ['udta', 'meta'];

    /** @return bool false si el archivo no es un ISO BMFF válido */
    public static function strip(string $path): bool
    {
        $fh = fopen($path, 'r+b');
        if ($fh === false) {
            return false;
        }
        try {
            clearstatcache(true, $path);
            $size = (int) filesize($path);
            $boxes = self::boxes($fh, 0, $size);
            if ($boxes === null || $boxes === [] || ! in_array($boxes[0]['type'], ['ftyp', 'wide', 'free', 'skip', 'mdat', 'moov'], true)) {
                return false;
            }
            $hasMoov = false;
            foreach ($boxes as $box) {
                if (in_array($box['type'], self::STRIP, true)) {
                    self::blank($fh, $box);
                }
                if ($box['type'] !== 'moov') {
                    continue;
                }
                $hasMoov = true;
                foreach (self::boxes($fh, $box['data'], $box['end']) ?? [] as $child) {
                    if (in_array($child['type'], self::STRIP, true)) {
                        self::blank($fh, $child);
                    } elseif ($child['type'] === 'trak') {
                        foreach (self::boxes($fh, $child['data'], $child['end']) ?? [] as $grand) {
                            if (in_array($grand['type'], self::STRIP, true)) {
                                self::blank($fh, $grand);
                            }
                        }
                    }
                }
            }

            return $hasMoov;
        } finally {
            fclose($fh);
        }
    }

    /**
     * Cajas hijas entre `from` y `to`.
     *
     * @param  resource  $fh
     * @return list<array{type: string, start: int, data: int, end: int}>|null null si la estructura no cuadra
     */
    private static function boxes($fh, int $from, int $to): ?array
    {
        $out = [];
        $pos = $from;
        while ($pos + 8 <= $to) {
            fseek($fh, $pos);
            $header = fread($fh, 8);
            if ($header === false || strlen($header) < 8) {
                return null;
            }
            $unpacked = unpack('Nsize', substr($header, 0, 4));
            $boxSize = (int) ($unpacked['size'] ?? 0);
            $type = substr($header, 4, 4);
            $headerLen = 8;
            if ($boxSize === 1) {
                $large = fread($fh, 8);
                if ($large === false || strlen($large) < 8) {
                    return null;
                }
                $parts = unpack('Nhi/Nlo', $large);
                $boxSize = (int) ($parts['hi'] ?? 0) * 4294967296 + (int) ($parts['lo'] ?? 0);
                $headerLen = 16;
            } elseif ($boxSize === 0) {
                $boxSize = $to - $pos;
            }
            if ($boxSize < $headerLen || $pos + $boxSize > $to || preg_match('/^[\x20-\x7E\xA9]{4}$/', $type) !== 1) {
                return null;
            }
            $out[] = ['type' => $type, 'start' => $pos, 'data' => $pos + $headerLen, 'end' => $pos + $boxSize];
            $pos += $boxSize;
        }

        return $out;
    }

    /**
     * @param  resource  $fh
     * @param  array{type: string, start: int, data: int, end: int}  $box
     */
    private static function blank($fh, array $box): void
    {
        fseek($fh, $box['start'] + 4);
        fwrite($fh, 'free');
        $remaining = $box['end'] - $box['data'];
        fseek($fh, $box['data']);
        while ($remaining > 0) {
            $chunk = min($remaining, 65536);
            fwrite($fh, str_repeat("\0", $chunk));
            $remaining -= $chunk;
        }
    }
}
