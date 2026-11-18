<?php

declare(strict_types=1);

namespace App\Domain\Measurement\Http\Controllers;

use App\Domain\Measurement\AdSpend\ImportAdSpend;
use App\Domain\Measurement\Conversions\TargetRegistry;
use App\Domain\Measurement\Enums\DestinationMode;
use App\Domain\Measurement\Enums\Platform;
use App\Domain\Measurement\Models\AdDestination;
use App\Domain\Measurement\Support\DestinationFields;
use App\Domain\Workspaces\Actions\RecordAuditLog;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Destinos de conversiones (M06). Ver: viewDashboards. Crear, cambiar o
 * borrar: manageWorkspace (owner y admin). Los secretos nunca vuelven.
 */
final class DestinationController
{
    public function __construct(private readonly CurrentWorkspace $current, private readonly RecordAuditLog $audit) {}

    /** GET /api/w/{ws}/measurement/destinations */
    public function index(TargetRegistry $targets, ImportAdSpend $spend): JsonResponse
    {
        Gate::authorize('ws.viewDashboards');

        return response()->json([
            'destinations' => AdDestination::query()->orderBy('created_at')->get()
                ->map(fn (AdDestination $d) => self::present($d, $targets, $spend))->values(),
            'events' => self::eventCatalog(),
        ]);
    }

    /** POST /api/w/{ws}/measurement/destinations */
    public function store(Request $request, TargetRegistry $targets, ImportAdSpend $spend): JsonResponse
    {
        Gate::authorize('ws.manageWorkspace');
        $data = $this->validated($request, null);
        $platform = Platform::from($data['platform']);
        $config = $this->credentials($platform, $data['credentials'] ?? [], []);
        $this->assertComplete($platform, $config);

        $destination = AdDestination::query()->create([
            'workspace_id' => $this->current->id(),
            'platform' => $platform,
            'name' => $data['name'] ?? $platform->label(),
            'config' => $config,
            'events_enabled' => $data['events_enabled'] ?? null,
            'mode' => DestinationMode::from($data['mode'] ?? 'server_only'),
            'pixel_id' => DestinationFields::pixelId($platform, $config),
            'test_event_code' => $data['test_event_code'] ?? null,
            'status' => 'active',
        ]);
        $this->audit->handle('measurement.destination_created', $destination, ['platform' => $platform->value, 'mode' => $destination->mode->value, 'credentials' => array_keys($config)], $this->current->id());

        return response()->json(self::present($destination, $targets, $spend), 201);
    }

    /** PATCH /api/w/{ws}/measurement/destinations/{destination} */
    public function update(Request $request, string $destination, TargetRegistry $targets, ImportAdSpend $spend): JsonResponse
    {
        Gate::authorize('ws.manageWorkspace');
        $model = $this->find($destination);
        $data = $this->validated($request, $model);
        $config = $this->credentials($model->platform, $data['credentials'] ?? [], $model->config);
        $this->assertComplete($model->platform, $config);

        $model->fill([
            'name' => $data['name'] ?? $model->name,
            'config' => $config,
            'pixel_id' => DestinationFields::pixelId($model->platform, $config),
        ]);
        foreach (['events_enabled', 'test_event_code', 'status'] as $field) {
            if (array_key_exists($field, $data)) {
                $model->setAttribute($field, $data[$field]);
            }
        }
        if (isset($data['mode'])) {
            $model->mode = DestinationMode::from($data['mode']);
        }
        $model->save();
        $this->audit->handle('measurement.destination_updated', $model, [
            'fields' => array_values(array_diff(array_keys($data), ['credentials'])),
            'credentials' => array_keys(array_filter((array) ($data['credentials'] ?? []), fn ($v) => is_string($v) && $v !== '')),
        ], $this->current->id());

        return response()->json(self::present($model, $targets, $spend));
    }

    /** DELETE /api/w/{ws}/measurement/destinations/{destination} */
    public function destroy(string $destination): JsonResponse
    {
        Gate::authorize('ws.manageWorkspace');
        $model = $this->find($destination);
        $model->delete();
        $this->audit->handle('measurement.destination_deleted', $model, ['platform' => $model->platform->value], $this->current->id());

        return response()->json(['ok' => true]);
    }

    /** POST /api/w/{ws}/measurement/destinations/{destination}/test — «probar conexión» sin registrar conversiones reales. */
    public function test(string $destination, TargetRegistry $targets): JsonResponse
    {
        Gate::authorize('ws.manageWorkspace');
        $model = $this->find($destination);
        $results = [];
        foreach ($targets->all() as $target) {
            if ($target->platform() !== $model->platform || ! $this->configured($target->key(), $model)) {
                continue;
            }
            $result = $target->test($model);
            $results[] = ['target' => $target->key(), 'ok' => $result->isDelivered(), 'message' => $result->isDelivered() ? __('Conexión correcta.') : $result->message];
        }
        if ($results === []) {
            $results[] = ['target' => null, 'ok' => false, 'message' => __('Faltan credenciales para probar este destino.')];
        }

        return response()->json(['results' => $results, 'ok' => ! in_array(false, array_column($results, 'ok'), true)]);
    }

