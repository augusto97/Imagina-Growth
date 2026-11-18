<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Http\Webhooks;

use App\Domain\Messaging\Channels\DriverRegistry;
use App\Domain\Messaging\Sending\WebhookProcessor;
use App\Domain\Messaging\Support\SnsMessageVerifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Eventos de SES por Amazon SNS (entregas, rebotes, quejas, aperturas). Se
 * verifica la firma de SNS; la confirmación de la suscripción se acepta sola.
 */
final class SesWebhookController
{
    public function __invoke(Request $request, SnsMessageVerifier $verifier, DriverRegistry $drivers, WebhookProcessor $processor): Response
    {
        $message = json_decode($request->getContent(), true);
        if (! is_array($message) || ! $verifier->verify($message)) {
            Log::warning('igr.messaging.sns_bad_signature');

            return response('', 403);
        }
        $type = (string) ($message['Type'] ?? '');
        if ($type === 'SubscriptionConfirmation') {
            $url = (string) ($message['SubscribeURL'] ?? '');
            if (preg_match('#^https://sns\.[a-z0-9-]+\.amazonaws\.com(\.cn)?/#', $url) === 1) {
                Http::timeout(10)->get($url);
            }

            return response('', 200);
        }
        if ($type === 'Notification') {
            $processor->apply($drivers->for('ses')->handleWebhook($request, null), ['ses']);
        }

        return response('', 200);
    }
}
