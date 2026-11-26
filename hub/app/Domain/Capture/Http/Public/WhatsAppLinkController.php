<?php

declare(strict_types=1);

namespace App\Domain\Capture\Http\Public;

use App\Domain\Capture\Models\WhatsAppLink;
use App\Domain\Capture\Support\CaptureCounters;
use App\Domain\Capture\Support\CaptureUrls;
use App\Domain\Capture\Support\WhatsAppNumber;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `GET /p/w/{código}`: el enlace corto de un chat de WhatsApp con atribución (el QR impreso o el botón de una página
 * de captura). Cuenta el escaneo o el clic y redirige (302, sin caché) a wa.me con el número conectado y el mensaje
 * de alta terminado en «(ref: CÓDIGO)». Así el número se puede cambiar sin volver a imprimir el QR.
 */
final class WhatsAppLinkController
{
    public function __construct(private readonly CurrentWorkspace $current, private readonly PublicCapture $public) {}

    public function __invoke(Request $request, string $code): Response
    {
        $code = strtoupper($code);
        $link = WhatsAppLink::withoutWorkspaceScope()->where('code', $code)->where('status', 'active')->first();
        $workspace = $link !== null ? Workspace::query()->find($link->workspace_id) : null;
        if ($link === null || $workspace === null || $this->public->limited($request, 'wa', (int) config('igr-capture.limits.redirects_per_minute', 120))) {
            return $this->public->missing(null, $link === null ? 404 : 429);
        }
        if (! $this->public->available($workspace) || ! CaptureUrls::hostAllows($request, $workspace)) {
            return $this->public->missing($workspace);
        }

        return $this->current->run($workspace, function () use ($request, $workspace, $link): Response {
            $digits = WhatsAppNumber::digits($workspace, WhatsAppNumber::account($link->channel_account_id));
            if ($digits === null) {
                return $this->public->missing($workspace);
            }
            $via = $link->kind === 'qr' || $request->query('via') === 'qr' ? 'qr' : 'link';
            CaptureCounters::view($workspace->id, CaptureCounters::WA_LINK, $link->id, $via, $request->ip());

            return redirect()->away(CaptureUrls::waMe($digits, $link->message, $link->code), 302)
                ->withHeaders(['Cache-Control' => 'no-store, private', 'X-Robots-Tag' => 'noindex, nofollow', 'Referrer-Policy' => 'no-referrer']);
        });
    }
}
