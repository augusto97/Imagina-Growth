<?php

declare(strict_types=1);

namespace App\Domain\Mcp\Tools;

use App\Domain\Api\Http\V1\Presenters;
use App\Domain\Commerce\Models\Order;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Mcp\Support\McpContext;
use App\Domain\Services\Leads\Models\Lead;
use App\Support\Phone\Phone;
use App\Support\Pii\BlindIndex;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Resumen de un contacto: datos (enmascarados salvo que el token tenga contacts:pii), compras, segmento RFM, predicciones, últimos pedidos y leads abiertos. Búscalo por contact_id (ct_…), correo o teléfono exactos.')]
#[IsReadOnly]
final class GetContactSummaryTool extends Tool
{
    protected string $name = 'get_contact_summary';

    public function handle(Request $request): Response
    {
        if (($denied = McpContext::deny('contacts:read')) !== null) {
            return $denied;
        }
        $workspace = McpContext::workspace();
        $contact = null;
        if (is_string($id = $request->get('contact_id')) && $id !== '') {
            $contact = Contact::findByPublicId($id);
        } elseif (is_string($email = $request->get('email')) && $email !== '') {
            $contact = Contact::query()->where('email_bidx', BlindIndex::email($workspace->id, BlindIndex::normalizeEmail($email)))->first();
        } elseif (is_string($phone = $request->get('phone')) && $phone !== '') {
            $e164 = Phone::toE164($phone, (string) $workspace->setting('default_phone_country', $workspace->country));
            $contact = $e164 !== null ? Contact::query()->where('phone_bidx', BlindIndex::phone($workspace->id, $e164))->first() : null;
        } else {
            return Response::error(__('Indica contact_id, email o phone.'));
        }
        if ($contact === null || $contact->getAttribute('merged_into_id') !== null) {
            return Response::error(__('No encontramos ese contacto.'));
        }

        $orders = Order::query()->where('contact_id', $contact->id)->orderByDesc('created_at')->limit(5)->get();
        $leads = Lead::query()->where('contact_id', $contact->id)->whereNotIn('stage', Lead::OPEN_STAGES_EXCLUDED)->orderByDesc('created_at')->limit(5)->get();

        return Response::json([
            'currency' => $workspace->currency,
            'contact' => Presenters::contact($contact, McpContext::can('contacts:pii')),
            'predictions' => [
                'predicted_clv_12m' => Presenters::money($contact->getAttribute('predicted_clv_12m_ws')),
                'churn_risk' => $contact->getAttribute('churn_risk'),
                'churn_band' => $contact->getAttribute('churn_band'),
                'expected_next_order_at' => Presenters::date($contact->getAttribute('expected_next_order_at')),
            ],
            'recent_orders' => $orders->map(fn (Order $o) => [
                'id' => $o->publicId(), 'number' => $o->getAttribute('number'), 'status' => $o->status, 'total_ws' => Presenters::money($o->total_ws),
                'created_at' => Presenters::date($o->getAttribute('created_at')),
            ])->values()->all(),
            'open_leads' => $leads->map(fn (Lead $l) => ['id' => $l->publicId(), 'stage' => $l->stage, 'service' => $l->service, 'score' => $l->score, 'temperature' => $l->temperature])->values()->all(),
            'panel_url' => McpContext::panelUrl('contactos/'.$contact->publicId()),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'contact_id' => $schema->string()->description('ID del contacto (ct_…).'),
            'email' => $schema->string()->description('Correo exacto.'),
            'phone' => $schema->string()->description('Teléfono; se normaliza con el país del negocio.'),
        ];
    }
}
