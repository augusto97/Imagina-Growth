<?php

declare(strict_types=1);

namespace App\Ingest\Http;

use App\Ingest\IngestStream;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\RateLimiter;

/**
 * POST /v1/b — eventos del tracker (api-connector.md §4.2). Clave pública del
 * sitio + Origin contra sites.domains. Cuerpo text/plain con JSON.
 */
final class BrowserIngestController
{
    public function __construct(
        private readonly SiteResolver $sites,
        private readonly EnvelopeChecker $checker,
        private readonly IngestStream $stream,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $raw = $request->getContent();
        if (strlen($raw) > (int) config('igr.ingest.browser_batch_max_bytes')) {
            return response()->json(['error' => 'too_large'], 413);
        }
        $payload = json_decode($raw);
        if (! is_object($payload) || ! isset($payload->k, $payload->events) || ! is_string($payload->k) || ! is_array($payload->events)) {
            return response()->json(['error' => 'bad_request'], 400);
        }
        $resolved = $this->sites->byPublicKey($payload->k);
        if ($resolved === null) {
            return response()->json(['error' => 'unknown_site'], 401);
        }
        [$site, $wsChId] = $resolved;
        if (! $site->isActive()) {
            return response()->json(['error' => 'site_disconnected'], 403);
        }
        $origin = $request->header('Origin');
        if (! $this->originAllowed($origin, $site->domains ?? [])) {
            return response()->json(['error' => 'origin'], 403);
        }
        if (count($payload->events) > (int) config('igr.ingest.browser_batch_max_events')) {
            return response()->json(['error' => 'too_many_events'], 413);
        }

        $ip = (string) $request->ip();
        if (RateLimiter::tooManyAttempts('igr-b-ip:'.$ip, 300) || RateLimiter::tooManyAttempts('igr-b-site:'.$site->id, 20000)) {
            return response()->json(['error' => 'rate_limited'], 429);
        }
        RateLimiter::hit('igr-b-ip:'.$ip, 60);
        RateLimiter::hit('igr-b-site:'.$site->id, 60);

        $result = new IngestResult;
        $accepted = $this->checker->check(array_values($payload->events), $site, $result, 'browser');
        // La IP y el user agent viajan al worker solo para derivar geo y dispositivo; nunca se guardan.
        $this->stream->push($site, $wsChId, $accepted, ['ip' => $ip, 'ua' => (string) $request->userAgent()]);

        return $result->toResponse()->withHeaders($this->cors($origin));
    }

    public function preflight(Request $request): Response
    {
        return response('', 204)->withHeaders($this->cors($request->header('Origin')) + ['Access-Control-Max-Age' => '86400']);
    }

    /** @param list<string> $domains */
    private function originAllowed(?string $origin, array $domains): bool
    {
        if ($origin === null || $origin === '') {
            return false;
        }
        $host = parse_url($origin, PHP_URL_HOST);
        if (! is_string($host)) {
            return false;
        }

        return in_array(strtolower($host), array_map('strtolower', $domains), true);
    }

    /** @return array<string, string> */
    private function cors(?string $origin): array
    {
        return [
            'Access-Control-Allow-Origin' => $origin ?: '*',
            'Access-Control-Allow-Methods' => 'POST, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type',
            'Vary' => 'Origin',
        ];
    }
}
