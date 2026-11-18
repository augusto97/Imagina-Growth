<?php

declare(strict_types=1);

namespace App\Domain\Measurement\Attribution;

use App\Analytics\Ch;
use App\Domain\Workspaces\Models\Workspace;
use Carbon\CarbonInterface;

/**
 * Visitas del contacto (o de sus anonymous_id) en ClickHouse: una fila por
 * sesión con el canal y las UTM de su primer evento (inteligencia.md §2: el
 * canal se evalúa sobre la sesión de entrada).
 */
final class TouchQuery
{
    /**
     * @param  list<string>  $anonymousIds  además de los que identity_map une al contacto
     * @return list<Touch> ordenadas de la más vieja a la más nueva
     */
    public function sessions(Workspace $workspace, ?string $contactId, array $anonymousIds, ?CarbonInterface $from, CarbonInterface $to): array
    {
        if ($contactId === null && $anonymousIds === []) {
            return [];
        }
        $rows = Ch::forWorkspace($workspace)->select(
            'SELECT session_id,
                    min(occurred_at) AS started,
                    argMin(channel, occurred_at) AS landing_channel,
                    argMin(utm_source, occurred_at) AS landing_utm_source,
                    argMin(utm_medium, occurred_at) AS landing_utm_medium,
                    argMin(utm_campaign, occurred_at) AS landing_utm_campaign
             FROM events
             WHERE workspace_id = {workspace_id:UInt32}
               AND source_type = \'browser\' AND session_id != \'\' AND channel != \'\'
               AND occurred_at <= {to:DateTime64(3)}
               AND occurred_at >= {from:DateTime64(3)}
               AND (
                    (contact_id != \'\' AND contact_id = {contact:String})
                    OR anonymous_id IN {anon:Array(String)}
                    OR anonymous_id IN (SELECT anonymous_id FROM identity_map WHERE workspace_id = {workspace_id:UInt32} AND contact_id = {contact:String})
               )
             GROUP BY session_id
             ORDER BY started',
            [
                'contact' => $contactId ?? '-',
                'anon' => self::array($anonymousIds),
                'from' => ($from ?? $to->copy()->subYears(5))->utc()->format('Y-m-d H:i:s.v'),
                'to' => $to->copy()->utc()->format('Y-m-d H:i:s.v'),
            ],
        );

        return array_map(fn (array $r) => new Touch(
            (string) $r['landing_channel'],
            self::nullable($r['landing_utm_source'] ?? null),
            self::nullable($r['landing_utm_medium'] ?? null),
            self::nullable($r['landing_utm_campaign'] ?? null),
            (string) $r['started'],
        ), $rows);
    }

    /** ¿El espacio de trabajo tiene datos del tracker? Si no, la atribución usa solo WooCommerce. */
    public function hasBrowserData(Workspace $workspace): bool
    {
        return (int) Ch::forWorkspace($workspace)->value(
            "SELECT count() FROM events WHERE workspace_id = {workspace_id:UInt32} AND source_type = 'browser' AND channel != '' LIMIT 1"
        ) > 0;
    }

    /** @param list<string> $values */
    private static function array(array $values): string
    {
        return '['.implode(',', array_map(fn ($v) => "'".str_replace(['\\', "'"], ['\\\\', "\\'"], $v)."'", $values)).']';
    }

    private static function nullable(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
