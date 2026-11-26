<?php

declare(strict_types=1);

namespace App\Domain\Privacy\Actions;

use App\Analytics\Ch;
use App\Domain\Commerce\Models\Order;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Contacts\Models\ContactIdentity;
use App\Domain\Measurement\Audiences\RemoveContactFromAudiences;
use App\Domain\Workspaces\Actions\RecordAuditLog;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Tenancy\RedisKey;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/**
 * Supresión de punta a punta (cumplimiento.md §9): bloquea envíos, borra en
 * MariaDB y en ClickHouse por contact_id y sus anonymous_id, y deja solo la
 * evidencia mínima: las revocaciones en consent_events y el índice ciego del
 * correo y el teléfono en suppressions, para no volver a escribirle si sus
 * datos regresan en una importación [validar con abogado]. Los pedidos se
 * conservan sin el vínculo a la persona (registro contable del negocio).
 * Fase 5: leads con su historia, citas y cotizaciones se borran (son de la persona,
 * no un registro contable), y también los registros de webhooks salientes de sus
 * eventos, que guardan el cuerpo enviado (cifrado) durante 30 días.
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

        // Paso 4 de la supresión (cumplimiento.md §9): fuera de las audiencias de anuncios
        // con los identificadores que se enviaron. Si una plataforma falla, la fila queda y la
        // próxima sincronización lo saca (las audiencias guardan esos hashes cifrados).
        $audiences = 0;
        foreach ($ids as $id) {
            $audiences += app(RemoveContactFromAudiences::class)->handle($id);
        }

        // Webhooks salientes (M19): el registro guarda 30 días el cuerpo enviado. Se borran las
        // entregas de sus eventos (antes de borrar los eventos de ClickHouse) y la de `contact.created`.
        $webhookEventIds = $this->webhookEventIds($workspace, $ids, $anonymous);

        $counts = DB::transaction(function () use ($workspace, $ids, $bidx, $anonymous, $webhookEventIds): array {
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
            // Fases 2 y 3: mensajes, conversaciones, flujos, preferencias, fidelización,
            // referidos, solicitudes, avisos y capturas se borran; lo que sirve a las cifras del
            // negocio (carritos, cupones, reseñas publicadas, afiliados) se desvincula.
            $ws = fn (string $table) => DB::table($table)->where('workspace_id', $workspace->id);
            $counts['messages'] = $ws('messages')->whereIn('contact_id', $ids)->delete();
            $counts['conversations'] = $ws('conversations')->whereIn('contact_id', $ids)->delete();
            $runs = $ws('flow_runs')->whereIn('contact_id', $ids)->pluck('id')->all();
            foreach (array_chunk($runs, 500) as $chunk) {
                DB::table('flow_run_steps')->whereIn('run_id', $chunk)->delete();
            }
            $counts['flow_runs'] = $ws('flow_runs')->whereIn('contact_id', $ids)->delete();
            $counts['campaign_recipients'] = $ws('campaign_recipients')->whereIn('contact_id', $ids)->delete();
            $ws('contact_preferences')->whereIn('contact_id', $ids)->delete();
            $counts['carts_unlinked'] = $ws('carts')->whereIn('contact_id', $ids)->update(['contact_id' => null, 'anonymous_id' => null]);
            $counts['coupons_unlinked'] = $ws('coupons_issued')->whereIn('contact_id', $ids)->update(['contact_id' => null]);
            $counts['loyalty_ledger'] = $ws('loyalty_ledger')->whereIn('contact_id', $ids)->delete();
            $counts['referrals'] = $ws('referrals')->where(fn ($q) => $q->whereIn('referrer_contact_id', $ids)->orWhereIn('friend_contact_id', $ids))->delete();
            $ws('referral_fingerprints')->whereIn('contact_id', $ids)->delete();
            $ws('affiliates')->whereIn('contact_id', $ids)->update(['contact_id' => null]);
            $counts['reviews_anonymized'] = $ws('reviews')->whereIn('contact_id', $ids)->update(['contact_id' => null, 'author_name' => null]);
            $counts['review_requests'] = $ws('review_requests')->whereIn('contact_id', $ids)->delete();
            $counts['survey_requests'] = $ws('survey_requests')->whereIn('contact_id', $ids)->delete();
            $counts['product_watches'] = $ws('product_watches')->whereIn('contact_id', $ids)->delete();
            $counts['widget_captures'] = $ws('widget_captures')->whereIn('contact_id', $ids)->delete();
            // Captación sin tienda: envíos de las páginas de captura (con lo que escribió, cifrado) y chats del QR.
            $counts['landing_submissions'] = $ws('landing_submissions')->whereIn('contact_id', $ids)->delete();
            $counts['whatsapp_link_hits'] = $ws('whatsapp_link_hits')->whereIn('contact_id', $ids)->delete();
            // Leads de anuncios (Meta y Google): lo que respondió en el formulario.
            $counts['lead_ad_leads'] = $ws('lead_ad_leads')->whereIn('contact_id', $ids)->delete();
            $ws('tracked_links')->whereIn('contact_id', $ids)->delete();
            // Fase 5: leads (con su historia y notas), citas y cotizaciones; entregas de webhooks.
            $leads = $ws('leads')->whereIn('contact_id', $ids)->pluck('id')->all();
            foreach (array_chunk($leads, 500) as $chunk) {
                $ws('lead_activities')->whereIn('lead_id', $chunk)->delete();
                $ws('lead_assistant_marks')->whereIn('lead_id', $chunk)->delete();
                $ws('lead_tasks')->whereIn('lead_id', $chunk)->delete();
            }
            $counts['lead_tasks'] = $ws('lead_tasks')->whereIn('contact_id', $ids)->delete();
            $counts['leads'] = $ws('leads')->whereIn('contact_id', $ids)->delete();
            $counts['appointments'] = $ws('appointments')->whereIn('contact_id', $ids)->delete();
            $counts['quotes'] = $ws('quotes')->where(function ($q) use ($ids, $anonymous): void {
                $q->whereIn('contact_id', $ids);
                if ($anonymous !== []) {
                    $q->orWhereIn('anonymous_id', $anonymous);
                }
            })->delete();
            $counts['webhook_deliveries'] = 0;
            foreach (array_chunk($webhookEventIds, 500) as $chunk) {
                $counts['webhook_deliveries'] += $ws('webhook_deliveries')->whereIn('event_id', $chunk)->delete();
            }
            $counts['contacts'] = Contact::query()->withTrashed()->whereIn('id', $ids)->forceDelete();

            return $counts;
        });

        $counts['audiences'] = $audiences;

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

    /**
     * IDs de los eventos de la persona que pudieron salir por un webhook: los de ClickHouse de
     * los últimos 35 días (las entregas se guardan 30) y el `contact.created` de cada contacto.
     *
     * @param  list<string>  $ids
     * @param  list<string>  $anonymous
     * @return list<string>
     */
    private function webhookEventIds(Workspace $workspace, array $ids, array $anonymous): array
    {
        if (! DB::table('webhook_deliveries')->where('workspace_id', $workspace->id)->exists()) {
            return [];
        }
        $out = array_map(fn (string $id) => Uuid::uuid5(Uuid::NAMESPACE_URL, 'igr:contact.created:'.$id)->toString(), $ids);
        $rows = Ch::forWorkspace($workspace)->select(
            'SELECT toString(event_id) AS id FROM events WHERE workspace_id = {workspace_id:UInt32} AND occurred_at >= now() - INTERVAL 35 DAY
             AND (has({ids:Array(String)}, contact_id) OR has({anon:Array(String)}, anonymous_id)) LIMIT 200000',
            ['ids' => ExportContactData::chArray($ids), 'anon' => ExportContactData::chArray($anonymous)],
        );
        foreach ($rows as $row) {
            $out[] = (string) $row['id'];
        }

        return array_values(array_unique($out));
    }
}
