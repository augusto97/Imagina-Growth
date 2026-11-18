<?php

declare(strict_types=1);

namespace App\Domain\Agency\Http;

use App\Domain\Agency\Http\Concerns\ResolvesAgency;
use App\Domain\Agency\Models\AgencyTemplate;
use App\Domain\Agency\Portable\PortableExporter;
use App\Domain\Agency\Portable\PortableImporter;
use App\Domain\Agency\Support\AgencyAccess;
use App\Domain\Workspaces\Actions\RecordAuditLog;
use App\Domain\Workspaces\Models\User;
use App\Domain\Workspaces\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Plantillas maestras de la agencia (M22): guardar un flujo, segmento, widget,
 * plantilla o campaña de un cliente en la biblioteca de la agencia, aplicarla a
 * otros clientes o copiarla directo entre clientes. Solo entre espacios de
 * trabajo de la misma cuenta de agencia; nunca viajan contactos ni datos personales.
 */
final class AgencyTemplateController
{
    use ResolvesAgency;

    /** GET agency/templates?kind= */
    public function index(Request $request): JsonResponse
    {
        $user = $this->agencyUser($request);
        $kind = $request->query('kind');
        $templates = AgencyTemplate::query()->whereIn('account_id', $this->agencyAccountIds($user))
            ->when(is_string($kind) && in_array($kind, PortableExporter::KINDS, true), fn ($q) => $q->where('kind', $kind))
            ->orderByDesc('updated_at')->limit(500)->get();
        $names = Workspace::query()->whereIn('id', $templates->pluck('source_workspace_id')->filter()->unique())->pluck('name', 'id');

        return response()->json(['templates' => $templates->map(fn (AgencyTemplate $t) => self::present($t, $names->get((string) $t->source_workspace_id)))->values()]);
    }

