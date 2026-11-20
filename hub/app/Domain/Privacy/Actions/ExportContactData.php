<?php

declare(strict_types=1);

namespace App\Domain\Privacy\Actions;

use App\Analytics\Ch;
use App\Domain\Commerce\Models\Order;
use App\Domain\Consent\Models\ConsentEvent;
use App\Domain\Consent\Models\ContactConsent;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Contacts\Models\ContactIdentity;
use App\Domain\Services\Leads\Brief\LeadBrief;
use App\Domain\Workspaces\Models\Workspace;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Derecho de acceso (cumplimiento.md §9): todo lo que el hub guarda de una
 * persona — perfil, identificadores, pedidos, consentimientos con su
 * evidencia, encuestas, notas, leads, citas, cotizaciones y eventos — en un JSON.
 */
final class ExportContactData
{
    /** @return array<string, mixed> */
    public function handle(Workspace $workspace, Contact $contact): array
    {
        $ids = self::contactIds($contact);
        $anonymous = array_values(ContactIdentity::query()->whereIn('contact_id', $ids)->where('type', 'anonymous_id')->pluck('value')->map(fn ($v) => (string) $v)->all());

        $events = Ch::forWorkspace($workspace)->select(
            'SELECT toString(event_id) AS event_id, event, occurred_at, source_type, page_type, path, channel, city, product_id, order_id, toString(revenue) AS revenue, currency, toJSONString(properties) AS properties
             FROM events WHERE workspace_id = {workspace_id:UInt32} AND (has({ids:Array(String)}, contact_id) OR has({anon:Array(String)}, anonymous_id))
             ORDER BY occurred_at LIMIT 50000',
            ['ids' => self::chArray($ids), 'anon' => self::chArray($anonymous)],
        );

        return [
            'generated_at' => now()->toIso8601String(),
            'workspace' => ['name' => $workspace->name, 'legal_name' => $workspace->getAttribute('legal_name')],
            'profile' => [
                'id' => $contact->publicId(),
                'first_name' => $contact->first_name, 'last_name' => $contact->last_name,
                'email' => $contact->email, 'phone' => $contact->phone,
                'city' => $contact->getAttribute('city'), 'region_code' => $contact->getAttribute('region_code'), 'country' => $contact->getAttribute('country'),
                'birthday' => $contact->getAttribute('birthday')?->toDateString(),
                'renewal_at' => $contact->getAttribute('renewal_at')?->toDateString(),
                'lifecycle_stage' => $contact->getAttribute('lifecycle_stage'),
                'orders_count' => $contact->getAttribute('orders_count'), 'revenue_total_ws' => (string) $contact->getAttribute('revenue_total_ws'),
                'rfm_segment' => $contact->getAttribute('rfm_segment'), 'hdyhau' => $contact->getAttribute('hdyhau'),
                'tags' => $contact->getAttribute('tags'), 'custom_attributes' => $contact->getAttribute('custom_attributes'),
                'first_seen_at' => $contact->first_seen_at?->toIso8601String(), 'last_seen_at' => $contact->last_seen_at?->toIso8601String(),
            ],
            'identifiers' => ContactIdentity::query()->whereIn('contact_id', $ids)->get(['type', 'value', 'first_seen_at', 'last_seen_at'])
                ->map(fn ($i) => ['type' => $i->type, 'value' => in_array($i->type, ['email_hash', 'phone_hash'], true) ? '(índice cifrado)' : $i->value, 'first_seen_at' => $i->getAttribute('first_seen_at')?->toIso8601String()])->all(),
            'orders' => Order::query()->whereIn('contact_id', $ids)->with('items')->orderBy('created_at')->get()
                ->map(fn (Order $o) => [
                    'number' => $o->getAttribute('number'), 'status' => $o->status, 'created_at' => $o->created_at?->toIso8601String(),
                    'total' => $o->total, 'currency' => $o->getAttribute('currency'), 'payment_method' => $o->getAttribute('payment_method_title'),
                    'items' => $o->items->map(fn ($i) => ['name' => $i->getAttribute('name'), 'qty' => (string) $i->getAttribute('qty'), 'line_total' => (string) $i->getAttribute('line_total')])->all(),
                ])->all(),
            'consents' => ContactConsent::query()->whereIn('contact_id', $ids)->get(['channel', 'purpose', 'status', 'source', 'legal_text_version', 'granted_at', 'revoked_at'])->toArray(),
            'consent_evidence' => ConsentEvent::query()->whereIn('contact_id', $ids)->orderBy('occurred_at')->get(['channel', 'purpose', 'action', 'source', 'legal_text_version', 'evidence', 'occurred_at'])->toArray(),
            'surveys' => DB::table('survey_responses')->where('workspace_id', $workspace->id)->whereIn('contact_id', $ids)->get(['answers', 'created_at'])->map(fn ($r) => ['answers' => json_decode((string) $r->answers, true), 'created_at' => $r->created_at])->all(),
            'notes' => DB::table('contact_notes')->where('workspace_id', $workspace->id)->whereIn('contact_id', $ids)->pluck('created_at')->map(fn ($d) => ['created_at' => $d, 'body' => '(nota interna del negocio)'])->all(),
            'messages' => DB::table('messages')->where('workspace_id', $workspace->id)->whereIn('contact_id', $ids)->orderBy('created_at')
                ->get(['channel', 'direction', 'kind', 'status', 'sent_at', 'delivered_at', 'opened_at', 'clicked_at', 'created_at'])->map(fn ($r) => (array) $r)->all(),
            'loyalty' => [
                'points_balance' => (int) $contact->getAttribute('points_balance'),
                'wallet_balance' => (string) $contact->getAttribute('wallet_balance_ws'),
                'ledger' => DB::table('loyalty_ledger')->where('workspace_id', $workspace->id)->whereIn('contact_id', $ids)->orderBy('created_at')
                    ->get(['kind', 'delta', 'currency', 'status', 'reason', 'expires_at', 'created_at'])->map(fn ($r) => (array) $r)->all(),
            ],
            'referrals' => [
                'code' => $contact->getAttribute('referral_code'),
                'referred_friends' => DB::table('referrals')->where('workspace_id', $workspace->id)->whereIn('referrer_contact_id', $ids)->count(),
                'referred_by_code' => DB::table('referrals')->where('workspace_id', $workspace->id)->whereIn('friend_contact_id', $ids)->value('code'),
            ],
            'reviews' => DB::table('reviews')->where('workspace_id', $workspace->id)->whereIn('contact_id', $ids)->orderBy('created_at')
                ->get(['external_product_id', 'rating', 'title', 'body', 'status', 'created_at'])->map(fn ($r) => (array) $r)->all(),
            'coupons' => DB::table('coupons_issued')->where('workspace_id', $workspace->id)->whereIn('contact_id', $ids)->orderBy('created_at')
                ->get(['code', 'source_type', 'kind', 'amount', 'status', 'expires_at', 'created_at'])->map(fn ($r) => (array) $r)->all(),
            'product_alerts' => DB::table('product_watches')->where('workspace_id', $workspace->id)->whereIn('contact_id', $ids)
                ->get(['external_product_id', 'kind', 'channel', 'status', 'created_at'])->map(fn ($r) => (array) $r)->all(),
            // Fase 5 (M18): leads con su historia (las notas internas del negocio no se exportan),
            // citas y cotizaciones con lo que la persona respondió.
            'leads' => self::leads($workspace, $ids),
            'appointments' => DB::table('appointments as a')->leftJoin('booking_services as s', 's.id', '=', 'a.service_id')
                ->where('a.workspace_id', $workspace->id)->whereIn('a.contact_id', $ids)->orderBy('a.starts_at')
                ->get(['s.name as service', 'a.starts_at', 'a.ends_at', 'a.status', 'a.channel', 'a.created_at'])->map(fn ($r) => (array) $r)->all(),
            'quotes' => DB::table('quotes as q')->leftJoin('quote_calculators as c', 'c.id', '=', 'q.calculator_id')
                ->where('q.workspace_id', $workspace->id)->where(function ($w) use ($ids, $anonymous): void {
                    $w->whereIn('q.contact_id', $ids);
                    if ($anonymous !== []) {
                        $w->orWhereIn('q.anonymous_id', $anonymous);
                    }
                })->orderBy('q.created_at')
                ->get(['c.name as calculator', 'q.answers', 'q.estimate_min', 'q.estimate_max', 'q.currency', 'q.created_at'])
                ->map(fn ($r) => ['answers' => json_decode((string) $r->answers, true)] + array_diff_key((array) $r, ['answers' => true]))->all(),
            'events' => array_map(function (array $e): array {
                $e['properties'] = json_decode((string) $e['properties'], true);

                return $e;
            }, $events),
        ];
    }

