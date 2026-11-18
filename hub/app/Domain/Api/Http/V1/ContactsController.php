<?php

declare(strict_types=1);

namespace App\Domain\Api\Http\V1;

use App\Domain\Api\Actions\ResolveApiContact;
use App\Domain\Api\Support\ApiContext;
use App\Domain\Api\Support\ApiError;
use App\Domain\Consent\Actions\ConsentTexts;
use App\Domain\Consent\Actions\RecordConsent;
use App\Domain\Consent\Models\ContactConsent;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Workspaces\Actions\RecordAuditLog;
use App\Support\Phone\Phone;
use App\Support\Pii\BlindIndex;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * Contactos (M03). Los datos personales solo se ven completos con el alcance `contacts:pii`.
 */
#[Group('Contactos', weight: 1)]
final class ContactsController
{
    use Paginates;

    public function __construct(private readonly CurrentWorkspace $current) {}

    /**
     * Listar o buscar contactos
     *
     * Busca por correo o teléfono exactos (con el índice ciego: el correo nunca se guarda en claro)
     * o lista los contactos cambiados desde una fecha. Requiere `contacts:read`.
     *
     * @queryParam email string Correo exacto.
     * @queryParam phone string Teléfono; se normaliza a E.164 con el país del negocio.
     * @queryParam updated_since string Fecha ISO 8601.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['sometimes', 'string', 'max:255'],
            'phone' => ['sometimes', 'string', 'max:32'],
            'updated_since' => ['sometimes', 'date'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $workspace = $this->current->get();
        $query = Contact::query()->whereNull('merged_into_id');
        if (is_string($email = $request->query('email')) && $email !== '') {
            $query->where('email_bidx', BlindIndex::email($workspace->id, BlindIndex::normalizeEmail($email)));
        }
        if (is_string($phone = $request->query('phone')) && $phone !== '') {
            $e164 = Phone::toE164($phone, (string) $workspace->setting('default_phone_country', $workspace->country));
            $query->where('phone_bidx', $e164 !== null ? BlindIndex::phone($workspace->id, $e164) : '-');
        }
        if (($since = $this->since($request, 'updated_since')) !== null) {
            $query->where('updated_at', '>=', $since);
        }
        $pii = ApiContext::can($request, 'contacts:pii');

        return $this->paginate($query, $request, fn (Contact $c) => Presenters::contact($c, $pii));
    }

    /**
     * Ver un contacto
     *
     * Con sus consentimientos por canal. Requiere `contacts:read`.
     */
    public function show(Request $request, string $contact): JsonResponse
    {
        $model = Contact::findByPublicId($contact);
        if ($model === null || $model->getAttribute('merged_into_id') !== null) {
            return ApiError::make(404, 'not_found', __('No existe ese contacto.'));
        }

        return new JsonResponse(['data' => $this->withConsents($model, ApiContext::can($request, 'contacts:pii'))]);
    }

