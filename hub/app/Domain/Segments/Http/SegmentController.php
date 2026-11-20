<?php

declare(strict_types=1);

namespace App\Domain\Segments\Http;

use App\Domain\Contacts\Models\Contact;
use App\Domain\Segments\Actions\CreateStaticSegment;
use App\Domain\Segments\Actions\SaveSegment;
use App\Domain\Segments\Compiler\SegmentTimeout;
use App\Domain\Segments\Definition\DefinitionValidator;
use App\Domain\Segments\Definition\InvalidDefinition;
use App\Domain\Segments\Jobs\RecomputeSegmentJob;
use App\Domain\Segments\Library\SegmentLibrary;
use App\Domain\Segments\Models\Segment;
use App\Domain\Segments\Queries\ContactRow;
use App\Domain\Segments\Queries\SegmentPreview;
use App\Domain\Segments\Support\DependencyIndex;
use App\Domain\Workspaces\Actions\RecordAuditLog;
use App\Domain\Workspaces\Models\User;
use App\Support\Csv;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** API de segmentos de la SPA (modulos.md M04). */
final class SegmentController
{
    public function __construct(private readonly CurrentWorkspace $current) {}

    /** GET segments */
    public function index(): JsonResponse
    {
        Gate::authorize('ws.viewAllContacts');
        $segments = Segment::query()->orderByDesc('is_system')->orderBy('folder')->orderBy('name')->get();

        return response()->json(['data' => $segments->map(fn (Segment $s) => SegmentPresenter::make($s))->values()]);
    }

    /** GET segments/{segment} */
    public function show(string $segment): JsonResponse
    {
        Gate::authorize('ws.viewAllContacts');

        return response()->json(SegmentPresenter::make($this->find($segment), withDefinition: true));
    }

    /** POST segments */
    public function store(Request $request, SaveSegment $save): JsonResponse
    {
        Gate::authorize('ws.manageMarketing');
        $data = $this->validated($request, creating: true);
        /** @var User $user */
        $user = $request->user();

        return response()->json(SegmentPresenter::make($save->handle(null, $data, $user), withDefinition: true), 201);
    }

    /** PATCH segments/{segment} */
    public function update(Request $request, string $segment, SaveSegment $save): JsonResponse
    {
        Gate::authorize('ws.manageMarketing');
        $model = $this->find($segment);
        $data = $this->validated($request, creating: false);
        /** @var User $user */
        $user = $request->user();

        return response()->json(SegmentPresenter::make($save->handle($model, $data, $user), withDefinition: true));
    }

    /** DELETE segments/{segment} */
    public function destroy(string $segment, RecordAuditLog $audit): JsonResponse
    {
        Gate::authorize('ws.manageMarketing');
        $model = $this->find($segment);
        if ($model->is_system) {
            return response()->json(['message' => __('Los segmentos del sistema no se pueden borrar.')], 422);
        }
        DB::table('segment_memberships')->where('workspace_id', $this->current->id())->where('segment_id', $model->id)->delete();
        $model->delete();
        DependencyIndex::forget($this->current->id());
        $audit->handle('segment.deleted', $model, ['name' => $model->name], $this->current->id());

        return response()->json(['ok' => true]);
    }

    /** POST segments/preview — vista previa de una definición sin guardar. */
    public function preview(Request $request, DefinitionValidator $validator, SegmentPreview $preview): JsonResponse
    {
        Gate::authorize('ws.viewAllContacts');
        $definition = $request->input('definition');
        if (! is_array($definition)) {
            throw ValidationException::withMessages(['definition' => __('Falta la definición del segmento.')]);
        }
        try {
            $ast = $validator->validate($definition);
        } catch (InvalidDefinition $e) {
            throw $e->toValidationException();
        }
        try {
            return response()->json($preview->handle($this->current->get(), $ast, Gate::allows('ws.exportContacts')));
        } catch (SegmentTimeout $e) {
            return response()->json(['error' => 'segment_timeout', 'message' => $e->getMessage()], 422);
        }
    }

