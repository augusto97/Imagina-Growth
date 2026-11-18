<?php

declare(strict_types=1);

namespace App\Domain\Api\Http\V1;

use App\Domain\Api\Actions\ResolveApiContact;
use App\Domain\Api\Support\ApiError;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Services\Contracts\LeadIntake;
use App\Domain\Services\Data\LeadIntakeInput;
use App\Domain\Services\Leads\Actions\ChangeLeadStage;
use App\Domain\Services\Leads\Models\Lead;
use App\Support\Ids\PrefixedId;
use App\Support\Tenancy\CurrentWorkspace;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Leads (M18): crearlos desde otro sistema y moverlos por el pipeline (Imagina CRM, Zapier).
 */
#[Group('Leads', weight: 6)]
final class LeadsController
{
    use Paginates;

    public function __construct(private readonly CurrentWorkspace $current) {}

    /**
     * Listar leads
     *
     * Filtros: `stage`, `contact_id`, `temperature` y `updated_since`. Requiere `leads:read`.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'stage' => ['sometimes', 'string', 'max:32'],
            'contact_id' => ['sometimes', 'string', 'max:40'],
            'temperature' => ['sometimes', 'in:cold,warm,hot'],
            'updated_since' => ['sometimes', 'date'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $query = Lead::query();
        foreach (['stage', 'temperature'] as $field) {
            if (is_string($value = $request->query($field))) {
                $query->where($field, $value);
            }
        }
        if (is_string($contact = $request->query('contact_id'))) {
            $query->where('contact_id', PrefixedId::decode('ct', $contact) ?? '-');
        }
        if (($since = $this->since($request, 'updated_since')) !== null) {
            $query->where('updated_at', '>=', $since);
        }

        return $this->paginate($query, $request, fn (Lead $l) => Presenters::lead($l));
    }

    /**
     * Ver un lead
     *
     * Requiere `leads:read`.
     */
    public function show(string $lead): JsonResponse
    {
        $model = Lead::findByPublicId($lead);

        return $model === null ? ApiError::make(404, 'not_found', __('No existe ese lead.')) : new JsonResponse(['data' => Presenters::lead($model)]);
    }

