<?php

declare(strict_types=1);

namespace App\Domain\Api\Http\V1;

use App\Domain\Api\Actions\ResolveApiContact;
use App\Domain\Api\Support\ApiError;
use App\Ingest\Http\EnvelopeChecker;
use App\Ingest\Http\IngestResult;
use App\Ingest\IngestStream;
use App\Ingest\Schema\SchemaRegistry;
use App\Support\Tenancy\CurrentWorkspace;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Métricas web reales de Imagina Turbo (ecosistema): LCP, INP y CLS por página. Entran como
 * evento `page.vitals` a ClickHouse para cruzarlas con la conversión. Solo funciona cuando el
 * contrato de eventos incluye `page.vitals`; mientras tanto responde 501.
 */
#[Group('Ecosistema', weight: 9)]
final class VitalsController
{
    public function __construct(private readonly CurrentWorkspace $current) {}

    /**
     * Enviar métricas web de una página
     *
     * `page` (URL), `lcp_ms`, `inp_ms`, `cls` y opcionales `ttfb_ms`, `device` y `samples`.
     * Requiere `events:write`.
     */
    public function store(Request $request, SchemaRegistry $schemas, EnvelopeChecker $checker, IngestStream $stream): JsonResponse
    {
        if (! $schemas->isKnownEvent('page.vitals')) {
            return ApiError::make(501, 'vitals_not_enabled', __('El evento page.vitals aún no está en el contrato de eventos.'));
        }
        $data = $request->validate([
            'page' => ['required', 'string', 'max:2048'],
            'lcp_ms' => ['required', 'integer', 'min:0', 'max:120000'],
            'inp_ms' => ['required', 'integer', 'min:0', 'max:120000'],
            'cls' => ['required', 'numeric', 'min:0', 'max:100'],
            'ttfb_ms' => ['sometimes', 'integer', 'min:0', 'max:120000'],
            'device' => ['sometimes', 'in:mobile,tablet,desktop'],
            'samples' => ['sometimes', 'integer', 'min:1'],
            'site_id' => ['sometimes', 'string', 'max:40'],
        ]);
        $site = ResolveApiContact::site($data['site_id'] ?? null);
        if ($site === null) {
            return ApiError::make(422, 'no_site', __('Conecta un sitio antes de enviar métricas web.'));
        }
        unset($data['site_id']);
        $envelope = json_decode((string) json_encode([
            'schema_version' => 5,
            'event_id' => (string) Str::uuid7(),
            'event' => 'page.vitals',
            'occurred_at' => now()->format(DATE_RFC3339_EXTENDED),
            'source' => ['type' => 'server', 'platform' => $site->platform === 'woocommerce' ? 'woocommerce' : 'wordpress', 'site_id' => $site->publicId(), 'sdk' => 'hub/api-v1'],
            'identity' => (object) [],
            'properties' => $data,
        ]));
        $result = new IngestResult;
        $accepted = $checker->check([$envelope], $site, $result, 'server');
        $stream->push($site, (int) $this->current->get()->ch_id, $accepted);

        return $result->accepted > 0
            ? new JsonResponse(['accepted' => 1], 202)
            : ApiError::make(422, 'validation_failed', __('Las métricas no pasaron la validación del contrato.'));
    }
}
