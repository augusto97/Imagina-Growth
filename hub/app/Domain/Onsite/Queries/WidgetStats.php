<?php

declare(strict_types=1);

namespace App\Domain\Onsite\Queries;

use App\Analytics\Ch;
use App\Domain\Onsite\Models\OnsiteWidget;
use App\Domain\Workspaces\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Estadísticas de los widgets (M12) por widget y variante: vistas (`widget.shown` en
 * ClickHouse), envíos y contactos nuevos (`widget_captures`), y pedidos, compradores
 * e ingresos atribuidos (`orders.attributed_widget_id`, paso 4 de la atribución).
 * Con dos o más variantes compara la tasa de envío con una prueba de dos
 * proporciones. Debe correr dentro del contexto del espacio de trabajo.
 *
 * @phpstan-type Row array{views: int, visitors: int, submissions: int, new_contacts: int, known_contacts: int, orders: int, buyers: int, revenue: string, submit_rate: float|null, conversion_rate: float|null}
 */
final class WidgetStats
{
    /** Mínimo de vistas por variante para declarar ganadora. */
    public const MIN_VIEWS = 100;

    /**
     * Totales por widget para la lista.
     *
     * @param  list<string>  $widgetIds  IDs internos
     * @return array<string, Row>
     */
    public function summary(Workspace $workspace, array $widgetIds, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = $this->rows($workspace, $widgetIds, $from, $to);
        $out = [];
        foreach ($widgetIds as $id) {
            $out[$id] = $this->finish($this->sum(array_values($rows[$id] ?? [])));
        }

        return $out;
    }

    /**
     * Detalle de un widget: totales, variantes, serie diaria y A/B.
     *
     * @return array<string, mixed>
     */
    public function detail(Workspace $workspace, OnsiteWidget $widget, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = $this->rows($workspace, [$widget->id], $from, $to)[$widget->id] ?? [];
        $keys = array_values(array_unique(array_merge(array_map(fn (array $v) => (string) $v['key'], (array) ($widget->variants ?? [])) ?: ['a'], array_keys($rows))));
        $variants = [];
        foreach ($keys as $key) {
            $variants[] = ['key' => $key] + $this->finish($rows[$key] ?? $this->empty());
        }

        return [
            'range' => ['from' => $from->toIso8601String(), 'to' => $to->toIso8601String()],
            'totals' => $this->finish($this->sum(array_values($rows))),
            'variants' => $variants,
            'daily' => $this->daily($workspace, $widget, $from, $to),
            'ab' => count($variants) > 1 ? self::abTest($variants) : null,
        ];
    }

    /**
     * Comparación A/B por tasa de envío (envíos / vistas): la variante líder contra la
     * segunda, con una prueba z de dos proporciones (confianza bilateral).
     *
     * @param  list<array<string, mixed>>  $variants
     * @return array{leader: string|null, runner_up: string|null, lift: float|null, confidence: float|null, significant: bool, enough_data: bool}
     */
    public static function abTest(array $variants): array
    {
        $rated = array_values(array_filter($variants, fn (array $v) => (int) $v['views'] > 0));
        usort($rated, fn (array $a, array $b) => ((int) $b['submissions'] / (int) $b['views']) <=> ((int) $a['submissions'] / (int) $a['views']));
        if (count($rated) < 2) {
            return ['leader' => null, 'runner_up' => null, 'lift' => null, 'confidence' => null, 'significant' => false, 'enough_data' => false];
        }
        [$a, $b] = [$rated[0], $rated[1]];
        $n1 = (int) $a['views'];
        $n2 = (int) $b['views'];
        $p1 = (int) $a['submissions'] / $n1;
        $p2 = (int) $b['submissions'] / $n2;
        $p = ((int) $a['submissions'] + (int) $b['submissions']) / ($n1 + $n2);
        $se = sqrt($p * (1 - $p) * (1 / $n1 + 1 / $n2));
        $z = $se > 0 ? ($p1 - $p2) / $se : 0.0;
        $confidence = round(1 - 2 * (1 - self::normalCdf(abs($z))), 4);
        $enough = $n1 >= self::MIN_VIEWS && $n2 >= self::MIN_VIEWS;

        return [
            'leader' => (string) $a['key'],
            'runner_up' => (string) $b['key'],
            'lift' => $p2 > 0 ? round(($p1 - $p2) / $p2, 4) : null,
            'confidence' => $confidence,
            'significant' => $enough && $confidence >= 0.95 && $p1 > $p2,
            'enough_data' => $enough,
        ];
    }

