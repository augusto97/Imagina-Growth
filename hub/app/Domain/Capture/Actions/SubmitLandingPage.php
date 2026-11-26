<?php

declare(strict_types=1);

namespace App\Domain\Capture\Actions;

use App\Domain\Bookings\Support\BookingSettings;
use App\Domain\Capture\Models\LandingPage;
use App\Domain\Capture\Models\LandingSubmission;
use App\Domain\Capture\Support\CaptureCounters;
use App\Domain\Consent\Actions\ConsentTexts;
use App\Domain\Contacts\Actions\CaptureContact;
use App\Domain\Contacts\Data\CaptureInput;
use App\Domain\Onsite\Support\PublicWidgetConfig;
use App\Domain\Services\Contracts\LeadIntake;
use App\Domain\Services\Data\LeadIntakeInput;
use App\Domain\Sites\Models\Site;
use App\Domain\Workspaces\Models\Workspace;
use App\Ingest\Enrichment\ChannelClassifier;
use App\Ingest\HubEvents;
use App\Support\Phone\Phone;
use InvalidArgumentException;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * Envío de una página de captura (`POST /p/{slug}`): valida los campos de la página, crea o identifica el contacto
 * con `CaptureContact` (cifrado e índice ciego, como el resto; origen `landing:lp_…`) y registra cada casilla marcada
 * con su prueba (página, hash de la IP y del navegador, texto legal y su versión, copia del formulario). Después
 * emite `form.submitted` desde el hub (para los flujos, los segmentos y los webhooks), crea el lead con origen
 * `landing` y la atribución (UTM, `ref`, identificadores de clic, si llegó por el QR) por `LeadIntake` (puntaje,
 * vendedor y aviso como cualquier lead) y cuenta el envío. Debe correr dentro del contexto del espacio de trabajo.
 */
final class SubmitLandingPage
{
    public const FORM_PLUGIN = 'igr_landing';

    public function __construct(
        private readonly CaptureContact $capture,
        private readonly HubEvents $events,
        private readonly LeadIntake $leads,
        private readonly ConsentTexts $texts,
        private readonly PublicWidgetConfig $widgets,
        private readonly ChannelClassifier $channels,
    ) {}

    /**
     * @param  array<string, mixed>  $input  lo que llegó del formulario
     * @param  array{ip: string|null, user_agent: string|null, url: string}  $meta
     * @param  array<string, string>  $touch  utm_*, identificadores de clic, ref, aid, rh (host del referente) y via
     * @return array{errors: array<string, string>}|array{submission: LandingSubmission}
     */
    public function handle(Workspace $workspace, LandingPage $page, array $input, array $meta, array $touch): array
    {
        [$values, $errors] = $this->values($workspace, $page, is_array($input['f'] ?? null) ? $input['f'] : []);
        if ($errors !== []) {
            return ['errors' => $errors];
        }
        $offered = (array) ($page->consent_channels ?? []);
        $given = is_array($input['consent'] ?? null) ? $input['consent'] : [];
        $consents = [];
        foreach ($offered as $channel) {
            $consents[(string) $channel] = in_array($given[$channel] ?? null, ['1', 1, true, 'on'], true);
        }
        $this->texts->current($workspace); // las casillas que vio la persona son las del texto vigente
        $shown = collect($this->widgets->legalConsents($workspace))->keyBy('channel');
        [$first, $last] = self::splitName($values['name'] ?? null);
        $site = $this->site($workspace, $page);
        $via = ($touch['via'] ?? 'link') === 'qr' ? 'qr' : 'link';
        $source = $this->source($page, $touch, $via);

        try {
            $result = $this->capture->handle($workspace, $site, new CaptureInput(
                source: 'landing:'.$page->publicId(),
                email: $values['email'] ?? null,
                phone: $values['phone'] ?? null,
                firstName: $first,
                lastName: $last,
                anonymousId: $touch['aid'] ?? null,
                consents: $consents,
                pageUrl: $meta['url'],
                ip: $meta['ip'],
                userAgent: $meta['user_agent'],
                evidence: [
                    'form' => 'landing:'.$page->publicId(),
                    'form_snapshot' => [
                        'title' => $page->text('title'),
                        'fields' => array_map(fn (array $f) => ['key' => $f['key'], 'label' => $f['label'] ?? '', 'required' => (bool) ($f['required'] ?? false)], (array) ($page->fields ?? [])),
                        'checkboxes' => array_values(array_map(fn (string $c) => ['channel' => $c, 'text' => (string) ($shown[$c]['label'] ?? ''), 'version' => (string) ($shown[$c]['version'] ?? '')], $offered)),
                    ],
                    'via' => $via,
                ],
            ));
        } catch (InvalidArgumentException $e) {
            return ['errors' => ['phone' => $e->getMessage()]];
        }
        $contact = $result->contact;
        if ($contact->getAttribute('first_source') === null) {
            $contact->forceFill(['first_source' => array_filter([
                'channel' => $source['channel'] ?? null, 'utm_source' => $source['utm_source'] ?? null, 'utm_medium' => $source['utm_medium'] ?? null,
                'utm_campaign' => $source['utm_campaign'] ?? null, 'at' => now()->toIso8601String(), 'via' => 'landing',
            ])])->save();
        }

        $submission = LandingSubmission::query()->create([
            'workspace_id' => $workspace->id,
            'page_id' => $page->id,
            'contact_id' => $contact->id,
            'via' => $via,
            'source' => $source,
            'data' => $values,
            'consents' => $result->granted,
            'was_known' => $result->wasKnown,
        ]);

        $this->emitForm($workspace, $page, $submission, $site, $values, $consents, $touch, $meta['url']);
        try {
            $leadId = $this->leads->capture(new LeadIntakeInput(
                workspaceId: $workspace->id,
                contactId: $contact->id,
                origin: 'landing',
                originRef: $submission->publicId(),
                service: $values['service'] ?? null,
                source: $source,
                siteId: $site?->id,
                message: $this->message($page, $values),
            ));
            if ($leadId !== null) {
                $submission->forceFill(['lead_id' => $leadId])->save();
            }
        } catch (Throwable $e) {
            report($e); // el contacto y el consentimiento ya quedaron: el lead se puede crear después a mano
        }
        CaptureCounters::submission($workspace->id, CaptureCounters::PAGE, $page->id, $via);

        return ['submission' => $submission];
    }

