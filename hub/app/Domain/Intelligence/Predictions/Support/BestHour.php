<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Predictions\Support;

use App\Domain\Consent\Policy\LegalWindow;
use App\Domain\Intelligence\Predictions\Models\PredictionRun;
use Illuminate\Support\Facades\Cache;

/**
 * Mejor hora de envío (inteligencia.md §4.7) para campañas y flujos: la del
 * contacto (`contacts.preferred_hour`, con 5 o más interacciones) o, si no hay,
 * la mejor del espacio de trabajo para ese canal (`best_hour` en `prediction_runs`).
 * Siempre es una hora dentro de la ventana legal de lunes a viernes del país.
 */
final class BestHour
{
    public static function forContact(string $workspaceId, ?int $preferredHour, string $channel): ?int
    {
        return $preferredHour ?? self::workspaceHour($workspaceId, $channel);
    }

    public static function workspaceHour(string $workspaceId, string $channel): ?int
    {
        $hours = Cache::remember('ws:'.$workspaceId.':best_hours', 600, function () use ($workspaceId): array {
            $run = PredictionRun::query()->withoutGlobalScopes()->where('workspace_id', $workspaceId)->where('model', 'best_hour')
                ->where('status', 'completed')->orderByDesc('trained_at')->first();

            return (array) ($run?->params['channels'] ?? []);
        });
        $hour = $hours[$channel] ?? ($hours['all'] ?? null);

        return is_numeric($hour) ? (int) $hour : null;
    }

    /**
     * Horas (0–23) en que se puede enviar marketing de lunes a viernes en ese país.
     *
     * @return list<int>
     */
    public static function allowedHours(string $country): array
    {
        $window = LegalWindow::windowsFor($country)[1] ?? ['07:00', '19:00'];
        $start = (int) substr($window[0], 0, 2);
        $end = (int) substr($window[1], 0, 2);

        return range($start, max($start, $end - 1));
    }

    /**
     * La hora de más peso que cae en la ventana legal.
     *
     * @param  array<int, float>  $histogram  hora → peso
     */
    public static function pick(array $histogram, string $country): ?int
    {
        $allowed = array_flip(self::allowedHours($country));
        $best = null;
        $bestWeight = 0.0;
        foreach ($histogram as $hour => $weight) {
            if (isset($allowed[$hour]) && $weight > $bestWeight) {
                $best = (int) $hour;
                $bestWeight = $weight;
            }
        }

        return $best;
    }
}