    /** GET segments/{segment}/members */
    public function members(Request $request, string $segment): JsonResponse
    {
        Gate::authorize('ws.viewAllContacts');
        $model = $this->find($segment);
        $page = Contact::query()
            ->whereNull('merged_into_id')
            ->whereIn('id', fn ($q) => $q->from('segment_memberships')->select('contact_id')->where('workspace_id', $this->current->id())->where('segment_id', $model->id))
            ->orderByDesc('last_seen_at')
            ->orderBy('id')
            ->paginate(min(100, max(10, (int) $request->query('per_page', 25))));
        $unmasked = Gate::allows('ws.exportContacts');

        return response()->json([
            'data' => collect($page->items())->map(fn (Contact $c) => ContactRow::make($c, $unmasked))->values(),
            'meta' => ['total' => $page->total(), 'page' => $page->currentPage(), 'per_page' => $page->perPage()],
        ]);
    }

    /** GET segments/{segment}/export — CSV de los miembros (auditoría segment.exported). */
    public function export(string $segment, RecordAuditLog $audit): StreamedResponse
    {
        Gate::authorize('ws.exportContacts');
        $model = $this->find($segment);
        $workspaceId = $this->current->id();
        $audit->handle('segment.exported', $model, ['name' => $model->name, 'members' => $model->member_count], $workspaceId);

        $filename = 'segmento-'.(Str::slug($model->name) ?: 'contactos').'-'.now()->format('Ymd').'.csv';

        return response()->streamDownload(function () use ($model, $workspaceId): void {
            $out = fopen('php://output', 'wb');
            if ($out === false) {
                return;
            }
            fwrite($out, "\xEF\xBB\xBF"); // BOM: Excel abre bien las tildes
            fputcsv($out, ['id', 'nombre', 'apellido', 'correo', 'telefono', 'ciudad', 'departamento', 'etapa', 'segmento_rfm', 'pedidos', 'gasto_total', 'ultimo_pedido'], ',', '"', '');
            Contact::query()
                ->whereNull('merged_into_id')
                ->whereIn('id', fn ($q) => $q->from('segment_memberships')->select('contact_id')->where('workspace_id', $workspaceId)->where('segment_id', $model->id))
                ->orderBy('id')
                ->chunk(1000, function ($contacts) use ($out): void {
                    foreach ($contacts as $c) {
                        /** @var Contact $c */
                        fputcsv($out, Csv::row([
                            $c->publicId(), $c->first_name ?? '', $c->last_name ?? '', $c->email ?? '', $c->phone ?? '',
                            $c->getAttribute('city') ?? '', $c->getAttribute('region_code') ?? '', $c->getAttribute('lifecycle_stage') ?? '',
                            $c->getAttribute('rfm_segment') ?? '', (int) $c->getAttribute('orders_count'), (string) $c->getAttribute('revenue_total_ws'),
                            $c->getAttribute('last_order_at')?->toDateString() ?? '',
                        ]), ',', '"', '');
                    }
                });
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** POST segments/{segment}/recompute */
    public function recompute(string $segment): JsonResponse
    {
        Gate::authorize('ws.manageMarketing');
        $model = $this->find($segment);
        RecomputeSegmentJob::dispatch($this->current->id(), $model->id);

        return response()->json(['queued' => true], 202);
    }

    /** POST segments/{segment}/snapshot — foto estática de un dinámico. */
    public function snapshot(Request $request, string $segment, CreateStaticSegment $create): JsonResponse
    {
        Gate::authorize('ws.manageMarketing');
        $model = $this->find($segment);
        $data = $request->validate(['name' => ['nullable', 'string', 'min:2', 'max:160']]);
        /** @var User $user */
        $user = $request->user();
        $static = $create->snapshot($model, $data['name'] ?? __('Foto de :name', ['name' => $model->name]), $user);

        return response()->json(SegmentPresenter::make($static), 201);
    }

    /** POST segments/static — estático desde correos o teléfonos (CSV leído en el navegador). */
    public function storeStatic(Request $request, CreateStaticSegment $create): JsonResponse
    {
        Gate::authorize('ws.manageMarketing');
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:160'],
            'description' => ['nullable', 'string', 'max:1000'],
            'folder' => ['nullable', 'string', 'max:80'],
            'values' => ['required', 'array', 'min:1', 'max:50000'],
            'values.*' => ['nullable', 'string', 'max:254'],
        ]);
        /** @var User $user */
        $user = $request->user();
        $result = $create->fromValues($data['name'], $data['description'] ?? null, $data['folder'] ?? null, array_values(array_map('strval', array_filter($data['values'], 'is_string'))), $user);

        return response()->json(['segment' => SegmentPresenter::make($result['segment']), 'matched' => $result['matched'], 'unmatched' => $result['unmatched']], 201);
    }

    /** GET segments/library — biblioteca: segmentos del sistema y plantillas. */
    public function library(): JsonResponse
    {
        Gate::authorize('ws.viewAllContacts');
        $existing = Segment::query()->whereNotNull('system_key')->pluck('id', 'system_key');

        return response()->json([
            'system' => array_map(fn (array $s) => [
                'key' => $s['key'], 'name' => $s['name'], 'description' => $s['description'], 'folder' => $s['folder'],
                'segment_id' => isset($existing[$s['key']]) ? 'sg_'.$existing[$s['key']] : null,
            ], SegmentLibrary::system()),
            'templates' => SegmentLibrary::templates(),
        ]);
    }

    /**
     * POST segments/library/{key} — crea un segmento desde una plantilla con sus
     * parámetros. Con la clave de un segmento del sistema que falte, instala
     * la biblioteca (idempotente) y devuelve ese segmento.
     */
    public function fromTemplate(Request $request, string $key, SaveSegment $save, SegmentLibrary $library): JsonResponse
    {
        Gate::authorize('ws.manageMarketing');
        if (in_array($key, array_column(SegmentLibrary::system(), 'key'), true)) {
            $library->install($this->current->get());
            $system = Segment::query()->where('system_key', $key)->first() ?? abort(404);
            RecomputeSegmentJob::dispatch($this->current->id(), $system->id);

            return response()->json(SegmentPresenter::make($system, withDefinition: true), 201);
        }
        $data = $request->validate([
            'name' => ['nullable', 'string', 'min:2', 'max:160'],
            'params' => ['required', 'array'],
            'params.*' => ['array'],
            'params.*.*' => ['string', 'max:64'],
        ]);
        $template = collect(SegmentLibrary::templates())->firstWhere('key', $key);
        $definition = SegmentLibrary::buildTemplate($key, $data['params']);
        if ($template === null || $definition === null) {
            return response()->json(['message' => __('La plantilla no existe.')], 404);
        }
        /** @var User $user */
        $user = $request->user();
        $segment = $save->handle(null, ['name' => $data['name'] ?? $template['name'], 'description' => $template['description'], 'definition' => $definition], $user);

        return response()->json(SegmentPresenter::make($segment, withDefinition: true), 201);
    }

    /**
     * @return array{name?: string, description?: string|null, folder?: string|null, definition?: array<mixed>}
     */
    private function validated(Request $request, bool $creating): array
    {
        /** @var array{name?: string, description?: string|null, folder?: string|null, definition?: array<mixed>} $data */
        $data = $request->validate([
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'min:2', 'max:160'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'folder' => ['sometimes', 'nullable', 'string', 'max:80'],
            'definition' => [$creating ? 'required' : 'sometimes', 'array'],
        ]);

        return $data;
    }

    private function find(string $publicId): Segment
    {
        return Segment::findByPublicId($publicId) ?? abort(404);
    }
}
