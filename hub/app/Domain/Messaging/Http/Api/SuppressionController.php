<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Http\Api;

use App\Domain\Contacts\Models\Contact;
use App\Domain\Messaging\Models\ExcludedRegistryImport;
use App\Domain\Messaging\Models\Suppression;
use App\Domain\Workspaces\Actions\RecordAuditLog;
use App\Support\Phone\Phone;
use App\Support\Pii\BlindIndex;
use App\Support\Pii\Pii;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Supresión (M07, cumplimiento.md §4.2): rebotes, quejas, bajas, bloqueos y los
 * registros de exclusión RNE (Colombia) y REPEP (México). Solo hay índices
 * ciegos: el valor se muestra enmascarado si existe un contacto con él.
 */
final class SuppressionController
{
    public function __construct(private readonly CurrentWorkspace $current, private readonly RecordAuditLog $audit) {}

    /** GET /api/w/{ws}/suppressions?channel=&reason=&q= */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('ws.manageMarketing');
        $query = Suppression::query()->orderByDesc('id');
        if ($request->filled('channel')) {
            $query->where('channel', (string) $request->query('channel'));
        }
        if ($request->filled('reason')) {
            $query->where('reason', (string) $request->query('reason'));
        }
        if ($request->filled('q')) {
            $query->whereIn('value_bidx', $this->bidx((string) $request->query('q')));
        }
        $page = $query->paginate(50);
        $bidx = collect($page->items())->pluck('value_bidx')->all();
        $contacts = Contact::query()->whereNull('merged_into_id')->where(fn ($q) => $q->whereIn('email_bidx', $bidx)->orWhereIn('phone_bidx', $bidx))->get();

        return response()->json([
            'data' => collect($page->items())->map(function (Suppression $s) use ($contacts) {
                $contact = $contacts->first(fn (Contact $c) => $c->email_bidx === $s->value_bidx || $c->phone_bidx === $s->value_bidx);

                return [
                    'id' => $s->id,
                    'channel' => $s->channel,
                    'reason' => $s->reason,
                    'created_at' => $s->created_at?->toIso8601String(),
                    'contact' => $contact === null ? null : [
                        'id' => $contact->publicId(),
                        'name' => $contact->displayName(),
                        'value' => $s->channel === 'email' ? Pii::maskEmail((string) $contact->email) : Pii::maskPhone((string) $contact->phone),
                    ],
                ];
            })->values(),
            'meta' => ['total' => $page->total(), 'page' => $page->currentPage(), 'last_page' => $page->lastPage()],
            'imports' => ExcludedRegistryImport::query()->orderByDesc('imported_at')->limit(10)->get()
                ->map(fn (ExcludedRegistryImport $i) => ['country' => $i->country, 'rows' => $i->rows, 'added' => $i->added, 'imported_at' => $i->imported_at->toIso8601String()]),
        ]);
    }

    /** POST /api/w/{ws}/suppressions {channel, value} — supresión manual. */
    public function store(Request $request): JsonResponse
    {
        Gate::authorize('ws.manageMarketing');
        $data = $request->validate(['channel' => ['required', Rule::in(['email', 'whatsapp', 'sms'])], 'value' => ['required', 'string', 'max:190']]);
        $bidx = $this->bidx($data['value'], $data['channel']);
        if ($bidx === []) {
            throw ValidationException::withMessages(['value' => $data['channel'] === 'email' ? __('Escribe un correo válido.') : __('Escribe un teléfono válido.')]);
        }
        $added = Suppression::add($this->current->id(), $data['channel'], $bidx[0], 'manual');
        $this->audit->handle('suppression.added', null, ['channel' => $data['channel'], 'value' => Pii::mask($data['value'])], $this->current->id());

        return response()->json(['added' => $added], $added ? 201 : 200);
    }

    /** DELETE /api/w/{ws}/suppressions/{id} — las quejas no se quitan (cumplimiento.md §8). */
    public function destroy(int $suppression): JsonResponse
    {
        Gate::authorize('ws.manageWorkspace');
        $model = Suppression::query()->findOrFail($suppression);
        abort_if($model->reason === 'complaint', 422, __('Una queja de spam no se puede quitar: la persona marcó el correo como spam.'));
        $this->audit->handle('suppression.removed', $model, ['channel' => $model->channel, 'reason' => $model->reason], $this->current->id());
        $model->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * POST /api/w/{ws}/suppressions/registry {country: CO|MX, file} — importa el
     * RNE de la CRC o el REPEP de PROFECO (números a excluir de la publicidad).
     */
    public function registry(Request $request): JsonResponse
    {
        Gate::authorize('ws.manageWorkspace');
        $data = $request->validate(['country' => ['required', Rule::in(['CO', 'MX'])], 'file' => ['required', 'file', 'max:51200']]);
        $path = (string) $request->file('file')?->getRealPath();
        $reason = $data['country'] === 'CO' ? 'rne' : 'repep';
        $ws = $this->current->id();
        $rows = 0;
        $added = 0;
        $batch = [];
        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw ValidationException::withMessages(['file' => __('No se pudo leer el archivo.')]);
        }
        while (($line = fgets($handle)) !== false) {
            foreach (preg_split('/[;,\t]/', $line) ?: [] as $cell) {
                $digits = preg_replace('/[^\d+]/', '', $cell) ?? '';
                if (strlen(ltrim($digits, '+')) < 7) {
                    continue;
                }
                $e164 = Phone::toE164($digits, $data['country']);
                if ($e164 === null) {
                    continue;
                }
                $rows++;
                $bidx = BlindIndex::phone($ws, $e164);
                foreach (['whatsapp', 'sms'] as $channel) {
                    $batch[] = ['workspace_id' => $ws, 'channel' => $channel, 'value_bidx' => $bidx, 'reason' => $reason, 'created_at' => now()];
                }
                if (count($batch) >= 1000) {
                    $added += Suppression::query()->insertOrIgnore($batch);
                    $batch = [];
                }
            }
        }
        fclose($handle);
        if ($batch !== []) {
            $added += Suppression::query()->insertOrIgnore($batch);
        }
        $import = ExcludedRegistryImport::query()->create([
            'workspace_id' => $ws, 'country' => $data['country'], 'file_hash' => (string) hash_file('sha256', $path),
            'rows' => $rows, 'added' => $added, 'imported_by' => (string) $request->user()?->getAuthIdentifier(), 'imported_at' => now(),
        ]);
        $this->audit->handle('suppression.registry_imported', $import, ['country' => $data['country'], 'rows' => $rows, 'added' => $added], $ws);

        return response()->json(['rows' => $rows, 'added' => $added]);
    }

    /** @return list<string> índices ciegos del valor (correo o teléfono) */
    private function bidx(string $value, ?string $channel = null): array
    {
        $ws = $this->current->get();
        $out = [];
        if ($channel !== 'whatsapp' && $channel !== 'sms' && filter_var(trim($value), FILTER_VALIDATE_EMAIL) !== false) {
            $out[] = BlindIndex::email($ws->id, trim($value));
        }
        if ($channel !== 'email') {
            $e164 = Phone::toE164($value, strtoupper((string) ($ws->country ?: 'CO')));
            if ($e164 !== null) {
                $out[] = BlindIndex::phone($ws->id, $e164);
            }
        }

        return $out;
    }
}
