<?php

declare(strict_types=1);

namespace App\Domain\Privacy\Actions;

use App\Analytics\Ch;
use App\Domain\Commerce\Models\Order;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Contacts\Models\ContactIdentity;
use App\Domain\Workspaces\Actions\RecordAuditLog;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Tenancy\RedisKey;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Supresión de punta a punta (cumplimiento.md §9): bloquea envíos, borra en
 * MariaDB y en ClickHouse por contact_id y sus anonymous_id, y deja solo la
 * evidencia mínima: las revocaciones en consent_events y el índice ciego del
 * correo y el teléfono en suppressions, para no volver a escribirle si sus
 * datos regresan en una importación [validar con abogado]. Los pedidos se
 * conservan sin el vínculo a la persona (registro contable del negocio).
 */
final class DeleteContactData
{
    public function __construct(private readonly RecordAuditLog $audit) {}

    /** @return array<string, int> conteos de lo borrado (evidencia de la solicitud) */
    public function handle(Workspace $workspace, Contact $contact, ?string $userId = null): array
    {
        $ids = ExportContactData::contactIds($contact);
        $identities = ContactIdentity::query()->whereIn('contact_id', $ids)->get(['type', 'value']);
        $anonymous = [];
        foreach ($identities as $identity) {
            if ($identity->type === 'anonymous_id') {
                $anonymous[] = (string) $identity->value;
            }
        }
        $bidx = Contact::query()->whereIn('id', $ids)->get(['email_bidx', 'phone_bidx']);

        $counts = DB::transaction(function () use ($workspace, $ids, $bidx): array {
            $counts = [];
            foreach ($bidx as $row) {
                foreach (['email' => $row->getAttribute('email_bidx'), 'whatsapp' => $row->getAttribute('phone_bidx'), 'sms' => $row->getAttribute('phone_bidx')] as $channel => $value) {
                    if (is_string($value) && $value !== '') {
                        DB::table('suppressions')->insertOrIgnore(['workspace_id' => $workspace->id, 'channel' => $channel, 'value_bidx' => $value, 'reason' => 'manual', 'created_at' => now()]);
                    }
                }
            }
            $counts['orders_unlinked'] = Order::query()->whereIn('contact_id', $ids)->update(['contact_id' => null]);
            $counts['identities'] = ContactIdentity::query()->whereIn('contact_id', $ids)->delete();
            $counts['consents'] = DB::table('contact_consents')->where('workspace_id', $workspace->id)->whereIn('contact_id', $ids)->delete();
            $counts['consent_evidence'] = DB::table('consent_events')->where('workspace_id', $workspace->id)->whereIn('contact_id', $ids)->where('action', '!=', 'revoked')->delete();
            $counts['notes'] = DB::table('contact_notes')->where('workspace_id', $workspace->id)->whereIn('contact_id', $ids)->delete();
            $counts['surveys'] = DB::table('survey_responses')->where('workspace_id', $workspace->id)->whereIn('contact_id', $ids)->delete();
            $counts['segment_memberships'] = DB::table('segment_memberships')->where('workspace_id', $workspace->id)->whereIn('contact_id', $ids)->delete();
            DB::table('contact_merges')->where('workspace_id', $workspace->id)->where(fn ($q) => $q->whereIn('winner_id', $ids)->orWhereIn('loser_id', $ids))->delete();
            DB::table('data_requests')->where('workspace_id', $workspace->id)->whereIn('contact_id', $ids)->update(['contact_id' => null]);
            $counts['contacts'] = Contact::query()->withTrashed()->whereIn('id', $ids)->forceDelete();

            return $counts;
        });

        // ClickHouse: borrado liviano (modelo-de-datos.md §10).
        $ch = Ch::forWorkspace($workspace);
        $params = ['ids' => ExportContactData::chArray($ids), 'anon' => ExportContactData::chArray($anonymous)];
        $counts['events'] = (int) $ch->value('SELECT count() FROM events WHERE workspace_id = {workspace_id:UInt32} AND (has({ids:Array(String)}, contact_id) OR has({anon:Array(String)}, anonymous_id))', $params);
        $ch->execute('DELETE FROM events WHERE workspace_id = {workspace_id:UInt32} AND (has({ids:Array(String)}, contact_id) OR has({anon:Array(String)}, anonymous_id))', $params, ['mutations_sync' => 1]);
        $ch->execute('DELETE FROM identity_map WHERE workspace_id = {workspace_id:UInt32} AND (has({ids:Array(String)}, contact_id) OR has({anon:Array(String)}, anonymous_id))', $params, ['mutations_sync' => 1]);
        $ch->execute('DELETE FROM sessions WHERE workspace_id = {workspace_id:UInt32} AND (has({ids:Array(String)}, contact_id) OR has({anon:Array(String)}, anonymous_id))', $params, ['mutations_sync' => 1]);

        foreach ($anonymous as $anon) {
            Cache::forget(RedisKey::for($workspace->id, 'anon:'.$anon));
        }

        $this->audit->handle('contact.deleted', null, ['contact' => 'ct_'.$ids[0], 'counts' => $counts], $workspace->id, null, $userId);

        return $counts;
    }
}
