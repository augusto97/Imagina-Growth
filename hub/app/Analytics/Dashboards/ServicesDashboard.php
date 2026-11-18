<?php

declare(strict_types=1);

namespace App\Analytics\Dashboards;

use App\Analytics\Ch;
use App\Analytics\Semantic\Filters;
use App\Analytics\Semantic\Period;
use App\Domain\Workspaces\Models\Workspace;

/**
 * 11. Servicios: formularios por fuente y página, conversión del formulario
 * frente a las vistas de la página y clics a WhatsApp o llamada. Tiempo de
 * primera respuesta y cierre por fuente llegan con la bandeja y el pipeline (fases 4 y 5).
 */
final class ServicesDashboard extends Dashboard
{
    public function handle(Workspace $workspace, Period $period, Filters $filters): array
    {
        [$fsql, $params] = $filters->eventsSql();
        $ch = Ch::forWorkspace($workspace);
        $range = $params + self::chRange($period);

        $forms = $ch->select(
            "SELECT toString(properties.form_name) AS form, toString(properties.form_plugin) AS plugin, count() AS submissions,
                    any(toString(properties.page_path)) AS page
             FROM events WHERE workspace_id = {workspace_id:UInt32} AND event = 'form.submitted'
               AND occurred_at >= {from:DateTime64(3)} AND occurred_at < {to:DateTime64(3)}{$fsql}
             GROUP BY form, plugin ORDER BY submissions DESC LIMIT 50",
            $range,
        );
        $byChannel = $ch->select(
            "SELECT channel, countIf(event = 'form.submitted') AS forms, countIf(event = 'cta.clicked' AND toString(properties.kind) = 'whatsapp') AS whatsapp,
                    countIf(event = 'cta.clicked' AND toString(properties.kind) = 'phone') AS phone
             FROM events WHERE workspace_id = {workspace_id:UInt32} AND event IN ('form.submitted', 'cta.clicked')
               AND occurred_at >= {from:DateTime64(3)} AND occurred_at < {to:DateTime64(3)}{$fsql}
             GROUP BY channel ORDER BY forms + whatsapp + phone DESC",
            $range,
        );
        $pages = $ch->select(
            "SELECT path, countIf(event = 'page.viewed') AS views, countIf(event = 'form.submitted') AS forms,
                    countIf(event = 'cta.clicked' AND toString(properties.kind) = 'whatsapp') AS whatsapp,
                    countIf(event = 'cta.clicked' AND toString(properties.kind) IN ('phone', 'email')) AS calls
             FROM events WHERE workspace_id = {workspace_id:UInt32} AND event IN ('page.viewed', 'form.submitted', 'cta.clicked')
               AND occurred_at >= {from:DateTime64(3)} AND occurred_at < {to:DateTime64(3)}{$fsql}
             GROUP BY path HAVING forms + whatsapp + calls > 0 ORDER BY forms + whatsapp + calls DESC LIMIT 30",
            $range,
        );
        $clicks = $ch->select(
            "SELECT toString(properties.kind) AS kind, count() AS clicks FROM events
             WHERE workspace_id = {workspace_id:UInt32} AND event = 'cta.clicked' AND occurred_at >= {from:DateTime64(3)} AND occurred_at < {to:DateTime64(3)}{$fsql}
             GROUP BY kind ORDER BY clicks DESC",
            $range,
        );

        return [
            'forms' => array_map(fn ($r) => ['form' => (string) $r['form'], 'plugin' => (string) $r['plugin'], 'submissions' => (int) $r['submissions'], 'page' => (string) $r['page']], $forms),
            'by_channel' => array_map(fn ($r) => ['channel' => (string) $r['channel'] ?: 'unknown', 'forms' => (int) $r['forms'], 'whatsapp' => (int) $r['whatsapp'], 'phone' => (int) $r['phone']], $byChannel),
            'pages' => array_map(fn ($r) => ['path' => (string) $r['path'], 'views' => (int) $r['views'], 'forms' => (int) $r['forms'], 'whatsapp' => (int) $r['whatsapp'],
                'calls' => (int) $r['calls'], 'form_rate' => self::ratio((int) $r['forms'], (int) $r['views'])], $pages),
            'cta_clicks' => array_map(fn ($r) => ['kind' => (string) $r['kind'], 'clicks' => (int) $r['clicks']], $clicks),
            'response_time' => ['available' => false, 'phase' => 4],
        ];
    }
}
