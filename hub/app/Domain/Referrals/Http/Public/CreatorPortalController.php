<?php

declare(strict_types=1);

namespace App\Domain\Referrals\Http\Public;

use App\Domain\Referrals\Actions\SendCreatorPortalLink;
use App\Domain\Referrals\Models\Affiliate;
use App\Domain\Referrals\Queries\CreatorPortalData;
use App\Domain\Referrals\Support\CreatorToken;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * `/creadores/{token}`: panel del creador con enlace mágico (api-connector.md §8.3).
 * Sin sesión ni contraseña: el token firmado identifica al afiliado. Si venció, la
 * persona puede pedir uno nuevo, que solo se envía al correo que registró la marca.
 */
final class CreatorPortalController
{
    private const HEADERS = ['Cache-Control' => 'no-store, private', 'X-Robots-Tag' => 'noindex', 'Referrer-Policy' => 'no-referrer'];

    public function __construct(private readonly CurrentWorkspace $current) {}

    public function show(string $token, CreatorPortalData $data): Response
    {
        [$workspace, $affiliate, $parsed] = $this->resolve($token) ?? [null, null, null];
        if ($workspace === null || $affiliate === null || $parsed === null) {
            return response()->view('referrals.creator-missing', [], 404)->withHeaders(self::HEADERS);
        }
        if ($parsed['expired']) {
            return response()->view('referrals.creator-expired', [
                'brand' => $workspace->name, 'sent' => false, 'days' => CreatorToken::TTL_DAYS,
                'canResend' => $affiliate->email !== null && $affiliate->email !== '', 'action' => url()->current(),
            ])->withHeaders(self::HEADERS);
        }

        return response()->view('referrals.creator-portal', $this->current->run($workspace, fn () => $data->for($workspace, $affiliate)))->withHeaders(self::HEADERS);
    }

    /** POST: pedir un enlace nuevo (solo llega al correo registrado del afiliado). */
    public function resend(string $token, SendCreatorPortalLink $send): Response
    {
        [$workspace, $affiliate] = $this->resolve($token) ?? [null, null];
        if ($workspace === null || $affiliate === null) {
            return response()->view('referrals.creator-missing', [], 404)->withHeaders(self::HEADERS);
        }
        $key = 'igr-creator-resend:'.$affiliate->id;
        $sent = false;
        if (! RateLimiter::tooManyAttempts($key, 3) && $affiliate->email !== null && $affiliate->email !== '') {
            RateLimiter::hit($key, 3600);
            $sent = $this->current->run($workspace, fn () => $send->handle($affiliate)['sent']);
        }

        return response()->view('referrals.creator-expired', [
            'brand' => $workspace->name, 'sent' => $sent, 'days' => CreatorToken::TTL_DAYS, 'canResend' => false, 'action' => url()->current(),
        ])->withHeaders(self::HEADERS);
    }

    /** @return array{0: Workspace, 1: Affiliate, 2: array{w: string, a: string, n: string, e: int, expired: bool}}|null */
    private function resolve(string $token): ?array
    {
        $parsed = CreatorToken::parse($token);
        if ($parsed === null) {
            return null;
        }
        $workspace = Workspace::query()->find($parsed['w']);
        if ($workspace === null) {
            return null;
        }
        $affiliate = $this->current->run($workspace, fn () => Affiliate::query()->find($parsed['a']));
        if (! $affiliate instanceof Affiliate || $affiliate->status === 'archived' || $affiliate->portal_nonce === null || ! hash_equals($affiliate->portal_nonce, $parsed['n'])) {
            return null;
        }

        return [$workspace, $affiliate, $parsed];
    }
}
