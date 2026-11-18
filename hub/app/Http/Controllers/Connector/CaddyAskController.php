<?php

declare(strict_types=1);

namespace App\Http\Controllers\Connector;

use App\Domain\Agency\Domains\CustomDomains;
use App\Domain\Sites\Models\Site;
use App\Domain\Sites\Support\SiteKeys;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * GET /internal/caddy/ask?domain=… — Caddy pregunta antes de emitir un
 * certificado bajo demanda (hub/CLAUDE.md §18). Solo responde 200 para
 * dominios de seguimiento de sitios conectados y para los dominios propios
 * verificados de las agencias (M22). Solo desde la red interna:
 * Caddy bloquea /internal/* hacia afuera y aquí se exige una IP privada sin
 * cabeceras de proxy.
 */
final class CaddyAskController
{
    public function __invoke(Request $request): Response
    {
        $remote = $request->server->getString('REMOTE_ADDR');
        $isPrivate = filter_var($remote, FILTER_VALIDATE_IP) !== false
            && filter_var($remote, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
        if (! $isPrivate || $request->headers->has('X-Forwarded-For')) {
            return response('', 403);
        }

        $domain = SiteKeys::host('https://'.ltrim((string) $request->query('domain', ''), '/'));
        if ($domain === null) {
            return response('', 404);
        }
        $known = Site::withoutWorkspaceScope()
            ->whereIn('status', [Site::STATUS_CONNECTED, Site::STATUS_DEGRADED])
            ->where('tracking_domain', $domain)
            ->exists() || CustomDomains::servable($domain);

        return response('', $known ? 200 : 404);
    }
}