    private static function normalCdf(float $x): float
    {
        // Aproximación de Abramowitz y Stegun (error < 7.5e-8).
        $t = 1 / (1 + 0.2316419 * $x);
        $d = 0.3989422804014327 * exp(-$x * $x / 2);
        $p = $d * $t * (0.319381530 + $t * (-0.356563782 + $t * (1.781477937 + $t * (-1.821255978 + $t * 1.330274429))));

        return 1 - $p;
    }

    /**
     * @param  list<string>  $widgetIds
     * @return array<string, array<string, array<string, int|string>>> widget → variante → contadores
     */
    private function rows(Workspace $workspace, array $widgetIds, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $out = [];
        if ($widgetIds === []) {
            return $out;
        }
        $slot = function (string $widget, ?string $variant) use (&$out): string {
            $variant = $variant !== null && $variant !== '' ? $variant : 'a';
            $out[$widget][$variant] ??= $this->empty();

            return $variant;
        };

        foreach ($this->views($workspace, $widgetIds, $from, $to) as $row) {
            $v = $slot($row['widget'], $row['variant']);
            $out[$row['widget']][$v]['views'] = $row['views'];
            $out[$row['widget']][$v]['visitors'] = $row['visitors'];
        }
        $captures = DB::table('widget_captures')->where('workspace_id', $workspace->id)->whereIn('widget_id', $widgetIds)
            ->whereBetween('captured_at', [$from, $to])->groupBy('widget_id', 'variant')
            ->selectRaw('widget_id, variant, COUNT(*) AS n, SUM(CASE WHEN was_known = 0 THEN 1 ELSE 0 END) AS fresh')->get();
        foreach ($captures as $row) {
            $v = $slot((string) $row->widget_id, $row->variant !== null ? (string) $row->variant : null);
            $out[(string) $row->widget_id][$v]['submissions'] = (int) $row->n;
            $out[(string) $row->widget_id][$v]['new_contacts'] = (int) $row->fresh;
            $out[(string) $row->widget_id][$v]['known_contacts'] = (int) $row->n - (int) $row->fresh;
        }
        $orders = DB::table('orders')->where('workspace_id', $workspace->id)->whereIn('attributed_widget_id', $widgetIds)
            ->where('is_paid', true)->whereBetween('created_at', [$from, $to])->groupBy('attributed_widget_id', 'attributed_widget_variant')
            ->selectRaw('attributed_widget_id AS widget_id, attributed_widget_variant AS variant, COUNT(*) AS n, COUNT(DISTINCT contact_id) AS buyers, COALESCE(SUM(COALESCE(total_ws, total) - refunded), 0) AS revenue')->get();
        foreach ($orders as $row) {
            $v = $slot((string) $row->widget_id, $row->variant !== null ? (string) $row->variant : null);
            $out[(string) $row->widget_id][$v]['orders'] = (int) $row->n;
            $out[(string) $row->widget_id][$v]['buyers'] = (int) $row->buyers;
            $out[(string) $row->widget_id][$v]['revenue'] = number_format((float) $row->revenue, 2, '.', '');
        }

        return $out;
    }

    /**
     * @param  list<string>  $widgetIds
     * @return list<array{widget: string, variant: string|null, views: int, visitors: int}>
     */
    private function views(Workspace $workspace, array $widgetIds, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $public = array_map(fn (string $id) => OnsiteWidget::ID_PREFIX.'_'.$id, $widgetIds);
        try {
            $rows = Ch::forWorkspace($workspace)->select(
                "SELECT toString(properties.widget_id) AS widget, toString(properties.variant) AS variant, count() AS views, uniqExact(anonymous_id) AS visitors
                 FROM events WHERE workspace_id = {workspace_id:UInt32} AND event = 'widget.shown'
                   AND occurred_at >= {from:DateTime64(3)} AND occurred_at < {to:DateTime64(3)}
                   AND toString(properties.widget_id) IN {ids:Array(String)}
                 GROUP BY widget, variant",
                ['from' => $from->utc()->format('Y-m-d H:i:s.v'), 'to' => $to->utc()->format('Y-m-d H:i:s.v'), 'ids' => self::chArray($public)],
            );
        } catch (Throwable $e) {
            report($e);

            return [];
        }

        return array_map(fn (array $r) => [
            'widget' => substr((string) $r['widget'], strlen(OnsiteWidget::ID_PREFIX) + 1),
            'variant' => (string) $r['variant'] !== '' ? (string) $r['variant'] : null,
            'views' => (int) $r['views'],
            'visitors' => (int) $r['visitors'],
        ], $rows);
    }

