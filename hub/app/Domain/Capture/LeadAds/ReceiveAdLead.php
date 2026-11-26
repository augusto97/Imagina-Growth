<?php

declare(strict_types=1);

namespace App\Domain\Capture\LeadAds;

use App\Domain\Capture\Models\LeadAdLead;
use App\Domain\Capture\Models\LeadAdSource;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;

/**
 * Registra un lead de anuncios que acaba de avisar Meta (campo `leadgen` de la página) o Google (webhook del
 * formulario) y encola su proceso. Idempotente por (espacio de trabajo, plataforma, ID del lead): un aviso repetido no
 * crea otra fila ni encola otra vez. Los de prueba de Google se guardan marcados (`status = test`) y no crean contacto
 * ni lead: no cuentan en las cifras.
 */
final class ReceiveAdLead
{
    public function __construct(private readonly CurrentWorkspace $current) {}

    /**
     * @param  array<string, mixed>  $ids  form_id, campaign_id, adset_id y ad_id del aviso
     * @param  array<string, mixed>  $data  lo que se guarda cifrado mientras se procesa (Google: el aviso sin la clave)
     * @return LeadAdLead|null la fila nueva, o null si ya existía
     */
    public function handle(LeadAdSource $source, string $externalId, array $ids, array $data = [], bool $isTest = false, ?CarbonImmutable $at = null): ?LeadAdLead
    {
        $workspace = $source->workspace()->first();
        if ($workspace === null) {
            return null;
        }

        return $this->current->run($workspace, function () use ($source, $externalId, $ids, $data, $isTest, $at): ?LeadAdLead {
            $externalId = mb_substr($externalId, 0, 100);
            if (LeadAdLead::query()->where('platform', $source->platform)->where('external_id', $externalId)->exists()) {
                if ($isTest) {
                    $source->forceFill(['test_received_at' => now()])->save(); // Google repite el mismo lead de prueba
                }

                return null;
            }
            try {
                $row = LeadAdLead::query()->create([
                    'workspace_id' => $source->workspace_id,
                    'source_id' => $source->id,
                    'platform' => $source->platform,
                    'external_id' => $externalId,
                    'form_id' => self::id($ids['form_id'] ?? null),
                    'campaign_id' => self::id($ids['campaign_id'] ?? null),
                    'adset_id' => self::id($ids['adset_id'] ?? null),
                    'ad_id' => self::id($ids['ad_id'] ?? null),
                    'is_test' => $isTest,
                    'status' => $isTest ? 'test' : 'received',
                    'data' => $data !== [] ? $data : null,
                    'received_at' => $at ?? now(),
                ]);
            } catch (QueryException $e) {
                if (str_contains($e->getMessage(), 'Duplicate')) {
                    return null; // dos avisos a la vez: el otro ya la creó
                }
                throw $e;
            }
            if ($isTest) {
                $source->forceFill(['test_received_at' => now()])->save();

                return $row;
            }
            ProcessAdLead::dispatch($source->workspace_id, $row->id);

            return $row;
        });
    }

    private static function id(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? mb_substr(trim((string) $value), 0, 64) : null;
    }
}
