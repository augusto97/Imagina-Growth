<?php

declare(strict_types=1);

namespace App\Domain\Referrals\Http\Public;

use App\Domain\Referrals\Support\Fingerprints;
use App\Domain\Referrals\Support\Programs;
use App\Domain\Referrals\Support\ReferralCodes;
use App\Domain\Referrals\Support\ReferralEvents;
use App\Domain\Referrals\Support\SiteCustomer;
use App\Domain\Sites\Models\Site;
use App\Domain\Workspaces\Models\Workspace;
use App\Ingest\Http\SiteResolver;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * `POST /p/{public_key}/me/share` `{"token", "channel"}` (api-connector.md §8.3): el
 * cliente compartió su enlace de referido → `202` y `referral.shared`. Solo con un
 * `igrUser.token` válido (`401` si no). Guarda el hash de la IP de quien comparte
 * para las señales de fraude. Límite por IP con hash (`429`).
 */
final class ReferralShareController
{
    private const CHANNELS = ['whatsapp', 'copy', 'email', 'other'];

    public function __construct(private readonly SiteResolver $sites, private readonly CurrentWorkspace $current) {}

    public function __invoke(Request $request, string $publicKey, Programs $programs, ReferralCodes $codes, ReferralEvents $events, Fingerprints $fingerprints): JsonResponse
    {
        $resolved = $this->sites->byPublicKey($publicKey);
        $site = $resolved !== null && $resolved[0]->isActive() ? $resolved[0] : null;
        $workspace = $site !== null ? Workspace::query()->find($site->workspace_id) : null;
        if ($site === null || $workspace === null) {
            return response()->json(['error' => 'unknown_site'], 404);
        }
        $cors = self::cors($request, $site);
        $limiter = 'igr-referral-share:'.hash('sha256', (string) $request->ip());
        if (RateLimiter::tooManyAttempts($limiter, 30)) {
            return response()->json(['error' => 'rate_limited'], 429)->withHeaders($cors);
        }
        RateLimiter::hit($limiter, 60);

        $body = json_decode($request->getContent(), true);
        $body = is_array($body) ? $body : [];
        $channel = is_string($body['channel'] ?? null) && in_array($body['channel'], self::CHANNELS, true) ? $body['channel'] : 'other';

        return $this->current->run($workspace, function () use ($site, $body, $channel, $request, $programs, $codes, $events, $fingerprints, $workspace, $cors): JsonResponse {
            $contact = SiteCustomer::fromToken($site, is_string($body['token'] ?? null) ? $body['token'] : null);
            if ($contact === null) {
                return response()->json(['error' => 'invalid_token'], 401)->withHeaders($cors);
            }
            if ($programs->active() === null) {
                return response()->json(['error' => 'program_inactive'], 404)->withHeaders($cors);
            }
            $code = $codes->forContact($contact);
            $fingerprints->record($workspace->id, $contact->id, 'ip', $request->ip());
            $events->shared($contact->id, $code, $channel, $site);

            return response()->json(['ok' => true], 202)->withHeaders($cors + ['Cache-Control' => 'no-store, private']);
        });
    }

    /** @return array<string, string> */
    private static function cors(Request $request, Site $site): array
    {
        $origin = (string) $request->header('Origin');
        $host = parse_url($origin, PHP_URL_HOST);
        $allowed = is_string($host) && in_array(strtolower($host), array_map('strtolower', $site->domains ?? []), true);

        return ($allowed ? ['Access-Control-Allow-Origin' => $origin] : []) + [
            'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type',
            'Vary' => 'Origin',
        ];
    }
}
