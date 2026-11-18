<?php

declare(strict_types=1);

namespace App\Domain\Webhooks\Actions;

use App\Domain\Webhooks\Models\WebhookSubscription;
use App\Domain\Webhooks\Support\SafeUrl;
use App\Domain\Webhooks\Support\SubscriptionIndex;
use App\Domain\Webhooks\Support\WebhookEvents;
use App\Domain\Workspaces\Actions\RecordAuditLog;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Crea o cambia una suscripción de webhooks (panel y API). Valida la URL (HTTPS pública) y
 * los eventos; al crear o rotar genera el secreto `whsec_…`, que se muestra una sola vez.
 */
final class SaveWebhookSubscription
{
    public const MAX_PER_WORKSPACE = 25;

    public function __construct(private readonly RecordAuditLog $audit) {}

    /**
     * @param  array{name?: string|null, url?: string, events?: list<string>, include_pii?: bool, status?: string, preset?: string|null}  $data
     * @return array{0: WebhookSubscription, 1: string|null} la suscripción y el secreto nuevo (solo al crear)
     */
    public function handle(string $workspaceId, ?WebhookSubscription $sub, array $data, ?string $userId, string $actor): array
    {
        if (isset($data['url']) && ($problem = SafeUrl::problem((string) $data['url'])) !== null) {
            throw ValidationException::withMessages(['url' => $problem]);
        }
        if (isset($data['events'])) {
            $events = array_values(array_unique(array_map('strval', $data['events'])));
            $bad = array_values(array_filter($events, fn (string $e) => ! WebhookEvents::isValidPattern($e)));
            if ($events === [] || $bad !== []) {
                throw ValidationException::withMessages(['events' => $bad === [] ? __('Elige al menos un evento.') : __('Eventos desconocidos: :list', ['list' => implode(', ', $bad)])]);
            }
            $data['events'] = $events;
        }
        $secret = null;
        if ($sub === null) {
            if (WebhookSubscription::query()->count() >= self::MAX_PER_WORKSPACE) {
                throw ValidationException::withMessages(['url' => __('Llegaste al máximo de :n webhooks por espacio de trabajo.', ['n' => self::MAX_PER_WORKSPACE])]);
            }
            if (! isset($data['url'], $data['events'])) {
                throw ValidationException::withMessages(['url' => __('Falta la URL o los eventos.')]);
            }
            $secret = 'whsec_'.Str::random(40);
            $sub = new WebhookSubscription([
                'workspace_id' => $workspaceId, 'secret' => $secret, 'status' => WebhookSubscription::ACTIVE,
                'consecutive_failures' => 0, 'created_by' => $userId,
            ]);
        }
        foreach (['name', 'url', 'events', 'include_pii', 'preset'] as $field) {
            if (array_key_exists($field, $data)) {
                $sub->setAttribute($field, $data[$field]);
            }
        }
        if (isset($data['status']) && in_array($data['status'], [WebhookSubscription::ACTIVE, WebhookSubscription::PAUSED], true)) {
            $sub->status = $data['status'];
            if ($data['status'] === WebhookSubscription::ACTIVE) {
                $sub->consecutive_failures = 0;
            }
        }
        $isNew = ! $sub->exists;
        $sub->save();
        SubscriptionIndex::forget($workspaceId);
        $this->audit->handle($isNew ? 'webhook.created' : 'webhook.updated', $sub, [
            'host' => parse_url($sub->url, PHP_URL_HOST), 'events' => $sub->events, 'include_pii' => $sub->include_pii, 'status' => $sub->status, 'by' => $actor,
        ], $workspaceId);

        return [$sub, $secret];
    }

    public function rotate(WebhookSubscription $sub): string
    {
        $secret = 'whsec_'.Str::random(40);
        $sub->forceFill(['secret' => $secret])->save();
        $this->audit->handle('webhook.secret_rotated', $sub, ['host' => parse_url($sub->url, PHP_URL_HOST)], $sub->workspace_id);

        return $secret;
    }

    public function delete(WebhookSubscription $sub, string $actor): void
    {
        $sub->delete();
        SubscriptionIndex::forget($sub->workspace_id);
        $this->audit->handle('webhook.deleted', $sub, ['host' => parse_url($sub->url, PHP_URL_HOST), 'by' => $actor], $sub->workspace_id);
    }
}
