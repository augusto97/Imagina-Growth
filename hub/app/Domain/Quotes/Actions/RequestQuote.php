<?php

declare(strict_types=1);

namespace App\Domain\Quotes\Actions;

use App\Domain\Contacts\Models\Contact;
use App\Domain\Quotes\Models\Quote;
use App\Domain\Quotes\Models\QuoteCalculator;
use App\Domain\Services\Contracts\LeadIntake;
use App\Domain\Services\Data\LeadIntakeInput;
use App\Domain\Sites\Models\Site;
use App\Domain\Workspaces\Models\Workspace;
use App\Ingest\HubEvents;
use App\Support\Ids\PrefixedId;
use Ramsey\Uuid\Uuid;

/**
 * Guarda una cotización (M18): la fila de `quotes`, el lead (`LeadIntake`, origen
 * quote, valor = el punto medio del rango) y el evento `quote.requested` cuando
 * hay contacto, seguido de `quote.sent` (v8, `source = calculator`): el
 * cotizador ya le mostró el precio, así que la cotización quedó enviada (flujo 22).
 * Sin contacto (cotizador sin captura) queda solo la fila con el `anonymous_id`.
 */
final class RequestQuote
{
    public function __construct(private readonly LeadIntake $leads, private readonly HubEvents $events) {}

    /**
     * @param  array{min: string, max: string, mid: string, answers: array<string, mixed>, lines: list<array{label: string, amount: string}>}  $estimate
     * @param  array<string, mixed>  $source
     */
    public function handle(Workspace $workspace, QuoteCalculator $calculator, array $estimate, ?Contact $contact, ?Site $site, ?string $anonymousId, array $source = []): Quote
    {
        $currency = (string) (($calculator->definition['currency'] ?? null) ?: ($workspace->currency ?: 'COP'));
        $quote = Quote::query()->create([
            'workspace_id' => $workspace->id, 'calculator_id' => $calculator->id, 'contact_id' => $contact?->id,
            'answers' => $estimate['answers'], 'estimate_min' => $estimate['min'], 'estimate_max' => $estimate['max'],
            'currency' => $currency, 'anonymous_id' => $anonymousId,
        ]);
        if ($contact === null) {
            return $quote;
        }
        $leadId = $this->leads->capture(new LeadIntakeInput(
            workspaceId: $workspace->id, contactId: $contact->id, origin: 'quote', originRef: $quote->publicId(),
            service: $calculator->name, value: $estimate['mid'], source: $source, siteId: $site?->id,
        ));
        $leadUlid = $leadId !== null ? (string) preg_replace('/^[a-z]+_/', '', $leadId) : null;
        if ($leadUlid !== null && strlen($leadUlid) === 26) {
            $quote->forceFill(['lead_id' => $leadUlid])->save();
        }
        $this->events->emit($workspace, 'quote.requested', [
            'quote_id' => $quote->publicId(), 'calculator_id' => $calculator->publicId(), 'calculator_name' => mb_substr($calculator->name, 0, 160),
            'estimate_min' => $estimate['min'], 'estimate_max' => $estimate['max'], 'currency' => $currency,
            'lead_id' => $quote->lead_id !== null ? PrefixedId::encode('ld', $quote->lead_id) : null,
        ], $contact->id, $site, eventId: Uuid::uuid5('6f1b3c2a-4d5e-5f60-8a9b-0c1d2e3f4a5b', 'quote.requested:'.$quote->id)->toString(), anonymousId: $anonymousId);
        $this->events->emit($workspace, 'quote.sent', [
            'source' => 'calculator', 'quote_id' => $quote->publicId(),
            'lead_id' => $quote->lead_id !== null ? PrefixedId::encode('ld', $quote->lead_id) : null,
            'service' => mb_substr($calculator->name, 0, 160), 'value' => $estimate['mid'], 'currency' => $currency,
        ], $contact->id, $site, eventId: Uuid::uuid5('6f1b3c2a-4d5e-5f60-8a9b-0c1d2e3f4a5b', 'quote.sent:'.$quote->id)->toString(), anonymousId: $anonymousId);

        return $quote;
    }
}
