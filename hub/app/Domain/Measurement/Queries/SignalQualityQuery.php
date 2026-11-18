<?php

declare(strict_types=1);

namespace App\Domain\Measurement\Queries;

use App\Analytics\Ch;
use App\Domain\Workspaces\Models\Workspace;

/**
 * Calidad de la señal por destino (modulos.md M06): qué parte de las
 * conversiones viajó con correo, teléfono, click ID o cookie del navegador, y
 * cuántas se entregaron, fallaron o siguen en reintento.
 */
final class SignalQualityQuery
{
    /** @return array<string, array<string, mixed>> destination_id público => métricas */
    public function handle(Workspace $workspace, int $days = 7): array
    {
        $ch = Ch::forWorkspace($workspace);
        $params = ['days' => min(90, max(1, $days))];
        $finals = $ch->select(
            'SELECT destination_id,
                    countIf(final = 1) AS total,
                    countIf(final = 1 AND status = \'delivered\') AS delivered,
                    countIf(final = 1 AND status = \'failed\') AS failed,
                    countIf(final = 1 AND has(match_keys, \'email\')) AS with_email,
                    countIf(final = 1 AND has(match_keys, \'phone\')) AS with_phone,
                    countIf(final = 1 AND has(match_keys, \'click_id\')) AS with_click_id,
                    countIf(final = 1 AND has(match_keys, \'browser_id\')) AS with_browser_id,
                    countIf(final = 1 AND has(match_keys, \'external_id\')) AS with_external_id
             FROM conversion_deliveries
             WHERE workspace_id = {workspace_id:UInt32} AND delivered_at >= now() - toIntervalDay({days:UInt16}) AND test = 0
             GROUP BY destination_id',
            $params,
        );
        $retrying = $ch->select(
            'SELECT destination_id, count() AS retrying FROM (
                SELECT destination_id, target, event_id, max(final) AS done
                FROM conversion_deliveries
                WHERE workspace_id = {workspace_id:UInt32} AND delivered_at >= now() - toIntervalDay({days:UInt16})
                GROUP BY destination_id, target, event_id
             ) WHERE done = 0 GROUP BY destination_id',
            $params,
        );
        $errors = $ch->select(
            'SELECT destination_id, formatDateTime(delivered_at, \'%FT%T.%fZ\', \'UTC\') AS at, conversion, error, error_code
             FROM conversion_deliveries
             WHERE workspace_id = {workspace_id:UInt32} AND final = 1 AND status = \'failed\' AND delivered_at >= now() - toIntervalDay({days:UInt16})
             ORDER BY delivered_at DESC
             LIMIT 5 BY destination_id',
            $params,
        );

        $out = [];
        foreach ($finals as $row) {
            $total = (int) $row['total'];
            $ratio = fn (string $k) => $total > 0 ? round(((int) $row[$k]) / $total, 4) : null;
            $out['dst_'.$row['destination_id']] = [
                'total' => $total,
                'delivered' => (int) $row['delivered'],
                'failed' => (int) $row['failed'],
                'retrying' => 0,
                'email_rate' => $ratio('with_email'),
                'phone_rate' => $ratio('with_phone'),
                'click_id_rate' => $ratio('with_click_id'),
                'browser_id_rate' => $ratio('with_browser_id'),
                'external_id_rate' => $ratio('with_external_id'),
                'last_errors' => [],
            ];
        }
        foreach ($retrying as $row) {
            $key = 'dst_'.$row['destination_id'];
            $out[$key] ??= ['total' => 0, 'delivered' => 0, 'failed' => 0, 'retrying' => 0, 'email_rate' => null, 'phone_rate' => null, 'click_id_rate' => null, 'browser_id_rate' => null, 'external_id_rate' => null, 'last_errors' => []];
            $out[$key]['retrying'] = (int) $row['retrying'];
        }
        foreach ($errors as $row) {
            $key = 'dst_'.$row['destination_id'];
            if (isset($out[$key])) {
                $out[$key]['last_errors'][] = ['at' => $row['at'], 'conversion' => $row['conversion'], 'message' => $row['error'], 'code' => $row['error_code']];
            }
        }

        return $out;
    }
}