    /**
     * @param  array<mixed>  $given
     * @return array{0: array<string, string>, 1: array<string, string>} valores limpios y errores por campo
     */
    private function values(Workspace $workspace, LandingPage $page, array $given): array
    {
        $values = [];
        $errors = [];
        $country = strtoupper((string) $workspace->setting('default_phone_country', $workspace->country ?: 'CO'));
        foreach ((array) ($page->fields ?? []) as $field) {
            $key = (string) ($field['key'] ?? '');
            $type = (string) ($field['type'] ?? 'text');
            $raw = $given[$key] ?? null;
            $value = is_scalar($raw) ? trim(self::plain((string) $raw)) : '';
            if ($type === 'checkbox') {
                $value = in_array($raw, ['1', 'on', 'true'], true) ? __('Sí') : '';
            }
            if ($value === '') {
                if ((bool) ($field['required'] ?? false)) {
                    $errors[$key] = match ($key) {
                        'email' => __('Escribe tu correo.'),
                        'phone' => __('Escribe tu número de WhatsApp.'),
                        'name' => __('Escribe tu nombre.'),
                        default => $type === 'checkbox' ? __('Marca esta casilla para continuar.') : __('Este dato es obligatorio.'),
                    };
                }

                continue;
            }
            $error = match (true) {
                $key === 'email' => filter_var($value, FILTER_VALIDATE_EMAIL) === false || mb_strlen($value) > 190 ? __('Escribe un correo válido.') : null,
                $key === 'phone' => Phone::toE164($value, $country) === null
                    ? __('Escribe un número de WhatsApp válido, con el indicativo si no es de :country.', ['country' => $country]) : null,
                $type === 'select' => ! in_array($value, (array) ($field['options'] ?? []), true) ? __('Elige una de las opciones.') : null,
                default => null,
            };
            if ($error !== null) {
                $errors[$key] = $error;

                continue;
            }
            $values[$key] = match ($key) {
                'email' => mb_strtolower($value),
                'phone' => mb_substr($value, 0, 40),
                'name' => mb_substr($value, 0, 120),
                default => mb_substr($value, 0, $type === 'textarea' ? 2000 : 300),
            };
        }
        if ($errors === [] && ! isset($values['email']) && ! isset($values['phone'])) {
            $errors[in_array('phone', array_column((array) ($page->fields ?? []), 'key'), true) ? 'phone' : 'email'] = __('Escribe tu correo o tu WhatsApp para poder responderte.');
        }

        return [$values, $errors];
    }

