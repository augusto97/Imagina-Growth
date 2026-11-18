<?php

declare(strict_types=1);

namespace App\Ingest\Enrichment;

/**
 * Clasificación liviana del user agent (tipo, sistema y navegador). En la
 * Fase 1, con el tracker, se reemplaza por matomo/device-detector.
 */
final class DeviceDetector
{
    /** @return array{type: string, os: string, browser: string} */
    public function detect(?string $ua): array
    {
        if ($ua === null || $ua === '') {
            return ['type' => '', 'os' => '', 'browser' => ''];
        }
        $type = match (true) {
            (bool) preg_match('/iPad|Tablet|PlayBook|Silk|(Android(?!.*Mobile))/i', $ua) => 'tablet',
            (bool) preg_match('/Mobi|iPhone|iPod|Android.*Mobile|Windows Phone|Opera Mini/i', $ua) => 'mobile',
            default => 'desktop',
        };
        $os = match (true) {
            (bool) preg_match('/iPhone|iPad|iPod/i', $ua) => 'iOS',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'Windows') => 'Windows',
            (bool) preg_match('/Mac OS X|Macintosh/', $ua) => 'macOS',
            str_contains($ua, 'CrOS') => 'ChromeOS',
            str_contains($ua, 'Linux') => 'Linux',
            default => 'Otro',
        };
        $browser = match (true) {
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'OPR/') || str_contains($ua, 'Opera') => 'Opera',
            str_contains($ua, 'SamsungBrowser') => 'Samsung Internet',
            str_contains($ua, 'Firefox/') || str_contains($ua, 'FxiOS') => 'Firefox',
            str_contains($ua, 'CriOS') || str_contains($ua, 'Chrome/') => 'Chrome',
            str_contains($ua, 'Safari/') => 'Safari',
            default => 'Otro',
        };

        return ['type' => $type, 'os' => $os, 'browser' => $browser];
    }
}