    /**
     * Crear un lead
     *
     * Con `contact_id` o con `email`/`phone` (se crea o une el contacto). `external_id` es la
     * referencia en tu sistema: repetirla no crea otro lead. Requiere `leads:write`. Acepta
     * `Idempotency-Key`.
     */
    public function store(Request $request, LeadIntake $intake, ResolveApiContact $resolver): JsonResponse
    {
        $data = $request->validate([
            'contact_id' => ['nullable', 'string', 'max:40', 'required_without_all:email,phone'],
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'first_name' => ['nullable', 'string', 'max:80'],
            'last_name' => ['nullable', 'string', 'max:80'],
            'country' => ['nullable', 'string', 'size:2'],
            'service' => ['nullable', 'string', 'max:160'],
            'value' => ['nullable', 'numeric', 'min:0'],
            'message' => ['nullable', 'string', 'max:2000'],
            'external_id' => ['nullable', 'string', 'max:64'],
            'site_id' => ['nullable', 'string', 'max:40'],
            'source' => ['sometimes', 'array'],
            'source.channel' => ['nullable', 'string', 'max:40'],
            'source.campaign' => ['nullable', 'string', 'max:120'],
            'source.page' => ['nullable', 'string', 'max:500'],
            'source.gclid' => ['nullable', 'string', 'max:200'],
            'source.fbclid' => ['nullable', 'string', 'max:200'],
        ]);
        $workspace = $this->current->get();
        $site = ResolveApiContact::site($data['site_id'] ?? null);
        if (isset($data['contact_id'])) {
            $contact = Contact::findByPublicId((string) $data['contact_id']);
            if ($contact === null) {
                return ApiError::make(422, 'validation_failed', ApiError::defaultMessage(422), ['contact_id' => [__('No existe ese contacto.')]]);
            }
        } else {
            if ($site === null) {
                return ApiError::make(422, 'no_site', __('Conecta un sitio antes de crear contactos por la API, o envía un site_id válido.'));
            }
            try {
                [$contact] = $resolver->handle($workspace, $site, $data);
            } catch (InvalidArgumentException $e) {
                return ApiError::make(422, 'validation_failed', ApiError::defaultMessage(422), ['email' => [$e->getMessage()]]);
            }
        }
        $before = Lead::query()->where('contact_id', $contact->id)->pluck('id')->all();
        $leadId = $intake->capture(new LeadIntakeInput(
            workspaceId: $workspace->id,
            contactId: $contact->id,
            origin: 'api',
            originRef: $data['external_id'] ?? null,
            service: $data['service'] ?? null,
            value: isset($data['value']) ? number_format((float) $data['value'], 2, '.', '') : null,
            source: array_filter((array) ($data['source'] ?? []), fn ($v) => $v !== null && $v !== ''),
            siteId: $site?->id,
            message: $data['message'] ?? null,
        ));
        if ($leadId === null) {
            return ApiError::make(409, 'leads_disabled', __('El módulo de leads no está activo en este espacio de trabajo.'));
        }
        $lead = Lead::query()->find(PrefixedId::decode('ld', $leadId) ?? $leadId);
        if ($lead === null) {
            return ApiError::make(500, 'server_error', ApiError::defaultMessage(500));
        }
        if (isset($data['external_id']) && $lead->external_crm_id === null) {
            $lead->forceFill(['external_crm_id' => (string) $data['external_id']])->save();
        }

        $created = ! in_array($lead->id, $before, true);

        return new JsonResponse(['data' => Presenters::lead($lead), 'created' => $created], $created ? 201 : 200);
    }

    /**
     * Actualizar un lead
     *
     * Cambia la etapa (`stage`, con `lost_reason` si se pierde), el valor, el próximo paso o la
     * referencia en tu CRM (`external_crm_id`). Requiere `leads:write`.
     */
    public function update(Request $request, string $lead, ChangeLeadStage $change): JsonResponse
    {
        $data = $request->validate([
            'stage' => ['sometimes', 'string', 'max:32'],
            'lost_reason' => ['nullable', 'string', 'max:120'],
            'value' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'next_step' => ['sometimes', 'nullable', 'string', 'max:255'],
            'next_step_at' => ['sometimes', 'nullable', 'date'],
            'external_crm_id' => ['sometimes', 'nullable', 'string', 'max:64'],
        ]);
        $model = Lead::findByPublicId($lead);
        if ($model === null) {
            return ApiError::make(404, 'not_found', __('No existe ese lead.'));
        }
        $fields = [];
        if (array_key_exists('value', $data)) {
            $fields['value_ws'] = $data['value'] !== null ? number_format((float) $data['value'], 2, '.', '') : null;
        }
        foreach (['next_step', 'next_step_at', 'external_crm_id'] as $field) {
            if (array_key_exists($field, $data)) {
                $fields[$field] = $data[$field];
            }
        }
        if ($fields !== []) {
            $model->forceFill($fields + ['last_activity_at' => now()])->save();
        }
        if (isset($data['stage'])) {
            // La misma acción del panel (M18): historia, eventos con `by = api` y, al ganar,
            // la conversión offline a Meta y Google.
            try {
                $model = $change->handle($this->current->get(), $model, (string) $data['stage'], 'api', 'api', null, $data['lost_reason'] ?? null);
            } catch (ValidationException $e) {
                return ApiError::make(422, 'validation_failed', ApiError::defaultMessage(422), ['stage' => array_values(array_map('strval', (array) ($e->errors()['stage'] ?? [$e->getMessage()])))]);
            }
        }

        return new JsonResponse(['data' => Presenters::lead($model->refresh())]);
    }
}
