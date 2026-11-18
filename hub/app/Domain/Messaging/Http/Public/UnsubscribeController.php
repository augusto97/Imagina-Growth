<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Http\Public;

use App\Domain\Messaging\Sending\UnsubscribeService;
use App\Domain\Messaging\Support\ContactLinkToken;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * /u/{token}: baja del marketing de un canal. GET muestra la confirmación; POST
 * da de baja. El POST de un clic de RFC 8058 (`List-Unsubscribe=One-Click`, lo
 * manda Gmail o Yahoo sin cookies ni CSRF) responde 200 vacío.
 */
final class UnsubscribeController
{
    public function show(string $token): Response
    {
        $ctx = ContactLinkContext::fromToken($token);
        if ($ctx === null) {
            return response()->view('messaging.link-missing', [], 404);
        }

        return response()->view('messaging.unsubscribe', [
            'brand' => $ctx->workspace->name,
            'channel' => $ctx->channel,
            'action' => url()->current(),
            'preferences' => ContactLinkToken::preferencesUrl($ctx->workspace->id, $ctx->contact->id, $ctx->channel, $ctx->message?->publicId()),
        ])->withHeaders(['Cache-Control' => 'no-store, private', 'X-Robots-Tag' => 'noindex']);
    }

    public function store(Request $request, string $token, CurrentWorkspace $current, UnsubscribeService $unsubscribe): Response
    {
        $ctx = ContactLinkContext::fromToken($token);
        if ($ctx === null) {
            return response()->view('messaging.link-missing', [], 404);
        }
        $oneClick = $request->input('List-Unsubscribe') === 'One-Click' || str_contains((string) $request->getContent(), 'List-Unsubscribe=One-Click');
        $scope = $request->boolean('all') ? 'all' : 'channel';
        $current->run($ctx->workspace, fn () => $unsubscribe->unsubscribe(
            $ctx->workspace, $ctx->contact->id, $ctx->channel, $scope, $oneClick ? 'one_click' : 'link', $ctx->message,
            ['ip_hash' => $request->ip() !== null ? hash_hmac('sha256', (string) $request->ip(), (string) config('app.key')) : null],
        ));
        if ($oneClick) {
            return response('', 200);
        }

        return response()->view('messaging.unsubscribed', [
            'brand' => $ctx->workspace->name,
            'all' => $scope === 'all',
            'channel' => $ctx->channel,
            'preferences' => ContactLinkToken::preferencesUrl($ctx->workspace->id, $ctx->contact->id, $ctx->channel, $ctx->message?->publicId()),
        ])->withHeaders(['Cache-Control' => 'no-store, private', 'X-Robots-Tag' => 'noindex']);
    }
}
