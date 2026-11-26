<?php

declare(strict_types=1);

namespace App\Domain\Capture\Support;

use App\Support\Tenancy\RedisKey;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Contadores diarios de la captación (`capture_daily`, UTC): vistas, visitantes únicos y envíos de cada página y
 * escaneos o clics de cada chat de WhatsApp, por vía (`qr` o `link`). El visitante único se reconoce con un hash de
 * la IP que vive un día en la caché; la IP nunca se guarda. Un fallo al contar nunca rompe la página.
 */
final class CaptureCounters
{
    public const PAGE = 'page';

    public const WA_LINK = 'wa_link';

    public static function view(string $workspaceId, string $type, string $id, string $via, ?string $ip): void
    {
        $day = now()->utc()->toDateString();
        $visitor = 0;
        if ($ip !== null && $ip !== '') {
            $hash = substr(hash_hmac('sha256', $ip.'|'.$day, (string) config('app.key')), 0, 24);
            $visitor = Cache::add(RedisKey::for($workspaceId, "capture:{$type}:{$id}:{$day}:{$hash}"), 1, now()->addDay()) ? 1 : 0;
        }
        self::bump($workspaceId, $type, $id, $day, $via, ['views' => 1, 'visitors' => $visitor]);
    }

    public static function submission(string $workspaceId, string $type, string $id, string $via): void
    {
        self::bump($workspaceId, $type, $id, now()->utc()->toDateString(), $via, ['submissions' => 1]);
    }

    /** @param array<string, int> $add */
    private static function bump(string $workspaceId, string $type, string $id, string $day, string $via, array $add): void
    {
        $via = in_array($via, ['qr', 'link'], true) ? $via : 'link';
        $values = ['views' => 0, 'visitors' => 0, 'submissions' => 0];
        foreach ($add as $column => $n) {
            $values[$column] = $n;
        }
        try {
            DB::statement(
                'INSERT INTO capture_daily (workspace_id, subject_type, subject_id, day, via, views, visitors, submissions) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE views = views + VALUES(views), visitors = visitors + VALUES(visitors), submissions = submissions + VALUES(submissions)',
                [$workspaceId, $type, $id, $day, $via, $values['views'], $values['visitors'], $values['submissions']],
            );
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Totales y serie diaria de un sujeto desde `$from` (fecha UTC), por vía.
     *
     * @return array{totals: array{views: int, visitors: int, submissions: int, qr_views: int, qr_submissions: int}, daily: list<array{day: string, views: int, visitors: int, submissions: int, qr_views: int}>}
     */
    public static function read(string $workspaceId, string $type, string $id, string $from): array
    {
        $rows = DB::table('capture_daily')->where('workspace_id', $workspaceId)->where('subject_type', $type)->where('subject_id', $id)
            ->where('day', '>=', $from)->orderBy('day')->get(['day', 'via', 'views', 'visitors', 'submissions']);
        $totals = ['views' => 0, 'visitors' => 0, 'submissions' => 0, 'qr_views' => 0, 'qr_submissions' => 0];
        $daily = [];
        foreach ($rows as $row) {
            $day = substr((string) $row->day, 0, 10);
            $daily[$day] ??= ['day' => $day, 'views' => 0, 'visitors' => 0, 'submissions' => 0, 'qr_views' => 0];
            foreach (['views', 'visitors', 'submissions'] as $column) {
                $daily[$day][$column] += (int) $row->{$column};
                $totals[$column] += (int) $row->{$column};
            }
            if ($row->via === 'qr') {
                $daily[$day]['qr_views'] += (int) $row->views;
                $totals['qr_views'] += (int) $row->views;
                $totals['qr_submissions'] += (int) $row->submissions;
            }
        }

        return ['totals' => $totals, 'daily' => array_values($daily)];
    }

    /**
     * Totales de varios sujetos de una vez (la lista del panel).
     *
     * @param  list<string>  $ids
     * @return array<string, array{views: int, submissions: int, qr_views: int}>
     */
    public static function summary(string $workspaceId, string $type, array $ids, string $from): array
    {
        if ($ids === []) {
            return [];
        }
        $out = [];
        $rows = DB::table('capture_daily')->where('workspace_id', $workspaceId)->where('subject_type', $type)->whereIn('subject_id', $ids)
            ->where('day', '>=', $from)->groupBy('subject_id', 'via')
            ->selectRaw('subject_id, via, SUM(views) AS views, SUM(submissions) AS submissions')->get();
        foreach ($rows as $row) {
            $out[$row->subject_id] ??= ['views' => 0, 'submissions' => 0, 'qr_views' => 0];
            $out[$row->subject_id]['views'] += (int) $row->views;
            $out[$row->subject_id]['submissions'] += (int) $row->submissions;
            if ($row->via === 'qr') {
                $out[$row->subject_id]['qr_views'] += (int) $row->views;
            }
        }

        return $out;
    }
}