    /**
     * Vistas y envíos por día en la zona horaria del espacio de trabajo.
     *
     * @return list<array{date: string, views: int, submissions: int}>
     */
    private function daily(Workspace $workspace, OnsiteWidget $widget, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $tz = $workspace->timezone ?: 'America/Bogota';
        $days = [];
        for ($d = $from->setTimezone($tz)->startOfDay(); $d->lessThan($to); $d = $d->addDay()) {
            $days[$d->toDateString()] = ['date' => $d->toDateString(), 'views' => 0, 'submissions' => 0];
        }
        try {
            $rows = Ch::forWorkspace($workspace)->select(
                "SELECT toString(toDate(occurred_at, {tz:String})) AS day, count() AS views FROM events
                 WHERE workspace_id = {workspace_id:UInt32} AND event = 'widget.shown' AND toString(properties.widget_id) = {widget:String}
                   AND occurred_at >= {from:DateTime64(3)} AND occurred_at < {to:DateTime64(3)} GROUP BY day",
                ['tz' => $tz, 'widget' => $widget->publicId(), 'from' => $from->utc()->format('Y-m-d H:i:s.v'), 'to' => $to->utc()->format('Y-m-d H:i:s.v')],
            );
        } catch (Throwable) {
            $rows = [];
        }
        foreach ($rows as $row) {
            if (isset($days[(string) $row['day']])) {
                $days[(string) $row['day']]['views'] = (int) $row['views'];
            }
        }
        $offset = CarbonImmutable::now($tz)->format('P');
        $captures = DB::table('widget_captures')->where('workspace_id', $workspace->id)->where('widget_id', $widget->id)
            ->whereBetween('captured_at', [$from, $to])
            ->selectRaw("DATE(CONVERT_TZ(captured_at, '+00:00', ?)) AS day, COUNT(*) AS n", [$offset])->groupBy('day')->get();
        foreach ($captures as $row) {
            if (isset($days[(string) $row->day])) {
                $days[(string) $row->day]['submissions'] = (int) $row->n;
            }
        }

        return array_values($days);
    }

    /** @return array<string, int|string> */
    private function empty(): array
    {
        return ['views' => 0, 'visitors' => 0, 'submissions' => 0, 'new_contacts' => 0, 'known_contacts' => 0, 'orders' => 0, 'buyers' => 0, 'revenue' => '0.00'];
    }

    /**
     * @param  list<array<string, int|string>>  $rows
     * @return array<string, int|string>
     */
    private function sum(array $rows): array
    {
        $total = $this->empty();
        foreach ($rows as $row) {
            foreach ($total as $k => $v) {
                $total[$k] = $k === 'revenue' ? number_format((float) $v + (float) $row[$k], 2, '.', '') : (int) $v + (int) $row[$k];
            }
        }

        return $total;
    }

    /**
     * @param  array<string, int|string>  $row
     * @return Row
     */
    private function finish(array $row): array
    {
        $views = (int) $row['views'];
        $subs = (int) $row['submissions'];

        return [
            'views' => $views,
            'visitors' => (int) $row['visitors'],
            'submissions' => $subs,
            'new_contacts' => (int) $row['new_contacts'],
            'known_contacts' => (int) $row['known_contacts'],
            'orders' => (int) $row['orders'],
            'buyers' => (int) $row['buyers'],
            'revenue' => (string) $row['revenue'],
            'submit_rate' => $views > 0 ? round($subs / $views, 4) : null,
            'conversion_rate' => $subs > 0 ? round((int) $row['buyers'] / $subs, 4) : null,
        ];
    }

    /** @param  list<string>  $values */
    private static function chArray(array $values): string
    {
        return '['.implode(',', array_map(fn (string $v) => "'".str_replace(['\\', "'"], ['\\\\', "\\'"], $v)."'", $values)).']';
    }
}
