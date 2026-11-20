<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Channels\Drivers;

use App\Domain\Messaging\Channels\ChannelDriver;
use App\Domain\Messaging\Channels\Data\InboundMessage;
use App\Domain\Messaging\Channels\Data\OutgoingMessage;
use App\Domain\Messaging\Channels\Data\SendResult;
use App\Domain\Messaging\Channels\Data\StatusUpdate;
use App\Domain\Messaging\Channels\Data\WebhookBatch;
use App\Domain\Messaging\Enums\MessageKind;
use App\Domain\Messaging\Models\ChannelAccount;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/** SMS por Twilio (opcional, M07): respaldo, OTP o clientes que lo pidan. */
final class TwilioSmsDriver implements ChannelDriver
{
    public function send(ChannelAccount $account, OutgoingMessage $message): SendResult
    {
        $sid = $account->credential('account_sid');
        $token = $account->credential('auth_token');
        if ($sid === null || $token === null) {
            return SendResult::failed(__('Faltan los datos de Twilio en la cuenta.'), 'not_configured');
        }
        $form = array_filter([
            'To' => $message->to,
            'Body' => (string) $message->text,
            'From' => $account->credential('from'),
            'MessagingServiceSid' => $account->credential('messaging_service_sid'),
            'StatusCallback' => rtrim((string) config('igr-messaging.links_url'), '/').'/webhooks/twilio/'.$account->publicId(),
        ]);
        try {
            $response = Http::asForm()->withBasicAuth($sid, $token)->timeout(15)->acceptJson()
                ->post(rtrim((string) config('igr-messaging.twilio.api_url'), '/').'/2010-04-01/Accounts/'.rawurlencode($sid).'/Messages.json', $form);
        } catch (ConnectionException $e) {
            return SendResult::failed(__('Sin conexión con Twilio: :msg', ['msg' => $e->getMessage()]), 'connection', true);
        }
        if ($response->successful() && is_string($response->json('sid'))) {
            return SendResult::sent((string) $response->json('sid'));
        }
        $code = $response->json('code');
        $msg = $response->json('message');

        return SendResult::failed(is_string($msg) ? $msg : __('Twilio respondió :status', ['status' => $response->status()]), is_scalar($code) ? (string) $code : (string) $response->status(), $response->serverError() || $response->status() === 429);
    }

    /** Firma X-Twilio-Signature: HMAC-SHA1 en base64 de la URL más los parámetros ordenados. */
    public static function validSignature(Request $request, string $authToken): bool
    {
        $params = $request->post();
        ksort($params);
        $data = $request->fullUrl();
        foreach ($params as $key => $value) {
            $data .= $key.(is_scalar($value) ? (string) $value : '');
        }
        $expected = base64_encode(hash_hmac('sha1', $data, $authToken, true));

        return hash_equals($expected, (string) $request->header('X-Twilio-Signature'));
    }

    public function handleWebhook(Request $request, ?ChannelAccount $account): WebhookBatch
    {
        $batch = new WebhookBatch;
        $sid = (string) $request->input('MessageSid', $request->input('SmsSid', ''));
        $status = (string) $request->input('MessageStatus', $request->input('SmsStatus', ''));
        if ($request->filled('Body') && $status === 'received') {
            $batch->inbound[] = ['account' => $account?->external_id, 'message' => new InboundMessage(
                (string) $request->input('From'), $sid, 'text', CarbonImmutable::now(), (string) $request->input('Body'),
            )];
        } elseif ($sid !== '' && $status !== '') {
            $mapped = match ($status) {
                'sent' => 'sent', 'delivered' => 'delivered', 'undelivered', 'failed' => 'failed', default => null,
            };
            if ($mapped !== null) {
                $batch->statuses[] = ['account' => $account?->external_id, 'update' => new StatusUpdate(
                    $sid, $mapped, CarbonImmutable::now(),
                    $mapped === 'failed' ? (string) __('Twilio no pudo entregar el SMS') : null,
                    $request->filled('ErrorCode') ? (string) $request->input('ErrorCode') : null,
                )];
            }
        }

        return $batch;
    }

    public function syncTemplates(ChannelAccount $account): int
    {
        return 0;
    }

    /** Un segmento al precio de Twilio para el país (config `igr-messaging.costs.sms_usd`). */
    public function estimateCost(ChannelAccount $account, string $country, MessageKind $kind): float
    {
        $rates = (array) config('igr-messaging.costs.sms_usd', []);

        return (float) ($rates[strtoupper($country)] ?? $rates['default'] ?? 0.0);
    }

    public function capabilities(): array
    {
        return ['marketing' => true, 'templates' => false, 'free_text' => true, 'media' => false, 'buttons' => false, 'rate_per_second' => (int) config('igr-messaging.twilio.rate_per_second', 10)];
    }
}
