<?php

declare(strict_types=1);

namespace App\Domain\Measurement\AdSpend;

use App\Domain\Measurement\Models\AdDestination;
use App\Domain\Measurement\Models\AdSpendDaily;
use Carbon\CarbonImmutable;

/** Importa y guarda el gasto de un destino (upsert idempotente por día, campaña, conjunto y anuncio). */
final class ImportAdSpend
{
    /** @var list<SpendImporter> */
    private array $importers;

    public function __construct(MetaSpendImporter $meta, GoogleSpendImporter $google, TikTokSpendImporter $tiktok)
    {
        $this->importers = [$meta, $google, $tiktok];
    }

    public function supports(AdDestination $destination): bool
    {
        foreach ($this->importers as $importer) {
            if ($importer->supports($destination)) {
                return true;
            }
        }

        return false;
    }

    public function handle(AdDestination $destination, CarbonImmutable $from, CarbonImmutable $to): int
    {
        foreach ($this->importers as $importer) {
            if (! $importer->supports($destination)) {
                continue;
            }
            $rows = $importer->fetch($destination, $from, $to);
            $now = now();
            $records = array_map(fn (SpendRow $r) => [
                'workspace_id' => $destination->workspace_id,
                'platform' => $destination->platform->value,
                'date' => $r->date,
                'campaign_id' => $r->campaignId,
                'campaign_name' => $r->campaignName !== null ? mb_substr($r->campaignName, 0, 255) : null,
                // NULL en una clave única permite duplicados en MariaDB: se guarda vacío.
                'adset_id' => $r->adsetId ?? '',
                'ad_id' => $r->adId ?? '',
                'spend' => $r->spend,
                'currency' => $r->currency,
                'impressions' => $r->impressions,
                'clicks' => $r->clicks,
                'created_at' => $now,
                'updated_at' => $now,
            ], $rows);
            foreach (array_chunk($records, 500) as $chunk) {
                AdSpendDaily::query()->upsert($chunk, ['workspace_id', 'platform', 'date', 'campaign_id', 'adset_id', 'ad_id'], ['campaign_name', 'spend', 'currency', 'impressions', 'clicks', 'updated_at']);
            }

            return count($records);
        }

        return 0;
    }
}
