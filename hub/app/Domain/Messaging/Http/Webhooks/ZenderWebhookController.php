<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Http\Webhooks;

use App\Domain\Messaging\Channels\DriverRegistry;
use App\Domain\Messaging\Models\ChannelAccount;
use App\Domain\Messaging\Sending\WebhookProcessor;
use App\Support\Ids\PrefixedId;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Mensajes entrantes de Zender, verificados con el secreto propio de la cuenta. */
final class ZenderWebhookController
{
    public function __invoke(Request $request, string $account, DriverRegistry $drivers, WebhookProcessor $processor): Response
    {
        $id = PrefixedId::decode(ChannelAccount::ID_PREFIX, $account);
        $model = $id !== null ? ChannelAccount::withoutWorkspaceScope()->where('provider', 'zender')->find($id) : null;
        $secret = $model?->credential('webhook_secret');
        if ($model === null || $secret === null || ! hash_equals($secret, (string) $request->input('secret'))) {
            return response('', 403);
        }
        $processor->apply($drivers->for('zender')->handleWebhook($request, $model), ['zender'], $model);

        return response('', 200);
    }
}
