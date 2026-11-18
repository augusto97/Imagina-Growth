<?php

declare(strict_types=1);

namespace App\Domain\Webhooks\Http;

use Dedoc\Scramble\Attributes\Group;
use App\Domain\Api\Support\ApiContext;
use App\Domain\Api\Support\ApiError;
use App\Domain\Webhooks\Actions\SaveWebhookSubscription;
use App\Domain\Webhooks\Models\WebhookSubscription;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Suscripciones de webhooks desde la API (REST hooks): Zapier, Make y n8n se suscriben al
 * activar un disparador y se dan de baja al apagarlo.
 */
#[Group('Webhooks', weight: 8)]
final class WebhookApiController
{
    public function __construct(private readonly CurrentWorkspace $current) {}

    /**
     * Listar webhooks
     *
     * Requiere `webhooks:manage`.
     */
    public function index(): JsonResponse
    {
        return new JsonResponse(['data' => WebhookSubscription::query()->orderByDesc('created_at')->get()->map(fn (WebhookSubscription $s) => WebhookPresenter::subscription($s))->values()->all()]);
    }

    /**
     * Suscribir una URL
     *
     * `url` (HTTPS pública), `events` (p. ej. `order.paid`, `lead.*`) y opcional `name`.
     * Con `include_pii: true` el cuerpo trae correo y teléfono: exige además `contacts:pii`.
     * Responde el `secret` para verificar la firma `X-IGR-Signature`; solo se muestra aquí.
     * Requiere `webhooks:manage`. Acepta `Idempotency-Key`.
     */
    public function store(Request $request, SaveWebhookSubscription $save): JsonResponse
    {
        $data = $request->validate([
            'url' => ['required', 'url', 'max:500'],
            'events' => ['required', 'array', 'min:1', 'max:50'],
            'events.*' => ['string', 'max:64'],
            'name' => ['nullable', 'string', 'max:120'],
            'include_pii' => ['sometimes', 'boolean'],
        ]);
        if (($data['include_pii'] ?? false) && ! ApiContext::can($request, 'contacts:pii')) {
            return ApiError::make(403, 'missing_scope', __('Para recibir datos personales el token necesita el alcance «contacts:pii».'));
        }
        $client = ApiContext::client($request);
        [$sub, $secret] = $save->handle($this->current->id(), null, $data + ['name' => $data['name'] ?? $client->name], null, 'api:'.$client->publicId());

        return new JsonResponse(['data' => WebhookPresenter::subscription($sub) + ['secret' => $secret]], 201);
    }

    /**
     * Borrar un webhook
     *
     * Requiere `webhooks:manage`.
     */
    public function destroy(Request $request, string $webhook, SaveWebhookSubscription $save): JsonResponse
    {
        $sub = WebhookSubscription::findByPublicId($webhook);
        if ($sub === null) {
            return ApiError::make(404, 'not_found', __('No existe ese webhook.'));
        }
        $save->delete($sub, 'api:'.ApiContext::client($request)->publicId());

        return new JsonResponse(null, 204);
    }
}
