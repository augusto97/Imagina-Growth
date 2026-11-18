<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Http\Webhooks;

use App\Domain\Messaging\Channels\DriverRegistry;
use App\Domain\Messaging\Channels\Drivers\TwilioSmsDriver;
use App\Domain\Messaging\Models\ChannelAccount;
use App\Domain\Messaging\Sending\WebhookProcessor;
use App\Support\Ids\PrefixedId;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Estados y SMS entrantes de Twilio, verificados con X-Twilio-Signature. */
final class TwilioWebhookController
{
    public function __invoke(Request $request, string $account, DriverRegistry $drivers, WebhookProcessor $processor): Response
    {
        $id = PrefixedId::decode(ChannelAccount::ID_PREFIX, $account);
        $model = $id !== null ? ChannelAccount::withoutWorkspaceScope()->where('provider', 'twilio')->find($id) : null;
        $token = $model?->credential('auth_token');
        if ($model === null || $token === null || ! TwilioSmsDriver::validSignature($request, $token)) {
            return response('', 403);
        }
        $processor->apply($drivers->for('twilio')->handleWebhook($request, $model), ['twilio'], $model);

        return response('<?xml version="1.0" encoding="UTF-8"?><Response/>', 200)->header('Content-Type', 'text/xml');
    }
}
