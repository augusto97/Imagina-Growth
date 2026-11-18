<?php

declare(strict_types=1);

namespace App\Domain\Privacy\Http;

use App\Domain\Contacts\Models\Contact;
use App\Domain\Privacy\Actions\CreateDataRequest;
use App\Domain\Privacy\Actions\DeleteContactData;
use App\Domain\Privacy\Actions\ExportContactData;
use App\Domain\Privacy\Models\DataRequest;
use App\Domain\Workspaces\Actions\RecordAuditLog;
use App\Domain\Workspaces\Models\User;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/** Solicitudes de los titulares desde el panel (cumplimiento.md §9). */
final class DataRequestController
{
    public function __construct(private readonly CurrentWorkspace $current) {}

    /** GET /api/w/{workspace}/data-requests */
    public function index(): JsonResponse
    {
        Gate::authorize('ws.exportContacts');

        return response()->json(['data' => DataRequest::query()->orderByDesc('created_at')->limit(200)->get()->map(fn (DataRequest $r) => $this->present($r))]);
    }

    /** POST /api/w/{workspace}/contacts/{contact}/data-requests {kind: access|delete} */
    public function store(Request $request, string $contact, CreateDataRequest $create): JsonResponse
    {
        $data = $request->validate(['kind' => ['required', 'in:access,delete']]);
        Gate::authorize($data['kind'] === 'delete' ? 'ws.deleteContacts' : 'ws.exportContacts');
        $model = Contact::findByPublicId($contact) ?? abort(404);
        /** @var User $user */
        $user = $request->user();
        $dataRequest = $create->handle($this->current->get(), $model->id, $data['kind'], null, 'panel', $user->id);

        return response()->json($this->present($dataRequest), 201);
    }

    /** POST /api/w/{workspace}/data-requests/{request}/execute — exporta o borra. */
    public function execute(Request $request, string $dataRequest, ExportContactData $export, DeleteContactData $delete, RecordAuditLog $audit): JsonResponse
    {
        $model = DataRequest::findByPublicId($dataRequest) ?? abort(404);
        Gate::authorize($model->kind === 'delete' ? 'ws.deleteContacts' : 'ws.exportContacts');
        if ($model->status === 'completed') {
            throw ValidationException::withMessages(['request' => __('La solicitud ya se completó.')]);
        }
        $contact = $model->contact_id !== null ? Contact::query()->find($model->contact_id) : null;
        if ($contact === null) {
            $model->forceFill(['status' => 'completed', 'completed_at' => now(), 'evidence' => ($model->evidence ?? []) + ['result' => 'sin datos de esa persona']])->save();

            return response()->json($this->present($model));
        }
        /** @var User $user */
        $user = $request->user();
        $workspace = $this->current->get();

        if ($model->kind === 'delete') {
            $request->validate(['confirm' => ['required', 'in:BORRAR']]);
            $counts = $delete->handle($workspace, $contact, $user->id);
            $model->forceFill(['status' => 'completed', 'completed_at' => now(), 'contact_id' => null, 'evidence' => ($model->evidence ?? []) + ['deleted' => $counts]])->save();
        } else {
            $payload = $export->handle($workspace, $contact);
            $path = "exports/{$workspace->id}/{$model->id}.json.enc";
            Storage::disk('local')->put($path, Crypt::encryptString((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)));
            $model->forceFill(['status' => 'completed', 'completed_at' => now(), 'evidence' => ($model->evidence ?? []) + ['export' => $path, 'events' => count($payload['events'])]])->save();
            $audit->handle('contact.exported', $contact, ['request' => $model->publicId()], $workspace->id, null, $user->id);
        }

        return response()->json($this->present($model));
    }

    /** GET /api/w/{workspace}/data-requests/{request}/download */
    public function download(string $dataRequest, RecordAuditLog $audit): Response
    {
        Gate::authorize('ws.exportContacts');
        $model = DataRequest::findByPublicId($dataRequest) ?? abort(404);
        $path = $model->evidence['export'] ?? null;
        if ($model->kind !== 'access' || ! is_string($path) || ! Storage::disk('local')->exists($path)) {
            abort(404);
        }
        $audit->handle('data_request.downloaded', $model, [], $model->workspace_id);

        return response(Crypt::decryptString((string) Storage::disk('local')->get($path)), 200, [
            'Content-Type' => 'application/json; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="datos-'.$model->publicId().'.json"',
            'Cache-Control' => 'no-store',
        ]);
    }

    /** @return array<string, mixed> */
    private function present(DataRequest $r): array
    {
        return [
            'id' => $r->publicId(),
            'kind' => $r->kind,
            'status' => $r->status,
            'contact_id' => $r->contact_id ? 'ct_'.$r->contact_id : null,
            'origin' => $r->evidence['origin'] ?? null,
            'due_at' => $r->due_at?->toIso8601String(),
            'completed_at' => $r->completed_at?->toIso8601String(),
            'created_at' => $r->getAttribute('created_at')?->toIso8601String(),
            'result' => array_intersect_key($r->evidence ?? [], array_flip(['deleted', 'events', 'result'])),
            'downloadable' => $r->kind === 'access' && $r->status === 'completed' && isset($r->evidence['export']),
        ];
    }
}
