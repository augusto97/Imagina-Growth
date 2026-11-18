<?php

declare(strict_types=1);

namespace App\Domain\Contacts\Jobs;

use App\Domain\Consent\Actions\RecordConsent;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Contacts\Models\ContactIdentity;
use App\Domain\Contacts\Models\ContactImport;
use App\Domain\Contacts\Support\CsvReader;
use App\Domain\Identity\IdentityResolver;
use App\Domain\Workspaces\Actions\RecordAuditLog;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Phone\Phone;
use App\Support\Pii\BlindIndex;
use App\Support\Tenancy\CurrentWorkspace;
use App\Support\Tenancy\SetWorkspaceContext;
use App\Support\Tenancy\WorkspaceAware;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Importa contactos de un CSV (modulos.md M03, cumplimiento.md §3.1). Crea o
 * completa contactos por correo o teléfono (nunca pisa datos existentes ni
 * fusiona perfiles) y registra el consentimiento solo si se declaró su
 * origen; sin esa declaración, los contactos solo pueden recibir mensajes
 * transaccionales. El archivo se borra al terminar.
 */
final class ImportContacts implements ShouldQueue, WorkspaceAware
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 1800;

    public int $tries = 1;

    public function __construct(public string $workspaceIdValue, public string $importId)
    {
        $this->onQueue('analytics'); // job largo: lo atiende la conexión redis-long (hub/CLAUDE.md §15)
    }

    public function workspaceId(): string
    {
        return $this->workspaceIdValue;
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [new SetWorkspaceContext];
    }

    public function handle(RecordConsent $consents, RecordAuditLog $audit, CurrentWorkspace $current): void
    {
        $import = ContactImport::query()->findOrFail($this->importId);
        $workspace = $current->get();
        $import->forceFill(['status' => 'running'])->save();
        $totals = ['rows' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0, 'invalid' => 0, 'consents' => 0];
        $errors = [];

        try {
            $csv = CsvReader::read(Crypt::decryptString((string) Storage::disk('local')->get((string) $import->path)));
            $columns = [];
            foreach ($csv['headers'] as $i => $header) {
                $field = $import->mapping[$header] ?? 'ignore';
                if ($field !== 'ignore') {
                    $columns[$i] = $field;
                }
            }
            $consent = $import->consent;
            $consentAt = $consent !== null ? CarbonImmutable::parse($consent['date'])->startOfDay() : null;

            $batch = [];
            foreach ($csv['rows'] as $line => $row) {
                $batch[$line] = $row;
                if (count($batch) === 500) {
                    $this->processBatch($workspace, $import, $batch, $columns, $consents, $consent, $consentAt, $totals, $errors);
                    $batch = [];
                }
            }
            $this->processBatch($workspace, $import, $batch, $columns, $consents, $consent, $consentAt, $totals, $errors);
            $status = 'completed';
        } catch (Throwable $e) {
            $errors[] = ['row' => 0, 'message' => __('La importación se detuvo: :msg', ['msg' => mb_substr($e->getMessage(), 0, 200)])];
            $status = 'failed';
        } finally {
            if ($import->path !== null) {
                Storage::disk('local')->delete($import->path);
            }
        }

        $import->forceFill(['status' => $status, 'totals' => $totals, 'errors' => array_slice($errors, 0, 50), 'path' => null, 'finished_at' => now()])->save();
        $audit->handle('contacts.imported', $import, ['totals' => $totals, 'consent_declared' => $import->consent !== null], $workspace->id, null, $import->getAttribute('user_id'));
    }

    /**
     * @param  array<int, list<string>>  $batch
     * @param  array<int, string>  $columns
     * @param  array{channels: list<string>, source: string, date: string}|null  $consent
     * @param  array<string, int>  $totals
     * @param  list<array{row: int, message: string}>  $errors
     */
    private function processBatch(Workspace $workspace, ContactImport $import, array $batch, array $columns, RecordConsent $consents, ?array $consent, ?CarbonImmutable $consentAt, array &$totals, array &$errors): void
    {
        if ($batch === []) {
            return;
        }
        DB::transaction(function () use ($workspace, $import, $batch, $columns, $consents, $consent, $consentAt, &$totals, &$errors): void {
            foreach ($batch as $line => $row) {
                $totals['rows']++;
                $data = [];
                foreach ($columns as $i => $field) {
                    $data[$field] = $row[$i] ?? '';
                }
                $email = filter_var($data['email'] ?? '', FILTER_VALIDATE_EMAIL) ? BlindIndex::normalizeEmail((string) $data['email']) : null;
                $phone = Phone::toE164($data['phone'] ?? null, strtoupper(substr((string) ($data['country'] ?? ''), 0, 2)) ?: $workspace->country);
                if ($email === null && $phone === null) {
                    $totals['invalid']++;
                    $errors[] = ['row' => (int) $line, 'message' => __('Sin correo ni teléfono válido.')];

                    continue;
                }
                // El dato inválido no se guarda, pero se avisa: la fila entra solo con lo que sí sirve.
                if ($email === null && trim((string) ($data['email'] ?? '')) !== '') {
                    $errors[] = ['row' => (int) $line, 'message' => __('Correo inválido: no se guardó y el contacto se importó solo con el teléfono.')];
                }
                if ($phone === null && trim((string) ($data['phone'] ?? '')) !== '') {
                    $errors[] = ['row' => (int) $line, 'message' => __('Teléfono inválido: no se guardó y el contacto se importó solo con el correo.')];
                }

                $emailBidx = $email !== null ? BlindIndex::email($workspace->id, $email) : null;
                $phoneBidx = $phone !== null ? BlindIndex::phone($workspace->id, $phone) : null;
                $owners = ContactIdentity::query()->whereIn('value', array_filter([$emailBidx, $phoneBidx]))->pluck('contact_id', 'value');
                $contactId = ($emailBidx !== null ? $owners->get($emailBidx) : null) ?? ($phoneBidx !== null ? $owners->get($phoneBidx) : null);
                $contact = $contactId !== null ? Contact::query()->find($contactId) : null;
                $isNew = $contact === null;
                if ($isNew) {
                    $contact = new Contact(['workspace_id' => $workspace->id, 'lifecycle_stage' => $consent !== null ? 'subscriber' : 'lead', 'first_seen_at' => now(), 'last_seen_at' => now()]);
                    $contact->setAttribute('id', $contact->newUniqueId());
                }
                /** @var Contact $contact */
                $changed = false;
                $fill = function (string $field, ?string $value) use ($contact, &$changed): void {
                    if ($value !== null && $value !== '' && ($contact->getAttribute($field) === null || $contact->getAttribute($field) === '')) {
                        $contact->setAttribute($field, $value);
                        $changed = true;
                    }
                };
                if ($email !== null && ($owners->get((string) $emailBidx) ?? $contact->id) === $contact->id) {
                    $fill('email', $email);
                    $fill('email_bidx', $emailBidx);
                }
                if ($phone !== null && ($owners->get((string) $phoneBidx) ?? $contact->id) === $contact->id) {
                    $fill('phone', $phone);
                    $fill('phone_bidx', $phoneBidx);
                }
                foreach (['first_name' => 120, 'last_name' => 120, 'city' => 120, 'region_code' => 16] as $field => $max) {
                    $fill($field, isset($data[$field]) ? mb_substr($data[$field], 0, $max) : null);
                }
                if (isset($data['country']) && strlen($data['country']) === 2) {
                    $fill('country', strtoupper($data['country']));
                }
                if (! empty($data['birthday']) && strtotime($data['birthday']) !== false) {
                    $fill('birthday', date('Y-m-d', (int) strtotime($data['birthday'])));
                }
                if (! empty($data['renewal_at']) && strtotime($data['renewal_at']) !== false) {
                    $fill('renewal_at', date('Y-m-d', (int) strtotime($data['renewal_at'])));
                }
                $tags = array_values(array_filter(array_map('trim', explode(',', (string) ($data['tags'] ?? '')))));
                if ($tags !== []) {
                    $contact->setAttribute('tags', array_values(array_unique(array_merge((array) ($contact->getAttribute('tags') ?? []), $tags))));
                    $changed = true;
                }
                $custom = (array) ($contact->getAttribute('custom_attributes') ?? []);
                foreach ($data as $field => $value) {
                    if (str_starts_with($field, 'custom:') && $value !== '' && ! isset($custom[substr($field, 7)])) {
                        $custom[substr($field, 7)] = mb_substr($value, 0, 500);
                        $changed = true;
                    }
                }
                $contact->setAttribute('custom_attributes', $custom === [] ? null : $custom);
                if ($contact->first_name !== null && ($contact->isDirty('first_name') || $contact->isDirty('last_name'))) {
                    $contact->setAttribute('name_prefix_bidx', IdentityResolver::namePrefixes($workspace->id, $contact->first_name.' '.($contact->last_name ?? '')));
                }
                if (! $isNew && ! $changed && $consent === null) {
                    $totals['skipped']++;

                    continue;
                }
                $contact->save();
                foreach (['email_hash' => $emailBidx, 'phone_hash' => $phoneBidx] as $type => $value) {
                    if ($value !== null && ! $owners->has($value)) {
                        ContactIdentity::query()->create(['workspace_id' => $workspace->id, 'contact_id' => $contact->id, 'type' => $type, 'value' => $value, 'first_seen_at' => now(), 'last_seen_at' => now()]);
                    }
                }
                $totals[$isNew ? 'created' : 'updated']++;

                if ($consent !== null && $consentAt !== null) {
                    foreach ($consent['channels'] as $channel) {
                        $reachable = $channel === 'email' ? $email !== null : $phone !== null;
                        if ($reachable && $consents->handle($workspace, $contact->id, $channel, 'marketing', 'granted', 'import', null, $consentAt, [
                            'import_id' => $import->publicId(), 'declared_source' => $consent['source'], 'filename' => $import->filename, 'row' => (int) $line,
                        ])) {
                            $totals['consents']++;
                        }
                    }
                }
            }
        });
    }
}