    /**
     * @param  list<string>  $ids
     * @return list<array<string, mixed>>
     */
    private static function leads(Workspace $workspace, array $ids): array
    {
        $leads = DB::table('leads')->where('workspace_id', $workspace->id)->whereIn('contact_id', $ids)->orderBy('created_at')
            ->get(['id', 'stage', 'service', 'value_ws', 'origin', 'source', 'score', 'temperature', 'brief', 'created_at', 'first_response_at', 'won_at', 'lost_at', 'lost_reason']);
        $activities = DB::table('lead_activities')->where('workspace_id', $workspace->id)->whereIn('lead_id', $leads->pluck('id')->all())->orderBy('created_at')
            ->get(['lead_id', 'kind', 'created_at'])->groupBy('lead_id');

        return array_values($leads->map(function ($l) use ($activities): array {
            $source = (array) (json_decode((string) $l->source, true) ?: []);

            return [
                'stage' => $l->stage, 'service' => $l->service, 'value' => $l->value_ws, 'origin' => $l->origin,
                'source' => array_intersect_key($source, array_flip(['channel', 'utm_source', 'utm_medium', 'utm_campaign', 'page', 'form_name', 'referrer_host'])),
                'score' => (int) $l->score, 'temperature' => $l->temperature,
                // La ficha que se llenó con lo que la persona escribió en el chat (solo los valores).
                'brief' => self::brief($l->brief),
                'created_at' => $l->created_at, 'first_response_at' => $l->first_response_at, 'won_at' => $l->won_at, 'lost_at' => $l->lost_at, 'lost_reason' => $l->lost_reason,
                'history' => ($activities->get($l->id) ?? collect())->map(fn ($a) => ['kind' => $a->kind, 'at' => $a->created_at, 'detail' => $a->kind === 'note' ? '(nota interna del negocio)' : null])->values()->all(),
            ];
        })->all());
    }

    /** @return array<string, string> */
    private static function brief(mixed $raw): array
    {
        if (! is_string($raw) || $raw === '') {
            return [];
        }
        try {
            $decoded = json_decode(Crypt::decryptString($raw), true);
        } catch (\Throwable) {
            return [];
        }

        return LeadBrief::facts(is_array($decoded) ? $decoded : null);
    }

    /**
     * El contacto y los que se fusionaron en él.
     *
     * @return list<string>
     */
    public static function contactIds(Contact $contact): array
    {
        return array_values(array_unique(array_merge([$contact->id], Contact::query()->where('merged_into_id', $contact->id)->pluck('id')->map(fn ($v) => (string) $v)->all())));
    }

    /**
     * Arreglo de texto para un parámetro {x:Array(String)} de ClickHouse.
     *
     * @param  array<array-key, string>  $values
     */
    public static function chArray(array $values): string
    {
        return '['.implode(',', array_map(fn ($v) => "'".str_replace(['\\', "'"], ['\\\\', "\\'"], $v)."'", $values)).']';
    }
}
