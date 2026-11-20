<?php

declare(strict_types=1);

namespace App\Domain\Contacts\Http;

use App\Domain\Billing\Contracts\Entitlements;
use App\Domain\Consent\Support\ConsentDeclaration;
use App\Domain\Contacts\Jobs\DeclareHistoryConsent;
use App\Domain\Contacts\Jobs\ImportContacts;
use App\Domain\Contacts\Models\ContactImport;
use App\Domain\Contacts\Support\CsvReader;
use App\Domain\Sites\Models\Site;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/** Importación CSV de contactos (modulos.md M03). */
final class ImportController
{
    /** `consent_*`: la columna dice, fila por fila, si esa persona aceptó el canal (sí/no). */
    private const FIELDS = ['email', 'phone', 'first_name', 'last_name', 'city', 'region_code', 'country', 'birthday', 'renewal_at', 'tags', 'consent_email', 'consent_whatsapp', 'consent_sms', 'ignore'];

    public function __construct(private readonly CurrentWorkspace $current) {}

    /** POST contacts/imports/preview (multipart file) */
    public function preview(Request $request): JsonResponse
    {
        Gate::authorize('ws.editContacts');
        $file = $this->file($request);
        $csv = CsvReader::read((string) file_get_contents($file->getRealPath()));
        $rows = [];
        foreach ($csv['rows'] as $row) {
            $rows[] = $row;
            if (count($rows) === 5) {
                break;
            }
        }

        return response()->json([
            'headers' => $csv['headers'],
            'rows' => $rows,
            'suggested' => array_combine($csv['headers'], array_map([CsvReader::class, 'suggest'], $csv['headers'])) ?: [],
            'fields' => self::FIELDS,
        ]);
    }

    /** POST contacts/imports (multipart file, mapping, consent?) */
    public function store(Request $request): JsonResponse
    {
        Gate::authorize('ws.editContacts');
        // Cuenta suspendida por facturación (M21): no se importan contactos nuevos; los datos siguen.
        if (! app(Entitlements::class)->allows($this->current->get(), 'imports')) {
            // 402 como `plan_required` (la SPA muestra `message`); 423 lo reserva Laravel para confirmar la contraseña.
            return response()->json(['error' => 'billing_suspended', 'message' => __('La cuenta está suspendida por facturación: las importaciones se reanudan al regularizarla.')], 402);
        }
        $file = $this->file($request);
        $mapping = json_decode((string) $request->input('mapping', '{}'), true);
        $consent = $request->filled('consent') ? json_decode((string) $request->input('consent'), true) : null;
        if (! is_array($mapping) || $mapping === []) {
            throw ValidationException::withMessages(['mapping' => __('Indica qué columna corresponde a cada dato.')]);
        }
        foreach ($mapping as $field) {
            if (! is_string($field) || (! in_array($field, self::FIELDS, true) && ! preg_match('/^custom:[a-z0-9_]{1,40}$/', $field))) {
                throw ValidationException::withMessages(['mapping' => __('Campo no válido: :f', ['f' => is_string($field) ? $field : '?'])]);
            }
        }
        if (! in_array('email', $mapping, true) && ! in_array('phone', $mapping, true)) {
            throw ValidationException::withMessages(['mapping' => __('Asigna al menos la columna de correo o de teléfono.')]);
        }
        if ($consent !== null) {
            // cumplimiento.md §3.1: el origen, cómo se obtuvo y la fecha del consentimiento son obligatorios.
            $consent = ConsentDeclaration::validate($consent);
        }
        $perRow = array_values(array_filter($mapping, fn (string $f) => str_starts_with($f, 'consent_')));
        if ($perRow !== [] && $consent === null) {
            throw ValidationException::withMessages(['consent' => __('Las columnas de consentimiento necesitan la declaración de dónde salió y cómo se obtuvo.')]);
        }

        $workspace = $this->current->get();
        $import = ContactImport::query()->create([
            'workspace_id' => $workspace->id, 'user_id' => auth()->id(), 'filename' => mb_substr($file->getClientOriginalName(), 0, 255),
            'mapping' => $mapping, 'consent' => $consent, 'status' => 'queued',
        ]);
        $path = "imports/{$workspace->id}/{$import->id}.csv.enc";
        Storage::disk('local')->put($path, Crypt::encryptString((string) file_get_contents($file->getRealPath())));
        $import->forceFill(['path' => $path])->save();
        ImportContacts::dispatch($workspace->id, $import->id);

        return response()->json($this->present($import->refresh()), 202);
    }

    /**
     * POST contacts/imports/history {site, consent} — consentimiento del historial que importó el plugin de un sitio
     * (auditoría F7). Sin esta declaración la importación histórica no crea consentimiento.
     */
    public function history(Request $request): JsonResponse
    {
        Gate::authorize('ws.editContacts');
        $data = $request->validate(['site' => ['required', 'string', 'max:40'], 'consent' => ['required', 'array']]);
        $site = Site::findByPublicId($data['site']);
        if ($site === null) {
            throw ValidationException::withMessages(['site' => __('Elige un sitio conectado.')]);
        }
        $consent = ConsentDeclaration::validate($data['consent']);
        $workspace = $this->current->get();
        $import = ContactImport::query()->create([
            'workspace_id' => $workspace->id, 'user_id' => auth()->id(), 'kind' => 'history', 'site_id' => $site->id,
            'filename' => mb_substr((string) $site->host, 0, 255), 'mapping' => [], 'consent' => $consent, 'status' => 'queued',
        ]);
        DeclareHistoryConsent::dispatch($workspace->id, $import->id);

        return response()->json($this->present($import->refresh()), 202);
    }

    /** GET contacts/imports */
    public function index(): JsonResponse
    {
        Gate::authorize('ws.editContacts');

        return response()->json(['data' => ContactImport::query()->orderByDesc('created_at')->limit(50)->get()->map(fn (ContactImport $i) => $this->present($i))]);
    }

    /** GET contacts/imports/{import} */
    public function show(string $import): JsonResponse
    {
        Gate::authorize('ws.editContacts');

        return response()->json($this->present(ContactImport::findByPublicId($import) ?? abort(404)));
    }

    private function file(Request $request): UploadedFile
    {
        $request->validate(['file' => ['required', 'file', 'max:20480', 'mimes:csv,txt']]);
        $file = $request->file('file');

        return $file instanceof UploadedFile ? $file : throw ValidationException::withMessages(['file' => __('Sube un archivo CSV.')]);
    }

    /** @return array<string, mixed> */
    private function present(ContactImport $i): array
    {
        return [
            'id' => $i->publicId(), 'kind' => $i->kind, 'status' => $i->status, 'filename' => $i->filename,
            'totals' => $i->totals ?? ['rows' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0, 'invalid' => 0, 'consents' => 0],
            'errors' => $i->errors ?? [], 'consent' => $i->consent,
            'created_at' => $i->getAttribute('created_at')?->toIso8601String(), 'finished_at' => $i->finished_at?->toIso8601String(),
        ];
    }
}
