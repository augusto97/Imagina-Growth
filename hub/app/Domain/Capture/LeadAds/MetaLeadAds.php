<?php

declare(strict_types=1);

namespace App\Domain\Capture\LeadAds;

use App\Domain\Capture\Models\LeadAdSource;
use App\Domain\Connections\Enums\ConnectionProvider;
use App\Domain\Connections\Models\OAuthConnection;
use App\Domain\Connections\Providers\MetaProvider;
use App\Domain\Connections\Support\ConnectionError;
use Illuminate\Http\Client\Response;

/**
 * Meta Lead Ads con la conexión de Meta del espacio de trabajo (Login for Business, hub/CLAUDE.md §19) y el mismo
 * cliente HTTP y versión de la Graph API que las conexiones (`MetaProvider`):
 *
 * - Suscribir la página al campo `leadgen` (`POST /{página}/subscribed_apps`) sin perder los campos que ya tenía la app
 *   en esa página (Messenger): se leen los vigentes y se manda la unión.
 * - Los formularios de la página (`GET /{página}/leadgen_forms`) con sus preguntas y casillas, para elegir cuál es el
 *   consentimiento de cada canal.
 * - Leer un lead (`GET /{leadgen_id}`) con el token de la página.
 *
 * Los permisos que hacen falta (`igr-capture.meta_lead_scopes`) se revisan contra los que entregó Meta al conectar; si
 * faltan, el estado lo dice en palabras. https://developers.facebook.com/docs/marketing-api/guides/lead-ads [verificar]
 */
final class MetaLeadAds
{
    public const LEAD_FIELDS = 'id,created_time,field_data,custom_disclaimer_responses,form_id,ad_id,ad_name,adset_id,adset_name,campaign_id,campaign_name,is_organic,platform';

    public function __construct(private readonly MetaProvider $meta = new MetaProvider) {}

    /** La conexión de Meta (Login for Business) del espacio de trabajo activo, si existe. */
    public static function connection(): ?OAuthConnection
    {
        return OAuthConnection::query()->where('provider', ConnectionProvider::Meta->value)->where('subject', '')
            ->orderByRaw("status = 'active' DESC")->first();
    }

    /**
     * Los permisos que le faltan a la conexión para Lead Ads, con su nombre en palabras.
     *
     * @return list<array{scope: string, label: string}>
     */
    public static function missingScopes(OAuthConnection $connection): array
    {
        $out = [];
        foreach ((array) config('igr-capture.meta_lead_scopes') as $scope) {
            if (! $connection->hasScope((string) $scope)) {
                $out[] = ['scope' => (string) $scope, 'label' => self::scopeLabel((string) $scope)];
            }
        }

        return $out;
    }

    public static function scopeLabel(string $scope): string
    {
        return match ($scope) {
            'leads_retrieval' => __('leer los clientes potenciales de tus formularios'),
            'pages_manage_metadata' => __('recibir los avisos de tu página'),
            'pages_show_list' => __('ver tus páginas'),
            'pages_read_engagement' => __('leer la información de tu página'),
            'pages_manage_ads' => __('administrar los anuncios de tu página'),
            default => $scope,
        };
    }

    /**
     * Suscribe la app al campo `leadgen` de la página, conservando los campos que ya tenía. Devuelve el error en
     * palabras o null si quedó suscrita.
     */
    public function subscribe(string $pageId, string $pageToken): ?string
    {
        $fields = $this->currentFields($pageId, $pageToken);
        $fields[] = 'leadgen';
        $response = $this->meta->send('post', $pageId.'/subscribed_apps', $pageToken, ['subscribed_fields' => implode(',', array_values(array_unique($fields)))]);

        return $response->successful() ? null : self::explain($response);
    }

    /** Quita `leadgen` de la suscripción de la página y deja los demás campos (Messenger) como estaban. */
    public function unsubscribe(string $pageId, string $pageToken): void
    {
        $fields = array_values(array_diff($this->currentFields($pageId, $pageToken), ['leadgen']));
        if ($fields === []) {
            $this->meta->send('delete', $pageId.'/subscribed_apps', $pageToken);

            return;
        }
        $this->meta->send('post', $pageId.'/subscribed_apps', $pageToken, ['subscribed_fields' => implode(',', $fields)]);
    }

