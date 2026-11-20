<?php

declare(strict_types=1);

namespace App\Domain\Connections\Actions;

use App\Domain\Connections\Data\TokenSet;
use App\Domain\Connections\Enums\ConnectionProvider;
use App\Domain\Connections\Models\OAuthConnection;
use App\Domain\Connections\Providers\MetaProvider;
use App\Domain\Connections\Support\ConnectionError;
use App\Domain\Messaging\Models\ChannelAccount;
use App\Domain\Workspaces\Actions\RecordAuditLog;

/**
 * WhatsApp con un clic (Embedded Signup v5). La ventana de Meta devuelve el código (vive 5 minutos) y, por
 * `postMessage`, el evento `FINISH` (número nuevo en la Cloud API) o `FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING`
 * (coexistencia: el número sigue en la app de WhatsApp Business) con `waba_id`, `phone_number_id` y `business_id`.
 * https://developers.facebook.com/docs/whatsapp/embedded-signup/implementation
 *
 * 1. Canjea el código por el token del negocio y lo revisa con `debug_token`: que sea de esta app, que tenga
 *    `whatsapp_business_management` y `whatsapp_business_messaging`, y que la WABA esté entre las autorizadas.
 * 2. Guarda la conexión (`meta_whatsapp`, sujeto `waba:{id}`) con el token cifrado.
 * 3. Suscribe la app a los webhooks de la WABA (`POST /{waba}/subscribed_apps`).
 * 4. Con un solo número (o el que mandó la ventana) lo deja listo con AttachWhatsAppNumber; con varios, devuelve
 *    la lista para que la persona elija.
 */
final class CompleteWhatsAppSignup
{
    public const COEXISTENCE_EVENT = 'FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING';

    private const SCOPES = ['whatsapp_business_management', 'whatsapp_business_messaging'];

    public function __construct(
        private readonly CompleteAuthorization $authorization,
        private readonly AttachWhatsAppNumber $attach,
        private readonly RecordAuditLog $audit,
        private readonly MetaProvider $meta = new MetaProvider(ConnectionProvider::MetaWhatsApp),
    ) {}

    /**
     * @param  array{code: string, event: string, waba_id: string, phone_number_id?: string|null, business_id?: string|null}  $input
     * @return array{connection: OAuthConnection, account: ChannelAccount|null, phones: list<array<string, mixed>>}
     *
     * @throws ConnectionError
     */
    public function handle(string $workspaceId, string $userId, array $input): array
    {
        $waba = $input['waba_id'];
        $tokens = $this->meta->exchange($input['code'], null);
        if (array_diff(self::SCOPES, $tokens->scopes) !== []) {
            throw new ConnectionError(__('Meta no entregó los permisos de WhatsApp. Vuelve a conectar y acepta todos los permisos.'), 'scopes');
        }
        $granted = (array) ($tokens->assets['granted'] ?? []);
        $wabas = $granted['whatsapp_business_management'] ?? null;
        if (is_array($wabas) && ! in_array($waba, $wabas, true)) {
            throw new ConnectionError(__('Esa cuenta de WhatsApp no está entre las que autorizaste en Meta.'), 'asset');
        }

        $coexistence = $input['event'] === self::COEXISTENCE_EVENT;
        $subscribed = $this->meta->send('post', $waba.'/subscribed_apps', $tokens->accessToken)->successful();
        $connection = $this->authorization->save($workspaceId, $userId, ConnectionProvider::MetaWhatsApp, 'waba:'.$waba, new TokenSet(
            accessToken: $tokens->accessToken,
            expiresAt: $tokens->expiresAt,
            scopes: $tokens->scopes,
            externalId: isset($input['business_id']) && $input['business_id'] !== '' ? (string) $input['business_id'] : $tokens->externalId,
            accountName: $tokens->accountName,
            assets: $tokens->assets + ['whatsapp' => ['waba_id' => $waba, 'business_id' => $input['business_id'] ?? null, 'coexistence' => $coexistence, 'subscribed' => $subscribed]],
        ));
        $this->audit->handle('connection.connected', $connection, ['provider' => 'meta_whatsapp', 'coexistence' => $coexistence, 'subscribed' => $subscribed], $workspaceId, null, $userId);

        $phones = $this->attach->phones($waba, $tokens->accessToken);
        $chosen = $input['phone_number_id'] ?? null;
        if (($chosen === null || $chosen === '') && count($phones) === 1) {
            $chosen = $phones[0]['id'];
        }
        if ($chosen === null || $chosen === '') {
            if ($phones === []) {
                throw new ConnectionError(__('La cuenta de WhatsApp no tiene números. Agrega uno en el Administrador de WhatsApp y vuelve a conectar.'), 'asset');
            }

            return ['connection' => $connection, 'account' => null, 'phones' => $phones];
        }

        return ['connection' => $connection, 'account' => $this->attach->handle($connection, (string) $chosen), 'phones' => $phones];
    }
}
