<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Http\Webhooks;

use App\Domain\Messaging\Channels\DriverRegistry;
use App\Domain\Messaging\Channels\ImaginaWhats\ImaginaWhatsSignature;
use App\Domain\Messaging\Models\ChannelAccount;
use App\Domain\Messaging\Sending\WebhookProcessor;
use App\Support\Ids\PrefixedId;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Webhooks de Imagina Whats (`/webhooks/imagina-whats/{cuenta}`): firma `X-Webhook-Signature` sobre el cuerpo crudo
 * con el secreto de la cuenta (5 minutos de tolerancia) y una sola vez por entrega (`X-Webhook-Delivery-Id`, que se
 * repite en los reintentos). Responde rápido: lo pesado (bandeja, agente) va a las colas por los eventos del dominio.
 * Una cuenta borrada o desconectada responde 410 Gone: Imagina Whats apaga ese webhook y deja de insistir.
 */
final class ImaginaWhatsWebhookController
{
    public function __invoke(Request $request, string $account, DriverRegistry $drivers, WebhookProcessor $processor): Response
    {
        $id = PrefixedId::decode(ChannelAccount::ID_PREFIX, $account);
        $model = $id !== null ? ChannelAccount::withoutWorkspaceScope()->where('provider', ChannelAccount::IMAGINA_WHATS)->find($id) : null;
        if ($model === null || $model->status === 'disconnected') {
            return response('', 410);
        }
        $secret = $model->credential('webhook_secret');
        if ($secret === null || ! ImaginaWhatsSignature::valid($request->getContent(), (string) $request->header('X-Webhook-Signature'), $secret)) {
            return response('', 401);
        }
        $delivery = $request->header('X-Webhook-Delivery-Id') ?: $request->input('id');
        $key = is_string($delivery) && $delivery !== '' ? 'igr:iw:delivery:'.$model->id.':'.mb_substr($delivery, 0, 64) : null;
        if ($key !== null && Cache::has($key)) {
            return response('', 200);
        }
        if ($request->input('event') !== 'webhook.test') {
            $processor->apply($drivers->for(ChannelAccount::IMAGINA_WHATS)->handleWebhook($request, $model), [ChannelAccount::IMAGINA_WHATS], $model);
        }
        if ($key !== null) {
            Cache::put($key, 1, now()->addDays(4)); // los reintentos de Imagina Whats llegan hasta 72 h después
        }

        return response('', 200);
    }
}