    /**
     * Los formularios de la página con sus preguntas y casillas (las que vienen marcadas por defecto no sirven como
     * consentimiento).
     *
     * @return list<array{id: string, name: string, status: string|null, questions: list<array{key: string, label: string, type: string|null}>, checkboxes: list<array{key: string, text: string, required: bool, checked_by_default: bool}>}>
     *
     * @throws ConnectionError
     */
    public function forms(LeadAdSource $source): array
    {
        $response = $this->meta->get((string) $source->external_id.'/leadgen_forms', [
            'fields' => 'id,name,status,questions{key,label,type},legal_content{custom_disclaimer{checkboxes{key,text,is_required,is_checked_by_default}}}',
            'limit' => 100,
        ], (string) $source->secret);
        if (! $response->successful()) {
            throw new ConnectionError(self::explain($response), 'provider');
        }
        $out = [];
        foreach ((array) $response->json('data', []) as $form) {
            if (! is_array($form) || ! is_scalar($form['id'] ?? null)) {
                continue;
            }
            $questions = [];
            foreach ((array) ($form['questions'] ?? []) as $q) {
                if (is_array($q) && is_string($q['key'] ?? null)) {
                    $questions[] = ['key' => AdLeadData::key($q['key']), 'label' => (string) ($q['label'] ?? $q['key']), 'type' => isset($q['type']) ? (string) $q['type'] : null];
                }
            }
            $boxes = [];
            foreach ((array) ($form['legal_content']['custom_disclaimer']['checkboxes'] ?? []) as $box) {
                if (is_array($box) && is_scalar($box['key'] ?? null)) {
                    $boxes[] = ['key' => AdLeadData::key((string) $box['key']), 'text' => (string) ($box['text'] ?? ''), 'required' => (bool) ($box['is_required'] ?? false),
                        'checked_by_default' => (bool) ($box['is_checked_by_default'] ?? false)];
                }
            }
            $out[] = ['id' => (string) $form['id'], 'name' => (string) ($form['name'] ?? $form['id']), 'status' => isset($form['status']) ? (string) $form['status'] : null,
                'questions' => $questions, 'checkboxes' => $boxes];
        }

        return $out;
    }

    /**
     * El lead completo. Devuelve los datos o lanza el error en palabras (`permanent` si es de permisos o del token: no
     * sirve reintentar hasta que se vuelva a conectar).
     *
     * @return array<string, mixed>
     *
     * @throws ConnectionError
     */
    public function lead(LeadAdSource $source, string $leadgenId): array
    {
        $response = $this->meta->get($leadgenId, ['fields' => self::LEAD_FIELDS], (string) $source->secret);
        if (! $response->successful()) {
            $code = (int) $response->json('error.code');
            $permanent = in_array($code, [10, 100, 190, 200, 3, 803], true) || $response->status() === 403;
            throw new ConnectionError(self::explain($response), $permanent ? 'scopes' : 'provider', $permanent);
        }

        return (array) $response->json();
    }

    /** @return list<string> los campos de la página a los que la app ya está suscrita */
    private function currentFields(string $pageId, string $pageToken): array
    {
        try {
            $response = $this->meta->get($pageId.'/subscribed_apps', [], $pageToken);
        } catch (ConnectionError) {
            return [];
        }
        $appId = (string) config('igr-connections.meta.app_id');
        foreach ((array) $response->json('data', []) as $app) {
            if (is_array($app) && (string) ($app['id'] ?? '') === $appId) {
                return array_values(array_filter(array_map(fn ($f) => is_array($f) ? (string) ($f['name'] ?? '') : (string) $f, (array) ($app['subscribed_fields'] ?? []))));
            }
        }

        return [];
    }

    /** El error de Meta en palabras para la persona (sin códigos ni pasos de consola). */
    public static function explain(Response $response): string
    {
        $code = (int) $response->json('error.code');

        return match (true) {
            $code === 190 => __('El acceso a la página venció o se revocó. Vuelve a conectar Meta en Conexiones y conecta la página otra vez.'),
            in_array($code, [10, 200, 3], true) || $response->status() === 403 => __('Meta no deja leer los clientes potenciales de esta página: a la conexión le falta el permiso para leerlos o la persona que conectó Meta no administra los anuncios de la página. Vuelve a conectar Meta en Conexiones con una persona que sí los administre y acepta todos los permisos.'),
            default => __('Meta respondió con un error: :msg', ['msg' => MetaProvider::errorMessage($response)]),
        };
    }
}
