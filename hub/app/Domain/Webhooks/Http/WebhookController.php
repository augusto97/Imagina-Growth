<?php

declare(strict_types=1);

namespace App\Domain\Webhooks\Http;

use App\Domain\Webhooks\Actions\SaveWebhookSubscription;
use App\Domain\Webhooks\Actions\SendWebhook;
use App\Domain\Webhooks\Models\WebhookDelivery;
use App\Domain\Webhooks\Models\WebhookSubscription;
use App\Domain\Webhooks\Support\WebhookPayload;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Webhooks en el panel (M19): crear, editar, pausar, rotar el secreto, probar, ver el
 * registro de entregas y reenviar. Solo `manageApi`.
 */
final class WebhookController
{
    public function __construct(private readonly CurrentWorkspace $current) {}

    /** GET webhooks */
    public function index(): JsonResponse
    {
        Gate::authorize('ws.manageApi');

        return new JsonResponse(['data' => WebhookSubscription::query()->orderByDesc('created_at')->get()->map(fn (WebhookSubscription $s) => WebhookPresenter::subscription($s))->values()->all()]);
    }

    /** POST webhooks — el secreto se muestra una sola vez. */
    public function store(Request $request, SaveWebhookSubscription $save): JsonResponse
    {
        Gate::authorize('ws.manageApi');
        $data = $request->validate($this->rules(true));
        $userId = $request->user()?->getAuthIdentifier();
        [$sub, $secret] = $save->handle($this->current->id(), null, $data, is_string($userId) ? $userId : null, 'user');

        return new JsonResponse(['data' => WebhookPresenter::subscription($sub), 'secret' => $secret], 201);
    }

    /** PATCH webhooks/{webhook} */
    public function update(Request $request, string $webhook, SaveWebhookSubscription $save): JsonResponse
    {
        Gate::authorize('ws.manageApi');
        $sub = $this->find($webhook);
        $data = $request->validate($this->rules(false));
        [$sub] = $save->handle($this->current->id(), $sub, $data, null, 'user');

        return new JsonResponse(['data' => WebhookPresenter::subscription($sub)]);
    }

    /** DELETE webhooks/{webhook} */
    public function destroy(string $webhook, SaveWebhookSubscription $save): JsonResponse
    {
        Gate::authorize('ws.manageApi');
        $save->delete($this->find($webhook), 'user');

        return new JsonResponse(['ok' => true]);
    }

    /** POST webhooks/{webhook}/rotate-secret */
    public function rotate(string $webhook, SaveWebhookSubscription $save): JsonResponse
    {
        Gate::authorize('ws.manageApi');

        return new JsonResponse(['secret' => $save->rotate($this->find($webhook))]);
    }

    /** POST webhooks/{webhook}/ping — envía `webhook.ping` ya y devuelve el resultado. */
    public function ping(string $webhook, SendWebhook $send): JsonResponse
    {
        Gate::authorize('ws.manageApi');
        $sub = $this->find($webhook);
        $eventId = (string) Str::uuid7();
        $payload = WebhookPayload::build($sub, 'webhook.ping', $eventId, now()->toIso8601String(), null, null, [
            'message' => __('Prueba de Imagina Growth: si ves esto, el webhook funciona.'),
        ]);
        $delivery = WebhookDelivery::query()->create([
            'workspace_id' => $sub->workspace_id, 'subscription_id' => $sub->id, 'event' => 'webhook.ping', 'event_id' => $eventId,
            'payload' => $payload, 'attempt' => 1, 'kind' => 'ping', 'created_at' => now(),
        ]);
        $result = $send->handle($delivery->id) ?? $delivery;

        return new JsonResponse(['data' => WebhookPresenter::delivery($result->refresh())]);
    }

    /** GET webhooks/{webhook}/deliveries?status=failed */
    public function deliveries(Request $request, string $webhook): JsonResponse
    {
        Gate::authorize('ws.manageApi');
        $sub = $this->find($webhook);
        $query = WebhookDelivery::query()->where('subscription_id', $sub->id)->orderByDesc('id');
        if ($request->query('status') === 'failed') {
            $query->where(fn ($q) => $q->whereNotNull('error')->orWhere('status_code', '>=', 300));
        }
        if (is_string($before = $request->query('before')) && ctype_digit($before)) {
            $query->where('id', '<', (int) $before);
        }
        $rows = $query->limit(51)->get();

        return new JsonResponse([
            'data' => $rows->take(50)->map(fn (WebhookDelivery $d) => WebhookPresenter::delivery($d))->values()->all(),
            'next_before' => $rows->count() > 50 ? (string) $rows->get(49)?->id : null,
        ]);
    }

    /** POST webhook-deliveries/{delivery}/replay — reenvía el mismo cuerpo ya. */
    public function replay(string $delivery, SendWebhook $send): JsonResponse
    {
        Gate::authorize('ws.manageApi');
        $id = (int) preg_replace('/^whd_/', '', $delivery);
        /** @var WebhookDelivery $original */
        $original = WebhookDelivery::query()->findOrFail($id);
        $attempt = (int) WebhookDelivery::query()->where('subscription_id', $original->subscription_id)->where('event_id', $original->event_id)->max('attempt') + 1;
        if ($attempt > 255) {
            return new JsonResponse(['message' => __('Este evento ya se reenvió demasiadas veces.')], 422);
        }
        $copy = WebhookDelivery::query()->create([
            'workspace_id' => $original->workspace_id, 'subscription_id' => $original->subscription_id, 'event' => $original->event,
            'event_id' => $original->event_id, 'payload' => $original->payload, 'attempt' => $attempt, 'kind' => 'replay', 'created_at' => now(),
        ]);
        $result = $send->handle($copy->id) ?? $copy;

        return new JsonResponse(['data' => WebhookPresenter::delivery($result->refresh())]);
    }

    /** @return array<string, list<mixed>> */
    private function rules(bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return [
            'name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'url' => [$req, 'url', 'max:500'],
            'events' => [$req, 'array', 'min:1', 'max:50'],
            'events.*' => ['string', 'max:64'],
            'include_pii' => ['sometimes', 'boolean'],
            'status' => ['sometimes', Rule::in([WebhookSubscription::ACTIVE, WebhookSubscription::PAUSED])],
        ];
    }

    private function find(string $publicId): WebhookSubscription
    {
        return WebhookSubscription::findByPublicId($publicId) ?? abort(404);
    }
}