    /**
     * Catálogo de eventos que se pueden enviar y su nombre en cada plataforma.
     *
     * @return list<array<string, mixed>>
     */
    public static function eventCatalog(): array
    {
        $out = [];
        foreach ((array) config('igr-measurement.events') as $event => $map) {
            $out[] = ['event' => (string) $event] + (array) $map;
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public static function present(AdDestination $d, TargetRegistry $targets, ImportAdSpend $spend): array
    {
        $names = array_map(fn (array $e) => (string) $e['event'], self::eventCatalog());
        $events = array_values(array_filter($names, fn (string $event) => $d->sends($event)));
        $endpoints = [];
        foreach ($targets->all() as $target) {
            if ($target->platform() === $d->platform) {
                $endpoints[$target->key()] = array_filter($names, fn (string $e) => $target->supports($d, $e)) !== [];
            }
        }

        return [
            'id' => $d->publicId(),
            'platform' => $d->platform->value,
            'name' => $d->name,
            'mode' => $d->mode->value,
            'pixel_id' => $d->pixel_id,
            'events_enabled' => $events,
            'test_event_code' => $d->test_event_code,
            'status' => $d->status,
            'last_error' => $d->last_error,
            'last_delivered_at' => $d->last_delivered_at?->toIso8601String(),
            'credentials' => DestinationFields::present($d->platform, $d->config ?? []),
            'endpoints' => $endpoints,
            'spend_import' => $spend->supports($d),
            'created_at' => $d->getAttribute('created_at')?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?AdDestination $existing): array
    {
        $platform = $existing?->platform->value ?? (string) $request->input('platform');
        $fields = Platform::tryFrom($platform) !== null ? array_keys(DestinationFields::for(Platform::from($platform))) : [];
        $rules = [
            'platform' => $existing === null ? ['required', Rule::enum(Platform::class)] : ['prohibited'],
            'name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'mode' => ['sometimes', Rule::enum(DestinationMode::class)],
            'events_enabled' => ['sometimes', 'nullable', 'array'],
            'events_enabled.*' => ['string', Rule::in(array_keys(config('igr-measurement.events')))],
            'test_event_code' => ['sometimes', 'nullable', 'string', 'max:64'],
            'status' => ['sometimes', 'in:active,paused'],
            'credentials' => ['sometimes', 'array'],
        ];
        foreach ($fields as $field) {
            $rules['credentials.'.$field] = ['nullable', 'string', 'max:2048'];
        }

        return $request->validate($rules);
    }

    /**
     * Mezcla las credenciales nuevas sobre las guardadas: un campo vacío o ausente conserva el valor.
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $current
     * @return array<string, string>
     */
    private function credentials(Platform $platform, array $input, array $current): array
    {
        $out = [];
        foreach (array_keys(DestinationFields::for($platform)) as $field) {
            $value = $input[$field] ?? null;
            if (is_string($value) && trim($value) !== '') {
                $out[$field] = trim($value);
            } elseif (is_string($current[$field] ?? null) && $current[$field] !== '') {
                $out[$field] = $current[$field];
            }
        }

        return $out;
    }

    /** @param array<string, string> $config */
    private function assertComplete(Platform $platform, array $config): void
    {
        $missing = match ($platform) {
            Platform::Meta => array_diff(['dataset_id', 'access_token'], array_keys($config)),
            Platform::TikTok => array_diff(['pixel_code', 'access_token'], array_keys($config)),
            Platform::Google => (isset($config['measurement_id'], $config['api_secret']) || isset($config['ads_customer_id'], $config['oauth_refresh_token'])) ? [] : ['measurement_id', 'api_secret'],
        };
        if ($missing !== []) {
            throw ValidationException::withMessages(array_fill_keys(array_map(fn ($f) => 'credentials.'.$f, $missing), __('Este dato es obligatorio para la plataforma.')));
        }
    }

    private function configured(string $targetKey, AdDestination $destination): bool
    {
        return match ($targetKey) {
            'meta_capi' => $destination->credential('dataset_id') !== null && $destination->credential('access_token') !== null,
            'ga4' => $destination->credential('measurement_id') !== null && $destination->credential('api_secret') !== null,
            'google_data_manager' => $destination->credential('ads_customer_id') !== null && $destination->credential('oauth_refresh_token') !== null,
            'tiktok_events' => $destination->credential('pixel_code') !== null && $destination->credential('access_token') !== null,
            default => false,
        };
    }

    private function find(string $publicId): AdDestination
    {
        return AdDestination::findByPublicId($publicId) ?? abort(404);
    }
}
