<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Sites\Support\SiteSignatures;
use App\Domain\Workspaces\Models\Workspace;
use App\Ingest\Http\SiteResolver;
use App\Support\Tenancy\CurrentWorkspace;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autentica los endpoints /v1/s/* del connector (api-connector.md §2: v2 con método, ruta, consulta y nonce; v1
 * mientras se acepte) y deja el sitio y su espacio de trabajo activos para la petición.
 */
final class VerifySiteSignature
{
    public function __construct(
        private readonly SiteResolver $sites,
        private readonly CurrentWorkspace $current,
        private readonly SiteSignatures $signatures,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $resolved = $this->sites->byPublicId($request->header('X-IGR-Site'));
        if ($resolved === null) {
            return response()->json(['error' => 'signature'], 401);
        }
        [$site] = $resolved;
        if ($this->signatures->verify($request, $site, $request->getContent()) === null) {
            return response()->json(['error' => 'signature'], 401);
        }
        if (! $site->isActive()) {
            return response()->json(['error' => 'site_disconnected'], 403);
        }

        $workspace = Workspace::query()->findOrFail($site->workspace_id);
        $this->current->set($workspace);
        $request->attributes->set('igr_site', $site);

        return $next($request);
    }
}
