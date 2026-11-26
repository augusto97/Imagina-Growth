<?php

declare(strict_types=1);

namespace App\Domain\Capture\LeadAds;

use App\Domain\Capture\Models\LeadAdLead;
use App\Domain\Capture\Models\LeadAdSource;
use App\Domain\Connections\Support\ConnectionError;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Tenancy\SetWorkspaceContext;
use App\Support\Tenancy\WorkspaceAware;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Procesa un lead de anuncios fuera del webhook (cola `default`): en Meta lo lee por la Graph API con el token de la
 * página (el aviso solo trae el ID); en Google ya viene en el aviso (guardado cifrado en la fila). Después
 * `ImportAdLead` crea el contacto y el lead. Una fila ya procesada no se vuelve a procesar (idempotente). Un error de
 * permisos o del token deja la fila y la fuente con el mensaje en palabras (no sirve reintentar hasta reconectar); una
 * falla pasajera de Meta se reintenta.
 */
final class ProcessAdLead implements ShouldQueue, WorkspaceAware
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    public int $timeout = 60;

    public function __construct(public string $workspaceIdValue, public string $leadRowId)
    {
        $this->onQueue('default');
    }

    public function workspaceId(): string
    {
        return $this->workspaceIdValue;
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [new SetWorkspaceContext];
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300, 1800, 7200];
    }

    public function handle(MetaLeadAds $meta, ImportAdLead $import): void
    {
        $workspace = Workspace::query()->find($this->workspaceIdValue);
        $row = LeadAdLead::query()->find($this->leadRowId);
        if ($workspace === null || $row === null || in_array($row->status, ['processed', 'test'], true)) {
            return;
        }
        $source = LeadAdSource::query()->find($row->source_id);
        if ($source === null) {
            return;
        }
        $row->forceFill(['attempts' => $row->attempts + 1])->save();
        if ($row->platform === LeadAdSource::META) {
            try {
                $data = AdLeadData::fromMeta($meta->lead($source, $row->external_id));
            } catch (ConnectionError $e) {
                $row->forceFill(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 500)])->save();
                Log::warning('igr.capture.meta_lead_failed', ['workspace' => $workspace->id, 'source' => $source->id, 'permanent' => $e->permanent]);
                if ($e->permanent) {
                    $source->forceFill(['status' => 'error', 'last_error' => mb_substr($e->getMessage(), 0, 500)])->save();

                    return;
                }
                throw $e; // pasajera: la cola reintenta
            }
        } else {
            $data = AdLeadData::fromGoogle((array) (($row->data ?? [])['payload'] ?? []));
        }
        $import->handle($workspace, $source, $row, $data);
    }
}