    /**
     * De dónde vino el lead (sin datos personales): canal agrupado, UTM, `ref`, identificadores de clic, la página y
     * si llegó por el QR impreso.
     *
     * @param  array<string, string>  $touch
     * @return array<string, string>
     */
    private function source(LandingPage $page, array $touch, string $via): array
    {
        $utm = [];
        foreach (['source', 'medium', 'campaign', 'content', 'term'] as $k) {
            if (($touch['utm_'.$k] ?? '') !== '') {
                $utm[$k] = $touch['utm_'.$k];
            }
        }
        $clicks = array_filter(array_intersect_key($touch, array_flip(['gclid', 'gbraid', 'wbraid', 'fbclid', 'ttclid', 'msclkid'])), fn ($v) => $v !== '');
        $channel = $this->channels->classify([
            'utm' => $utm, 'click_ids' => $clicks, 'referrer' => isset($touch['rh']) ? 'https://'.$touch['rh'] : null,
            'site_host' => null, 'referral_code' => $touch['ref'] ?? null,
        ]);

        return array_filter([
            'channel' => $channel,
            'utm_source' => $utm['source'] ?? null,
            'utm_medium' => $utm['medium'] ?? null,
            'utm_campaign' => $utm['campaign'] ?? null,
            'utm_content' => $utm['content'] ?? null,
            'referral_code' => $touch['ref'] ?? null,
            'referrer_host' => $touch['rh'] ?? null,
            'page' => '/p/'.$page->slug,
            'form_id' => 'landing:'.$page->publicId(),
            'form_name' => mb_substr($page->text('title') ?: $page->name, 0, 200),
            'via' => $via,
        ] + $clicks, fn ($v) => $v !== null && $v !== '');
    }

    /**
     * @param  array<string, string>  $values
     * @param  array<string, bool>  $consents
     * @param  array<string, string>  $touch
     */
    private function emitForm(Workspace $workspace, LandingPage $page, LandingSubmission $submission, ?Site $site, array $values, array $consents, array $touch, string $url): void
    {
        [$first, $last] = self::splitName($values['name'] ?? null);
        $fields = array_filter(['first_name' => $first, 'last_name' => $last] + array_diff_key($values, ['name' => true]), fn ($v) => $v !== null && $v !== '');
        $utm = [];
        foreach (['source', 'medium', 'campaign', 'content', 'term'] as $k) {
            if (($touch['utm_'.$k] ?? '') !== '') {
                $utm[$k] = mb_substr($touch['utm_'.$k], 0, 200);
            }
        }
        try {
            $this->events->emit($workspace, 'form.submitted', [
                'form_plugin' => self::FORM_PLUGIN,
                'form_id' => $page->publicId(),
                'form_name' => mb_substr($page->text('title') ?: $page->name, 0, 200),
                'fields' => $fields,
                'page_path' => '/p/'.$page->slug,
                'consents' => array_combine(array_map(fn (string $c) => $c.'_marketing', array_keys($consents)), array_values($consents)),
            ], $submission->contact_id, $site, now()->toImmutable(),
                Uuid::uuid5(Uuid::NAMESPACE_URL, 'igr:landing:'.$submission->id)->toString(),
                $touch['aid'] ?? null,
                array_filter([
                    'page' => ['url' => mb_substr($url, 0, 2048), 'path' => '/p/'.$page->slug, 'title' => mb_substr($page->text('title'), 0, 300),
                        'referrer' => isset($touch['rh']) ? 'https://'.$touch['rh'].'/' : ''],
                    'page_type' => 'landing',
                    'utm' => $utm !== [] ? $utm : null,
                    'click_ids' => array_filter(array_intersect_key($touch, array_flip(['gclid', 'gbraid', 'wbraid', 'fbclid', 'ttclid', 'msclkid']))) ?: null,
                    'referral_code' => $touch['ref'] ?? null,
                    'locale' => (string) ($workspace->getAttribute('locale') ?: 'es-CO'),
                ]),
            );
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * El texto para la calificación con IA: el mensaje y las respuestas de los campos propios.
     *
     * @param  array<string, string>  $values
     */
    private function message(LandingPage $page, array $values): ?string
    {
        $lines = [];
        if (($values['message'] ?? '') !== '') {
            $lines[] = $values['message'];
        }
        foreach ((array) ($page->fields ?? []) as $field) {
            $key = (string) ($field['key'] ?? '');
            if (str_starts_with($key, 'custom_') && ($values[$key] ?? '') !== '') {
                $lines[] = ($field['label'] ?? $key).': '.$values[$key];
            }
        }

        return $lines !== [] ? implode("\n", $lines) : null;
    }

    /** El sitio con el que se registran los contactos: el de la página o el primero conectado (o ninguno). */
    private function site(Workspace $workspace, LandingPage $page): ?Site
    {
        if ($page->site_id !== null) {
            $site = Site::query()->whereKey($page->site_id)->whereIn('status', [Site::STATUS_CONNECTED, Site::STATUS_DEGRADED])->first();
            if ($site !== null) {
                return $site;
            }
        }

        return BookingSettings::site($workspace);
    }

    /** @return array{0: string|null, 1: string|null} */
    private static function splitName(?string $name): array
    {
        if ($name === null || trim($name) === '') {
            return [null, null];
        }
        $parts = preg_split('/\s+/', trim($name), 2) ?: [''];

        return [mb_substr((string) $parts[0], 0, 80), isset($parts[1]) && $parts[1] !== '' ? mb_substr($parts[1], 0, 80) : null];
    }

    /** Sin etiquetas ni caracteres de control. */
    private static function plain(string $value): string
    {
        return (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', strip_tags($value));
    }
}
