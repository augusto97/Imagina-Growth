<?php

declare(strict_types=1);

namespace App\Domain\Capture\LeadAds;

use App\Domain\Bookings\Support\BookingSettings;
use App\Domain\Capture\Models\LeadAdLead;
use App\Domain\Capture\Models\LeadAdSource;
use App\Domain\Consent\Actions\RecordConsent;
use App\Domain\Contacts\Actions\CaptureContact;
use App\Domain\Contacts\Data\CaptureInput;
use App\Domain\Services\Contracts\LeadIntake;
use App\Domain\Services\Data\LeadIntakeInput;
use App\Domain\Workspaces\Models\Workspace;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Convierte un lead de un formulario de anuncios en contacto + lead (rediseño v2 §11.3, modulos.md M18):
 *
 * - El contacto con `CaptureContact` (cifrado e índice ciego, misma identidad que el resto; origen
 *   `meta_lead_ads:{formulario}` o `google_lead_form:{formulario}`), sin consentimientos de por sí.
 * - **Consentimiento de marketing solo si el formulario trae una casilla o una pregunta asignada a un canal** (en el
 *   panel, por formulario) y la persona la marcó o dijo que sí; se registra con su prueba (plataforma, formulario,
 *   lead, pregunta, respuesta y el texto que vio). Sin eso, el lead entra sin consentimiento de marketing: solo se le
 *   puede responder 1:1 (cumplimiento.md §4.1, información pedida por el consumidor).
 * - El lead por `LeadIntake` con origen `meta_lead_ads` o `google_lead_form` (eventos.md v8), referencia `lal_…`, la
 *   campaña, el conjunto o grupo, el anuncio, el formulario y el gclid (para la conversión offline que ya existe), con
 *   el puntaje, el vendedor y el aviso de siempre.
 *
 * Debe correr dentro del contexto del espacio de trabajo.
 */
final class ImportAdLead
{
    public function __construct(
        private readonly CaptureContact $capture,
        private readonly RecordConsent $consents,
        private readonly LeadIntake $leads,
    ) {}

    public function handle(Workspace $workspace, LeadAdSource $source, LeadAdLead $row, AdLeadData $data): LeadAdLead
    {
        $origin = $row->platform === LeadAdSource::META ? 'meta_lead_ads' : 'google_lead_form';
        $formId = $data->formId ?? $row->form_id;
        $email = $data->email();
        $phone = $data->phone();
        [$first, $last] = $data->names();
        $at = $data->createdAt ?? CarbonImmutable::instance($row->received_at);
        $row->forceFill([
            'form_id' => $formId !== null ? mb_substr($formId, 0, 64) : null,
            'campaign_id' => $data->campaignId !== null ? mb_substr($data->campaignId, 0, 64) : $row->campaign_id,
            'adset_id' => $data->adsetId !== null ? mb_substr($data->adsetId, 0, 64) : $row->adset_id,
            'ad_id' => $data->adId !== null ? mb_substr($data->adId, 0, 64) : $row->ad_id,
            'data' => $data->toArray(),
        ]);
        if ($email === null && $phone === null) {
            $row->forceFill(['status' => 'failed', 'error' => __('El formulario no trajo correo ni teléfono.'), 'processed_at' => now()])->save();

            return $row;
        }
        try {
            $result = $this->capture->handle($workspace, BookingSettings::site($workspace), new CaptureInput(
                source: mb_substr($origin.':'.($formId ?? '-'), 0, 64), email: $email, phone: $phone, firstName: $first, lastName: $last,
            ));
        } catch (InvalidArgumentException $e) {
            $row->forceFill(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 500), 'processed_at' => now()])->save();

            return $row;
        }
        $contact = $result->contact;

        // Consentimiento: solo la casilla o la pregunta asignada al canal, marcada o respondida con un sí.
        $form = $source->form($formId);
        $granted = [];
        foreach ($form['consent'] as $rule) {
            $channel = $rule['channel'];
            $reachable = $channel === 'email' ? $contact->email !== null : $contact->phone !== null;
            if (! $reachable || ! in_array($channel, ['email', 'whatsapp', 'sms'], true) || ! $data->accepted($rule['key'])) {
                continue;
            }
            $this->consents->handle($workspace, $contact->id, $channel, 'marketing', 'granted', $origin, null, $at, array_filter([
                'platform' => $row->platform,
                'form_id' => $formId,
                'lead' => $row->external_id,
                'question' => $rule['key'],
                'answer' => array_key_exists(AdLeadData::key($rule['key']), $data->checkboxes) ? 'checked' : ($data->answers[AdLeadData::key($rule['key'])] ?? null),
                'text' => $rule['text'] ?? null,
                'campaign_id' => $data->campaignId,
                'ad_id' => $data->adId,
                'created_time' => $at->toIso8601String(),
            ]));
            $granted[] = $channel;
        }

        $sourceData = $this->source($row, $data, $form['name'] ?? $data->formName);
        if ($contact->getAttribute('first_source') === null) {
            $contact->forceFill(['first_source' => array_filter([
                'channel' => $sourceData['channel'], 'utm_source' => $sourceData['utm_source'] ?? null, 'utm_medium' => $sourceData['utm_medium'] ?? null,
                'utm_campaign' => $sourceData['utm_campaign'] ?? null, 'at' => $at->toIso8601String(), 'via' => $origin,
            ])])->save();
        }
        $serviceKey = $form['service_key'] !== null ? AdLeadData::key($form['service_key']) : null;
        $leadId = $this->leads->capture(new LeadIntakeInput(
            workspaceId: $workspace->id,
            contactId: $contact->id,
            origin: $origin,
            originRef: $row->publicId(),
            service: $serviceKey !== null ? ($data->answers[$serviceKey] ?? null) : null,
            source: $sourceData,
            siteId: BookingSettings::site($workspace)?->id,
            message: $data->message(),
        ));
        $row->forceFill([
            'status' => 'processed', 'error' => null, 'contact_id' => $contact->id, 'lead_id' => $leadId,
            'consents' => array_values(array_unique($granted)), 'processed_at' => now(),
        ])->save();
        $source->forceFill(['last_lead_at' => now()])->save();

        return $row;
    }

    /**
     * De dónde vino el lead (sin datos personales): canal, UTM equivalentes, campaña, conjunto o grupo, anuncio,
     * formulario y gclid.
     *
     * @return array<string, string>
     */
    private function source(LeadAdLead $row, AdLeadData $data, ?string $formName): array
    {
        $meta = $row->platform === LeadAdSource::META;
        $network = strtolower((string) $data->network);

        return array_filter([
            'channel' => $meta ? ($data->organic ? 'organic_social' : 'paid_meta') : 'paid_google',
            'utm_source' => $meta ? ($network === 'ig' || $network === 'instagram' ? 'instagram' : 'facebook') : 'google',
            'utm_medium' => $meta ? ($data->organic ? 'social' : 'paid_social') : 'cpc',
            'utm_campaign' => $data->campaignName ?? $data->campaignId,
            'campaign_id' => $data->campaignId,
            'adset_id' => $data->adsetId,
            'adset_name' => $data->adsetName,
            'ad_id' => $data->adId,
            'ad_name' => $data->adName,
            'form_id' => ($meta ? 'meta:' : 'google:').($data->formId ?? $row->form_id ?? '-'),
            'form_name' => $formName !== null ? mb_substr($formName, 0, 200) : null,
            'gclid' => $data->gclid,
        ], fn ($v) => $v !== null && $v !== '');
    }
}
