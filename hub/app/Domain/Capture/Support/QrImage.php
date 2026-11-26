<?php

declare(strict_types=1);

namespace App\Domain\Capture\Support;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use BaconQrCode\Renderer\GDLibRenderer;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * QR para imprimir (bacon/bacon-qr-code): SVG (vectorial, para la imprenta) y PNG con GD. Corrección de errores
 * media (M): aguanta un poco de suciedad o un doblez en el papel del local sin volverse ilegible.
 */
final class QrImage
{
    public const FORMATS = ['svg', 'png'];

    public const MIN_SIZE = 128;

    public const MAX_SIZE = 2048;

    public static function svg(string $url, int $size = 512): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle(self::size($size), 2), new SvgImageBackEnd));

        return $writer->writeString($url, Encoder::DEFAULT_BYTE_MODE_ENCODING, ErrorCorrectionLevel::M());
    }

    public static function png(string $url, int $size = 1024): string
    {
        $writer = new Writer(new GDLibRenderer(self::size($size), 2));

        return $writer->writeString($url, Encoder::DEFAULT_BYTE_MODE_ENCODING, ErrorCorrectionLevel::M());
    }

    public static function render(string $format, string $url, ?int $size = null): string
    {
        return $format === 'png' ? self::png($url, $size ?? 1024) : self::svg($url, $size ?? 512);
    }

    public static function mime(string $format): string
    {
        return $format === 'png' ? 'image/png' : 'image/svg+xml';
    }

    private static function size(int $size): int
    {
        return max(self::MIN_SIZE, min(self::MAX_SIZE, $size));
    }
}
