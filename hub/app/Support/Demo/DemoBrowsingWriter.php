<?php

declare(strict_types=1);

namespace App\Support\Demo;

use App\Analytics\Ch;
use App\Domain\Sites\Models\Site;
use App\Domain\Workspaces\Models\Workspace;
use App\Ingest\Pipeline\EventRowMapper;
use Carbon\CarbonImmutable;

/**
 * Escribe la navegación demo directo en ClickHouse (`igr:seed-demo
 * --browsing=direct`). Pasa cada sobre por el mismo EventRowMapper de la
 * ingesta (canal, dispositivo, sin PII) pero sin el resto del pipeline: así
 * se cargan millones de eventos en segundos. Los pedidos sí van por el
 * pipeline. Antes borra la navegación demo anterior del sitio en la ventana,
 * para que repetir el comando no duplique.
 */
final class DemoBrowsingWriter
{
    private const EVENTS = ['page.viewed', 'page.engaged', 'product.viewed', 'category.viewed', 'search.performed', 'cta.clicked', 'cart.item_added', 'checkout.started', 'form.submitted'];

    public function __construct(private readonly EventRowMapper $mapper, private readonly Ch $ch) {}

    public function write(Workspace $workspace, Site $site, DemoBrowsingGenerator $browsing, CarbonImmutable $from): int
    {
        Ch::forWorkspace($workspace)->execute(
            'DELETE FROM events WHERE workspace_id = {workspace_id:UInt32} AND site_id = {site:UInt32} AND event IN {events:Array(String)} AND occurred_at >= {from:DateTime64(3)}',
            ['site' => $site->ch_id, 'events' => "['".implode("','", self::EVENTS)."']", 'from' => $from->utc()->format('Y-m-d H:i:s.v')],
            ['mutations_sync' => 2],
        );

        $count = $this->insert($workspace, $site, $browsing->drain());
        foreach ($browsing->backgroundSessions() as $chunk) {
            $count += $this->insert($workspace, $site, $chunk);
        }

        return $count;
    }

    /** @param list<array<string, mixed>> $envelopes */
    private function insert(Workspace $workspace, Site $site, array $envelopes): int
    {
        if ($envelopes === []) {
            return 0;
        }
        $now = (int) floor(microtime(true) * 1000);
        $rows = [];
        foreach ($envelopes as $envelope) {
            $geo = $envelope['_geo'] ?? [];
            unset($envelope['_geo']);
            $row = $this->mapper->map($envelope, [
                'ws_ch' => $workspace->ch_id, 'site_ch' => $site->ch_id, 'site_host' => $site->host, 'received_ms' => $now, 'ua' => '',
            ], null);
            if (($row['city'] ?? '') === '' && is_array($geo)) {
                $row['country'] = (string) ($geo['country'] ?? '');
                $row['region'] = (string) ($geo['region'] ?? '');
                $row['city'] = (string) ($geo['city'] ?? '');
            }
            $rows[] = $row;
        }
        foreach (array_chunk($rows, 10000) as $chunk) {
            $this->ch->insert('events', $chunk);
        }

        return count($rows);
    }
}
