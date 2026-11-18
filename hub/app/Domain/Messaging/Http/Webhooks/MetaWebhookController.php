<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Http\Webhooks;

use App\Domain\Messaging\Channels\DriverRegistry;
use App\Domain\Messaging\Sending\WebhookProcessor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Webhook de la app de Meta: verificación de la suscripción (GET) y eventos
 * firmados con X-Hub-Signature-256 (POST). El campo `object` dice de dónde vienen:
 * `whatsapp_business_account` (Cloud API), `page` (Messenger) o `instagram`
 * (Instagram Direct, Fase 5).
 */
final class MetaWebhookController
{
    public function verify(Request $request): Response
    {
        $token = config('igr-messaging.meta.webhook_verify_token');
        if ($request->query('hub_mode') === 'subscribe' && is_string($token) && $token !== '' && hash_equals($token, (string) $request->query('hub_verify_token'))) {
            return response((string) $request->query('hub_challenge'), 200)->header('Content-Type', 'text/plain');
        }

        return response('', 403);
    }

    public function handle(Request $request, DriverRegistry $drivers, WebhookProcessor $processor): Response
    {
        if (! self::validSignature($request)) {
            Log::warning('igr.messaging.meta_bad_signature');

            return response('', 403);
        }
        $provider = match ($request->input('object')) {
            'page' => 'meta_messenger',
            'instagram' => 'meta_instagram',
            default => 'meta_cloud',
        };
        $processor->apply($drivers->for($provider)->handleWebhook($request, null), [$provider]);

        return response('EVENT_RECEIVED', 200);
    }

    public static function validSignature(Request $request): bool
    {
        $secret = config('igr-messaging.meta.app_secret');
        $header = (string) $request->header('X-Hub-Signature-256');
        if (! is_string($secret) || $secret === '' || ! str_starts_with($header, 'sha256=')) {
            return false;
        }

        return hash_equals('sha256='.hash_hmac('sha256', $request->getContent(), $secret), $header);
    }
}
