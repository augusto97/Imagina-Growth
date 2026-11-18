<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Http;

use App\Domain\Inbox\Actions\AssignConversation;
use App\Domain\Inbox\Models\PushSubscription;
use App\Domain\Workspaces\Models\User;
use App\Support\Ids\PrefixedId;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Ajustes de la bandeja (`settings.inbox` del espacio de trabajo: asignación
 * automática y reglas) y las suscripciones push de la PWA.
 */
final class InboxSettingsController
{
    public function __construct(private readonly CurrentWorkspace $current) {}

    public function show(): JsonResponse
    {
        Gate::authorize('ws.useInbox');
        $ws = $this->current->get();

        return response()->json([
            'auto_assign' => $ws->setting('inbox.auto_assign', true) !== false,
            'rules' => array_values((array) $ws->setting('inbox.rules', [])),
            'push_public_key' => config('services.webpush.public_key'),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        Gate::authorize('ws.manageInbox');
        $data = $request->validate([
            'auto_assign' => ['required', 'boolean'],
            'rules' => ['present', 'array', 'max:30'],
            'rules.*.intent' => ['nullable', Rule::in(['purchase', 'support', 'complaint', 'other'])],
            'rules.*.label' => ['nullable', 'string', 'max:32'],
            'rules.*.user' => ['required', 'string', 'max:40'],
        ]);
        $agents = AssignConversation::agents($this->current->id());
        $rules = [];
        foreach ((array) $data['rules'] as $i => $rule) {
            $user = PrefixedId::decode(User::ID_PREFIX, (string) $rule['user']);
            if ($user === null || ! in_array($user, $agents, true)) {
                throw ValidationException::withMessages(["rules.{$i}.user" => __('Esa persona no atiende la bandeja.')]);
            }
            if (($rule['intent'] ?? null) === null && ($rule['label'] ?? null) === null) {
                throw ValidationException::withMessages(["rules.{$i}" => __('Cada regla necesita una intención o una etiqueta.')]);
            }
            $rules[] = array_filter(['intent' => $rule['intent'] ?? null, 'label' => $rule['label'] ?? null, 'user' => (string) $rule['user']], fn ($v) => $v !== null);
        }
        $ws = $this->current->get();
        $settings = (array) ($ws->settings ?? []);
        $settings['inbox'] = ['auto_assign' => (bool) $data['auto_assign'], 'rules' => $rules];
        $ws->forceFill(['settings' => $settings])->save();

        return $this->show();
    }

    /** POST inbox/push/subscriptions {endpoint, keys: {p256dh, auth}} */
    public function subscribe(Request $request): JsonResponse
    {
        Gate::authorize('ws.useInbox');
        $data = $request->validate([
            'endpoint' => ['required', 'url:https', 'max:1000'],
            'keys.p256dh' => ['required', 'string', 'max:200'],
            'keys.auth' => ['required', 'string', 'max:100'],
        ]);
        $userId = (string) $request->user()?->getAuthIdentifier();
        $hash = hash('sha256', (string) $data['endpoint']);
        $sub = PushSubscription::query()->firstOrNew(['user_id' => $userId, 'endpoint_hash' => $hash]);
        $sub->fill(['workspace_id' => $this->current->id(), 'endpoint' => (string) $data['endpoint'],
            'keys' => ['p256dh' => (string) $data['keys']['p256dh'], 'auth' => (string) $data['keys']['auth']],
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255)])->save();

        return response()->json(['id' => $sub->publicId()], 201);
    }

    /** DELETE inbox/push/subscriptions {endpoint} */
    public function unsubscribe(Request $request): JsonResponse
    {
        Gate::authorize('ws.useInbox');
        $data = $request->validate(['endpoint' => ['required', 'string', 'max:1000']]);
        PushSubscription::query()->where('user_id', (string) $request->user()?->getAuthIdentifier())->where('endpoint_hash', hash('sha256', (string) $data['endpoint']))->delete();

        return response()->json(['ok' => true]);
    }
}
