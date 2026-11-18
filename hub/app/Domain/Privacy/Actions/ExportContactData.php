<?php

declare(strict_types=1);

namespace App\Domain\Privacy\Actions;

use App\Analytics\Ch;
use App\Domain\Commerce\Models\Order;
use App\Domain\Consent\Models\ConsentEvent;
use App\Domain\Consent\Models\ContactConsent;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Contacts\Models\ContactIdentity;
use App\Domain\Workspaces\Models\Workspace;
use Illuminate\Support\Facades\DB;

/**
 * Derecho de acceso (cumplimiento.md §9): todo lo que el hub guarda de una
 * persona — perfil, identificadores, pedidos, consentimientos con su
 * evidencia, encuestas, notas y eventos — en un JSON.
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
            'events' => array_map(function (array $e): array {
                $e['properties'] = json_decode((string) $e['properties'], true);

                return $e;
            }, $events),
        ];
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
