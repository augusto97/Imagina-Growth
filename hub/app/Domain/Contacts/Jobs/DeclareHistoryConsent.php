<?php

declare(strict_types=1);

namespace App\Domain\Contacts\Jobs;

use App\Analytics\Ch;
use App\Domain\Consent\Actions\RecordConsent;
use App\Domain\Consent\Support\ConsentDeclaration;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Contacts\Models\ContactImport;
use App\Domain\Sites\Models\Site;
use App\Domain\Workspaces\Actions\RecordAuditLog;
use App\Support\Tenancy\CurrentWorkspace;
use App\Support\Tenancy\SetWorkspaceContext;
use App\Support\Tenancy\WorkspaceAware;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Consentimiento declarado para el historial que importó el plugin (auditoría F7, cumplimiento.md §3.1). La
 * importación histórica no crea consentimiento: el dueño declara aquí de dónde salió, cómo se obtuvo y su fecha, y
 * se registra con esa evidencia solo para los contactos de ese historial (sus pedidos o clientes importados del
 * sitio) que tienen la dirección del canal. Nunca a quien revocó o se dio de baja.
 */
final class DeclareHistoryConsent implements ShouldQueue, WorkspaceAware
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 1800;

    public int $tries = 1;

    private const CHUNK = 1000;

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
        $status = 'completed';
        $errors = [];
        try {
            $declaration = ConsentDeclaration::validate($import->consent);
            $site = Site::query()->findOrFail((string) $import->getAttribute('site_id'));
            $at = CarbonImmutable::parse($declaration['date'])->startOfDay();
            // Los contactos del historial importado de ese sitio (eventos con source.type = import).
            $ids = array_values(array_filter(array_map(fn (array $r) => (string) $r['contact_id'], Ch::forWorkspace($workspace)->select(
                "SELECT DISTINCT contact_id FROM events WHERE workspace_id = {workspace_id:UInt32} AND site_id = {site:UInt32} AND source_type = 'import'
                   AND event IN ('order.created', 'order.paid', 'customer.registered') AND contact_id != ''",
                ['site' => (int) $site->getAttribute('ch_id')],
            ))));
            foreach (array_chunk($ids, self::CHUNK) as $chunk) {
                DB::transaction(function () use ($workspace, $import, $chunk, $declaration, $at, $consents, &$totals): void {
                    $contacts = Contact::query()->whereIn('id', $chunk)->whereNull('merged_into_id')->get(['id', 'email_bidx', 'phone_bidx']);
                    foreach ($contacts as $contact) {
                        $totals['rows']++;
                        $blocked = ConsentDeclaration::blockedChannels($workspace->id, $contact->id, $contact->email_bidx, $contact->phone_bidx);
                        $granted = 0;
                        foreach ($declaration['channels'] as $channel) {
                            $reachable = $channel === 'email' ? $contact->email_bidx !== null : $contact->phone_bidx !== null;
                            if (! $reachable || in_array($channel, $blocked, true)) {
                                continue;
                            }
                            if ($consents->handle($workspace, $contact->id, $channel, 'marketing', 'granted', 'import', null, $at, [
                                'import_id' => $import->publicId(), 'scope' => 'history', 'site_id' => $import->getAttribute('site_id'),
                                'declared_source' => $declaration['source'], 'legal_basis' => $declaration['legal_basis'],
                            ])) {
                                $granted++;
                            }
                        }
                        $totals['consents'] += $granted;
                        $totals[$granted > 0 ? 'updated' : 'skipped']++;
                    }
                });
            }
        } catch (Throwable $e) {
            report($e);
            $status = 'failed';
            $errors[] = ['row' => 0, 'message' => __('La declaración se detuvo: :msg', ['msg' => mb_substr($e->getMessage(), 0, 200)])];
        }

        $import->forceFill(['status' => $status, 'totals' => $totals, 'errors' => $errors, 'finished_at' => now()])->save();
        $audit->handle('contacts.history_consent_declared', $import, ['totals' => $totals, 'channels' => $import->consent['channels'] ?? [], 'legal_basis' => $import->consent['legal_basis'] ?? null], $workspace->id, null, $import->getAttribute('user_id'));
    }
}