    /**
     * Crear o actualizar un contacto
     *
     * Une por correo o teléfono (la misma resolución de identidad de la ingesta). Los consentimientos
     * solo se registran si vienen en `consents`, con origen `api` y la versión del texto legal que
     * aceptó la persona. Requiere `contacts:write`. Acepta `Idempotency-Key`.
     */
    public function upsert(Request $request, ResolveApiContact $resolver, RecordConsent $consents, ConsentTexts $texts, RecordAuditLog $audit): JsonResponse
    {
        $data = $request->validate([
            'email' => ['nullable', 'email:rfc', 'max:255', 'required_without:phone'],
            'phone' => ['nullable', 'string', 'max:32', 'required_without:email'],
            'first_name' => ['nullable', 'string', 'max:80'],
            'last_name' => ['nullable', 'string', 'max:80'],
            'city' => ['nullable', 'string', 'max:120'],
            'country' => ['nullable', 'string', 'size:2'],
            'renewal_at' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'overwrite' => ['sometimes', 'boolean'],
            'tags' => ['sometimes', 'array', 'max:20'],
            'tags.*' => ['string', 'max:40'],
            'site_id' => ['sometimes', 'string', 'max:40'],
            'consents' => ['sometimes', 'array', 'max:3'],
            'consents.*.channel' => ['required', Rule::in(['email', 'whatsapp', 'sms'])],
            'consents.*.action' => ['required', Rule::in(['granted', 'revoked'])],
            'consents.*.legal_text_version' => ['nullable', 'string', 'max:32'],
            'consents.*.occurred_at' => ['nullable', 'date'],
            'consents.*.page_url' => ['nullable', 'string', 'max:500'],
        ]);
        $workspace = $this->current->get();
        $site = ResolveApiContact::site($data['site_id'] ?? null);
        if ($site === null) {
            return ApiError::make(422, 'no_site', __('Conecta un sitio antes de crear contactos por la API, o envía un site_id válido.'), ['site_id' => [__('Sitio no encontrado.')]]);
        }
        $client = ApiContext::client($request);
        $now = CarbonImmutable::now();

        try {
            [$contact, $created] = DB::transaction(function () use ($workspace, $site, $data, $resolver, $consents, $texts, $client, $now): array {
                [$contact, $created] = $resolver->handle($workspace, $site, $data);
                if (isset($data['tags'])) {
                    $contact->setAttribute('tags', array_values(array_unique([...((array) ($contact->getAttribute('tags') ?? [])), ...$data['tags']])));
                    $contact->save();
                }
                // Fecha de renovación (flujo 20): se escribe cuando viene, también para borrarla con null.
                if (array_key_exists('renewal_at', $data)) {
                    $contact->setAttribute('renewal_at', $data['renewal_at']);
                    $contact->save();
                }
                foreach ($data['consents'] ?? [] as $consent) {
                    $channel = (string) $consent['channel'];
                    if (($channel === 'email' && $contact->email === null) || ($channel !== 'email' && $contact->phone === null)) {
                        continue; // no se consiente un canal sin su dirección
                    }
                    $version = $consent['legal_text_version'] ?? ($consent['action'] === 'granted' ? $texts->latest($workspace, $channel)?->version : null);
                    $at = isset($consent['occurred_at']) ? CarbonImmutable::parse((string) $consent['occurred_at']) : $now;
                    $consents->handle($workspace, $contact->id, $channel, 'marketing', $consent['action'] === 'granted' ? 'granted' : 'revoked', 'api', $version, $at->greaterThan($now) ? $now : $at, array_filter([
                        'api_client' => $client->publicId(),
                        'page_url' => $consent['page_url'] ?? null,
                    ]));
                }

                return [$contact->refresh(), $created];
            });
        } catch (InvalidArgumentException $e) {
            return ApiError::make(422, 'validation_failed', ApiError::defaultMessage(422), ['phone' => [$e->getMessage()]]);
        }
        $audit->handle($created ? 'contact.created_by_api' : 'contact.updated_by_api', $contact, ['client' => $client->publicId()], $workspace->id);

        return new JsonResponse(['data' => $this->withConsents($contact, ApiContext::can($request, 'contacts:pii')), 'created' => $created], $created ? 201 : 200);
    }

    /** @return array<string, mixed> */
    private function withConsents(Contact $contact, bool $pii): array
    {
        return Presenters::contact($contact, $pii) + [
            'consents' => ContactConsent::query()->where('contact_id', $contact->id)->orderBy('channel')->get()
                ->map(fn (ContactConsent $c) => [
                    'channel' => $c->channel, 'purpose' => $c->purpose, 'status' => $c->status, 'source' => $c->source,
                    'legal_text_version' => $c->legal_text_version,
                    'granted_at' => Presenters::date($c->granted_at), 'revoked_at' => Presenters::date($c->revoked_at),
                ])->values()->all(),
        ];
    }
}
