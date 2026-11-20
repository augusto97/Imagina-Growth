<?php

declare(strict_types=1);

namespace App\Ingest\Http;

use App\Domain\Sites\Models\Site;
use App\Domain\Sites\Support\SiteSignatures;
use App\Ingest\IngestStream;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /v1/s — lotes firmados del connector (api-connector.md §4.1).
 */
final class ServerIngestController
{
    public function __construct(
        private readonly SiteResolver $sites,
        private readonly EnvelopeChecker $checker,
        private readonly IngestStream $stream,
        private readonly SiteSignatures $signatures,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $raw = $request->getContent();
        if (strlen($raw) > (int) config('igr.ingest.server_batch_max_bytes')) {
            return response()->json(['error' => 'too_large'], 413);
        }

        $resolved = $this->sites->byPublicId($request->header('X-IGR-Site'));
        if ($resolved === null) {
            return response()->json(['error' => 'signature'], 401);
        }
        [$site, $wsChId] = $resolved;

        // La firma (v2: método, ruta, consulta y nonce; v1 mientras se acepte) se verifica sobre los bytes recibidos,
        // antes de descomprimir.
        if ($this->signatures->verify($request, $site, $raw) === null) {
            return response()->json(['error' => 'signature'], 401);
        }
        if (! $site->isActive()) {
            return response()->json(['error' => 'site_disconnected'], 403);
        }

        $body = $raw;
        if (str_contains(strtolower((string) $request->header('Content-Encoding')), 'gzip')) {
            $body = @gzdecode($raw, (int) config('igr.ingest.server_batch_max_inflated_bytes'));
            if ($body === false) {
                return response()->json(['error' => 'bad_encoding'], 400);
            }
        }

        $payload = json_decode($body);
        if (! is_object($payload) || ! isset($payload->events) || ! is_array($payload->events)) {
            return response()->json(['error' => 'bad_request'], 400);
        }
        if (count($payload->events) > (int) config('igr.ingest.server_batch_max_events')) {
            return response()->json(['error' => 'too_many_events'], 413);
        }

        $result = new IngestResult;
        $accepted = $this->checker->check(array_values($payload->events), $site, $result, 'server');
        $this->stream->push($site, $wsChId, $accepted);

        if ($accepted !== []) {
            Site::withoutWorkspaceScope()->whereKey($site->id)->update(['last_seen_at' => now()]);
        }

        return $result->toResponse();
    }
}
