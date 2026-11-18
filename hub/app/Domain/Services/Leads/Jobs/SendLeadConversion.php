<?php

declare(strict_types=1);

namespace App\Domain\Services\Leads\Jobs;

use App\Domain\Contacts\Models\Contact;
use App\Domain\Measurement\Audiences\AudienceEligibility;
use App\Domain\Measurement\Conversions\ConversionInput;
use App\Domain\Measurement\Conversions\TargetRegistry;
use App\Domain\Measurement\Jobs\DeliverConversion;
use App\Domain\Measurement\Models\AdDestination;
use App\Domain\Services\Leads\Models\Lead;
use App\Domain\Services\Leads\Models\LeadActivity;
use App\Domain\Services\Leads\Support\LeadEvents;
use App\Domain\Sites\Models\Site;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Tenancy\RedisKey;
use App\Support\Tenancy\SetWorkspaceContext;
use App\Support\Tenancy\WorkspaceAware;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Conversión offline de un lead ganado (M18): `lead.won` con su valor va a Meta
 * (Conversions API, `action_source = system_generated`) y a Google Ads (Data
 * Manager API, acción de conversión «lead ganado» con el gclid si se conoce),
 * para que los anuncios optimicen hacia leads que se vuelven clientes.
 *
 * - Una vez por lead (`leads.offline_conversion_at` y un candado por destino).
 * - Consentimiento como en las audiencias (AudienceEligibility, cumplimiento.md
 *   §3.3): en modo estricto exige el consentimiento `ads` concedido; si no, basta
 *   con que no lo haya revocado.
 * - Correo y teléfono salen con hash (eventos.md §7); el ID del evento es
 *   determinista, así la plataforma deduplica un reenvío.
 * - Solo destinos que tienen `lead.won` activo (por defecto sí) y los objetivos
 *   que lo soportan (Meta CAPI y Data Manager; GA4 y TikTok no).
 */
final class SendLeadConversion implements ShouldQueue, WorkspaceAware
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const EVENT = 'lead.won';

    private const TARGETS = ['meta_capi', 'google_data_manager'];

    public int $tries = 3;

    public function __construct(public string $workspaceIdValue, public string $leadId) {}

    public function workspaceId(): string
    {
        return $this->workspaceIdValue;
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [new SetWorkspaceContext];
    }

    public function handle(TargetRegistry $targets): void
    {
        $workspace = Workspace::query()->find($this->workspaceIdValue);
        $lead = Lead::query()->find($this->leadId);
        if ($workspace === null || $lead === null || $lead->won_at === null || $lead->offline_conversion_at !== null) {
            return;
        }
        $contact = Contact::query()->find($lead->contact_id);
        if ($contact === null) {
            return;
        }
        $eligible = AudienceEligibility::apply(DB::table('contacts as c')->where('c.workspace_id', $workspace->id)->where('c.id', $contact->id), $workspace)->exists();
        if (! $eligible) {
            LeadActivity::log($lead, 'conversion', 'system', ['status' => 'skipped', 'reason' => 'consent']);

            return;
        }
        $destinations = AdDestination::query()->where('status', 'active')->get()->filter(fn (AdDestination $d) => $d->sends(self::EVENT));
        $input = $this->input($workspace, $lead, $contact);
        $queued = [];
        foreach ($destinations as $destination) {
            foreach (self::TARGETS as $key) {
                $target = $targets->all()[$key] ?? null;
                if ($target === null || ! $target->supports($destination, self::EVENT)) {
                    continue;
                }
                if (! Cache::add(RedisKey::for($workspace->id, "conv:{$destination->id}:{$key}:{$input->eventId}"), 1, now()->addDays(30))) {
                    continue;
                }
                $payload = $target->build($input, $destination);
                DeliverConversion::dispatch($workspace->id, $destination->id, $key, $payload, [
                    'source_event_id' => $input->eventId,
                    'source_event' => self::EVENT,
                    'conversion' => (string) (config('igr-measurement.events')[self::EVENT][$target->platform()->value] ?? self::EVENT),
                    'contact_id' => $contact->id,
                    'match_keys' => $target->matchKeys($payload),
                    'first_attempt_at' => time(),
                    'attempt' => 1,
                ])->onQueue($target->platform()->queue());
                $queued[] = $target->platform()->value;
            }
        }
        $lead->forceFill(['offline_conversion_at' => now()])->save();
        LeadActivity::log($lead, 'conversion', 'system', ['status' => $queued === [] ? 'no_destination' : 'queued', 'platforms' => array_values(array_unique($queued))]);
    }

    private function input(Workspace $workspace, Lead $lead, Contact $contact): ConversionInput
    {
        $source = $lead->source ?? [];
        $str = fn (string $k): ?string => is_string($source[$k] ?? null) && $source[$k] !== '' ? $source[$k] : null;
        $fbclid = $str('fbclid');
        $site = $lead->site_id !== null ? Site::query()->find($lead->site_id) : null;
        $wonAt = $lead->won_at ?? now();

        return new ConversionInput(
            workspaceId: $workspace->id,
            siteId: (string) ($lead->site_id ?? ''),
            event: self::EVENT,
            eventId: LeadEvents::eventId($workspace->id, 'conversion:'.$lead->id),
            occurredAt: $wonAt->getTimestamp(),
            sourceUrl: $site?->url,
            referrer: null,
            ip: null,
            userAgent: null,
            contactId: $contact->id,
            anonymousId: null,
            sessionId: null,
            defaultCountry: (string) ($workspace->setting('default_phone_country') ?? $workspace->country),
            email: $contact->email,
            phone: $contact->phone,
            firstName: $contact->first_name,
            lastName: $contact->last_name,
            city: is_string($contact->getAttribute('city')) ? $contact->getAttribute('city') : null,
            state: is_string($contact->getAttribute('region_code')) ? $contact->getAttribute('region_code') : null,
            country: is_string($contact->getAttribute('country')) ? $contact->getAttribute('country') : $workspace->country,
            // Formato de Meta para el clic de un anuncio (fbc), con la fecha del lead.
            fbc: $fbclid !== null && $lead->created_at !== null ? 'fb.1.'.$lead->created_at->getTimestampMs().'.'.$fbclid : null,
            fbclid: $fbclid,
            gclid: $str('gclid'),
            gbraid: $str('gbraid'),
            wbraid: $str('wbraid'),
            value: $lead->value_ws,
            currency: $workspace->currency,
            orderId: $lead->publicId(),
            consentGranted: true,
        );
    }
}