    /** POST agency/templates {source_workspace, kind, id, name?, description?} — guarda en la biblioteca. */
    public function store(Request $request, PortableExporter $exporter, RecordAuditLog $audit): JsonResponse
    {
        $user = $this->agencyUser($request);
        $data = $request->validate([
            'source_workspace' => ['required', 'string', 'max:40'],
            'kind' => ['required', Rule::in(PortableExporter::KINDS)],
            'id' => ['required', 'string', 'max:40'],
            'name' => ['nullable', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);
        $source = $this->clientWorkspace($user, $data['source_workspace']);
        $bundle = $exporter->export($source, $data['kind'], $data['id']);
        $name = trim((string) ($data['name'] ?? '')) !== '' ? trim((string) $data['name']) : $bundle['name'];
        $template = AgencyTemplate::query()->create([
            'account_id' => $source->account_id,
            'kind' => $data['kind'],
            'name' => mb_substr($name, 0, 160),
            'description' => $data['description'] ?? $bundle['description'],
            'payload' => $bundle,
            'source_workspace_id' => $source->id,
            'created_by' => $user->id,
        ]);
        $audit->handle('agency.template_saved', $template, ['kind' => $template->kind, 'name' => $template->name], $source->id, (string) $source->account_id, $user->id);

        return response()->json(['template' => self::present($template, $source->name)], 201);
    }

    /** GET agency/templates/{template} */
    public function show(Request $request, string $template): JsonResponse
    {
        $user = $this->agencyUser($request);
        $model = $this->find($user, $template);
        $source = $model->source_workspace_id !== null ? Workspace::query()->find($model->source_workspace_id) : null;

        return response()->json(['template' => self::present($model, $source?->name, true)]);
    }

    /** PATCH agency/templates/{template} {name?, description?} */
    public function update(Request $request, string $template): JsonResponse
    {
        $user = $this->agencyUser($request);
        $model = $this->find($user, $template);
        $data = $request->validate(['name' => ['sometimes', 'string', 'min:1', 'max:160'], 'description' => ['sometimes', 'nullable', 'string', 'max:1000']]);
        $model->fill($data)->save();

        return response()->json(['template' => self::present($model, null)]);
    }

    /** DELETE agency/templates/{template} — el dueño de la agencia o quien la creó. */
    public function destroy(Request $request, string $template, RecordAuditLog $audit): JsonResponse
    {
        $user = $this->agencyUser($request);
        $model = $this->find($user, $template);
        if ($model->created_by !== $user->id && ! AgencyAccess::isOwner($user, $model->account_id)) {
            abort(403, __('Solo el dueño de la agencia o quien creó la plantilla puede borrarla.'));
        }
        $audit->handle('agency.template_deleted', $model, ['name' => $model->name], null, $model->account_id, $user->id);
        $model->delete();

        return response()->json(['ok' => true]);
    }

    /** POST agency/templates/{template}/apply {workspaces: [ws_…], name?} */
    public function apply(Request $request, string $template, PortableImporter $importer): JsonResponse
    {
        $user = $this->agencyUser($request);
        $model = $this->find($user, $template);
        $data = $request->validate(['workspaces' => ['required', 'array', 'min:1', 'max:50'], 'workspaces.*' => ['string', 'max:40'], 'name' => ['nullable', 'string', 'max:160']]);
        $targets = array_map(fn (string $id) => $this->clientWorkspace($user, $id), array_values(array_unique($data['workspaces'])));
        foreach ($targets as $target) {
            if ($target->account_id !== $model->account_id) {
                throw ValidationException::withMessages(['workspaces' => __('Solo se aplica a clientes de la misma agencia.')]);
            }
        }

        return response()->json(['results' => $this->importInto($importer, $targets, (array) $model->payload, $data['name'] ?? null, $user)]);
    }

    /** POST agency/copy {source_workspace, kind, id, workspaces: [ws_…], name?} — copia directa entre clientes. */
    public function copy(Request $request, PortableExporter $exporter, PortableImporter $importer): JsonResponse
    {
        $user = $this->agencyUser($request);
        $data = $request->validate([
            'source_workspace' => ['required', 'string', 'max:40'],
            'kind' => ['required', Rule::in(PortableExporter::KINDS)],
            'id' => ['required', 'string', 'max:40'],
            'workspaces' => ['required', 'array', 'min:1', 'max:50'],
            'workspaces.*' => ['string', 'max:40'],
            'name' => ['nullable', 'string', 'max:160'],
        ]);
        $source = $this->clientWorkspace($user, $data['source_workspace']);
        $targets = array_map(fn (string $id) => $this->clientWorkspace($user, $id), array_values(array_unique($data['workspaces'])));
        foreach ($targets as $target) {
            if ($target->account_id !== $source->account_id) {
                throw ValidationException::withMessages(['workspaces' => __('Solo se copia entre clientes de la misma agencia.')]);
            }
        }
        $bundle = $exporter->export($source, $data['kind'], $data['id']);

        return response()->json(['results' => $this->importInto($importer, $targets, $bundle, $data['name'] ?? null, $user)]);
    }

    /**
     * @param  list<Workspace>  $targets
     * @param  array<string, mixed>  $bundle
     * @return list<array<string, mixed>>
     */
    private function importInto(PortableImporter $importer, array $targets, array $bundle, ?string $name, User $user): array
    {
        $results = [];
        foreach ($targets as $target) {
            try {
                $results[] = ['workspace_id' => $target->publicId(), 'workspace' => $target->name, 'ok' => true] + $importer->import($target, $bundle, $user, $name);
            } catch (ValidationException $e) {
                $results[] = ['workspace_id' => $target->publicId(), 'workspace' => $target->name, 'ok' => false, 'error' => collect($e->errors())->flatten()->first()];
            }
        }

        return $results;
    }

    private function find(User $user, string $publicId): AgencyTemplate
    {
        $model = AgencyTemplate::findByPublicId($publicId);
        if ($model === null || ! in_array($model->account_id, $this->agencyAccountIds($user), true)) {
            abort(404);
        }

        return $model;
    }

    /** @return array<string, mixed> */
    private static function present(AgencyTemplate $t, ?string $sourceName, bool $detail = false): array
    {
        $payload = (array) $t->payload;
        $data = [
            'id' => $t->publicId(),
            'account_id' => 'acc_'.$t->account_id,
            'kind' => $t->kind,
            'name' => $t->name,
            'description' => $t->description,
            'source_workspace' => $sourceName,
            'includes' => ['templates' => count((array) ($payload['templates'] ?? [])), 'segments' => count((array) ($payload['segments'] ?? []))],
            'warnings' => array_values((array) ($payload['warnings'] ?? [])),
            'created_at' => $t->created_at?->toIso8601String(),
            'updated_at' => $t->updated_at?->toIso8601String(),
        ];
        if ($detail) {
            $data['templates'] = array_values(array_map(fn ($x) => ['name' => $x['name'] ?? '', 'channel' => $x['channel'] ?? ''], (array) ($payload['templates'] ?? [])));
            $data['segments'] = array_values(array_map(fn ($x) => ['name' => $x['name'] ?? '', 'system' => is_string($x['system_key'] ?? null)], (array) ($payload['segments'] ?? [])));
        }

        return $data;
    }
}
