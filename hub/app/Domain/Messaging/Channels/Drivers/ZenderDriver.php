<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Channels\Drivers;

use App\Domain\Messaging\Channels\ChannelDriver;
use App\Domain\Messaging\Channels\Data\InboundMessage;
use App\Domain\Messaging\Channels\Data\OutgoingMessage;
use App\Domain\Messaging\Channels\Data\SendResult;
use App\Domain\Messaging\Channels\Data\WebhookBatch;
use App\Domain\Messaging\Enums\MessageKind;
use App\Domain\Messaging\Models\ChannelAccount;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Zender: WhatsApp no oficial (cumplimiento.md §5.2). Solo conversaciones 1:1 y
 * transaccionales de bajo volumen, con topes fijos por hora y por día y nunca
 * marketing (SendPolicy lo niega antes de llegar aquí). Solo texto: las
 * plantillas de WhatsApp se envían ya compuestas. API **[verificar]** contra la
 * instalación de Zender del cliente.
 */
final class ZenderDriver implements ChannelDriver
{
    public function send(ChannelAccount $account, OutgoingMessage $message): SendResult
    {
        if ($message->isMarketing) {
            return SendResult::failed(__('La conexión no oficial de WhatsApp no permite marketing.'), 'unofficial_marketing');
        }
        $base = $account->credential('base_url');
        $secret = $account->credential('api_secret');
        $device = $account->credential('account_id');
        if ($base === null || $secret === null || $device === null) {
            return SendResult::failed(__('Faltan los datos de Zender en la cuenta.'), 'not_configured');
        }
        try {
            $response = Http::asForm()->timeout(20)->acceptJson()->post(rtrim($base, '/').'/api/send/whatsapp', [
                'secret' => $secret,
                'account' => $device,
                'recipient' => $message->to,
                'type' => 'text',
                'message' => (string) $message->text,
            ]);
        } catch (ConnectionException $e) {
            return SendResult::failed(__('Sin conexión con Zender: :msg', ['msg' => $e->getMessage()]), 'connection', true);
        }
        $id = $response->json('data.messageId') ?? $response->json('data.id');
        if ($response->successful() && (int) $response->json('status', 200) === 200 && is_scalar($id)) {
            return SendResult::sent((string) $id);
        }
        $error = $response->json('message');

        return SendResult::failed(is_string($error) ? $error : __('Zender respondió :status', ['status' => $response->status()]), (string) $response->status(), $response->serverError());
    }

    public function handleWebhook(Request $request, ?ChannelAccount $account): WebhookBatch
    {
        $batch = new WebhookBatch;
        $data = (array) $request->input('data', []);
        if (($request->input('type') === 'whatsapp' || isset($data['phone'])) && isset($data['phone'])) {
            $batch->inbound[] = ['account' => $account?->external_id, 'message' => new InboundMessage(
                '+'.ltrim((string) $data['phone'], '+'),
                (string) ($data['id'] ?? $data['wid'] ?? bin2hex(random_bytes(8))),
                'text',
                isset($data['timestamp']) ? CarbonImmutable::createFromTimestamp((int) $data['timestamp']) : CarbonImmutable::now(),
                isset($data['message']) ? (string) $data['message'] : null,
            )];
        }

        return $batch;
    }

    public function syncTemplates(ChannelAccount $account): int
    {
        return 0;
    }

    public function estimateCost(ChannelAccount $account, string $country, MessageKind $kind): float
    {
        return 0.0;
    }

    public function capabilities(): array
    {
        return ['marketing' => false, 'templates' => false, 'free_text' => true, 'media' => false, 'buttons' => false, 'rate_per_second' => 1];
    }
}
