<?php

declare(strict_types=1);

namespace App\Domain\Capture\Http;

use App\Domain\Billing\Contracts\Entitlements;
use App\Domain\Capture\Models\LandingPage;
use App\Domain\Capture\Models\WhatsAppLink;
use App\Domain\Capture\Support\CaptureUrls;
use App\Domain\Capture\Support\WhatsAppNumber;
use App\Domain\Sites\Models\Site;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Ids\PrefixedId;
use Illuminate\Support\Facades\URL;

/**
 * Forma de las páginas de captura y de los chats de WhatsApp en la API del panel, con sus direcciones (la página, la
 * del QR con UTM `qr`, la vista previa firmada) y los avisos en palabras de lo que falta para que funcionen bien.
 */
final class CapturePresenter
{
    /**
     * @param  array<string, int>|null  $stats
     * @return array<string, mixed>
     */
    public static function page(Workspace $workspace, LandingPage $page, bool $detail = false, ?array $stats = null): array
    {
        $api = '/api/w/'.$workspace->publicId().'/capture/pages/'.$page->publicId();
        $link = $page->wa_link_id !== null ? WhatsAppLink::query()->find($page->wa_link_id) : null;
        $content = (array) ($page->content ?? []);
        $origin = CaptureUrls::page($workspace, $page);
        $content['logo_src'] = $page->text('logo_key') !== '' ? $origin.'/i/'.$page->text('logo_key') : ($page->text('logo_url') ?: null);
        $content['image_src'] = $page->text('image_key') !== '' ? $origin.'/i/'.$page->text('image_key') : ($page->text('image_url') ?: null);

        $out = [
            'id' => $page->publicId(),
            'name' => $page->name,
            'slug' => $page->slug,
            'status' => $page->status,
            'title' => $page->text('title'),
            'url' => CaptureUrls::page($workspace, $page),
            'qr_url' => CaptureUrls::pageQr($workspace, $page),
            'qr' => ['svg' => $api.'/qr?format=svg', 'png' => $api.'/qr?format=png'],
            'consent_channels' => array_values((array) ($page->consent_channels ?? [])),
            'site_id' => $page->site_id !== null ? PrefixedId::encode('st', $page->site_id) : null,
            'whatsapp_link' => $link !== null ? ['id' => $link->publicId(), 'code' => $link->code, 'url' => CaptureUrls::waLink($workspace, $link)] : null,
            'stats' => $stats,
            'published_at' => $page->published_at?->toIso8601String(),
            'created_at' => $page->created_at?->toIso8601String(),
            'updated_at' => $page->updated_at?->toIso8601String(),
        ];
        if (! $detail) {
            return $out;
        }

        return $out + [
            'content' => $content,
            'fields' => array_values((array) ($page->fields ?? [])),
            'settings' => ['indexable' => (bool) $page->setting('indexable', false)],
            'preview_url' => URL::temporarySignedRoute('capture.page', now()->addMinutes(30), ['slug' => $page->slug, 'vista_previa' => 1]),
            'warnings' => self::pageWarnings($workspace, $page),
        ];
    }

    /**
     * @param  array<string, int>|null  $stats
     * @return array<string, mixed>
     */
    public static function waLink(Workspace $workspace, WhatsAppLink $link, ?array $stats = null): array
    {
        $api = '/api/w/'.$workspace->publicId().'/capture/whatsapp-links/'.$link->publicId();
        $account = WhatsAppNumber::account($link->channel_account_id);
        $digits = WhatsAppNumber::digits($workspace, $account);

        return [
            'id' => $link->publicId(),
            'kind' => $link->kind,
            'name' => $link->name,
            'code' => $link->code,
            'message' => $link->message,
            'status' => $link->status,
            'page_id' => $link->page_id !== null ? PrefixedId::encode(LandingPage::ID_PREFIX, $link->page_id) : null,
            'channel_account_id' => $link->channel_account_id !== null ? PrefixedId::encode('cha', $link->channel_account_id) : null,
            'number' => $digits !== null ? '+'.$digits : null,
            'url' => CaptureUrls::waLink($workspace, $link),
            'qr_url' => CaptureUrls::waLinkQr($workspace, $link),
            'wa_me_url' => $digits !== null ? CaptureUrls::waMe($digits, $link->message, $link->code) : null,
            'qr' => ['svg' => $api.'/qr?format=svg', 'png' => $api.'/qr?format=png'],
            'settings' => [
                'create_lead' => $link->createsLead(),
                'ask_consent' => $link->asksConsent(),
                'service' => is_string($link->setting('service')) ? $link->setting('service') : null,
            ],
            'warnings' => self::waWarnings($workspace, $account, $digits),
            'stats' => $stats,
            'created_at' => $link->created_at?->toIso8601String(),
            'updated_at' => $link->updated_at?->toIso8601String(),
        ];
    }

    /** @return list<array{code: string, message: string}> */
    public static function pageWarnings(Workspace $workspace, LandingPage $page): array
    {
        $out = [];
        $privacy = $workspace->getAttribute('privacy_policy_url');
        if (! is_string($privacy) || $privacy === '') {
            $out[] = ['code' => 'no_privacy_url', 'message' => strtoupper((string) $workspace->country) === 'MX'
                ? __('Agrega el enlace a tu aviso de privacidad en Consentimiento: en México es obligatorio junto a cada formulario y sin él la página no se puede publicar.')
                : __('Agrega el enlace a tu política de tratamiento de datos en Consentimiento para mostrarlo junto al formulario.')];
        }
        if ((array) ($page->consent_channels ?? []) === []) {
            $out[] = ['code' => 'no_consent', 'message' => __('La página no tiene casillas de consentimiento: quien deje sus datos solo podrá recibir la respuesta a su solicitud, no promociones.')];
        }
        if (! app(Entitlements::class)->allows($workspace, 'services')) {
            $out[] = ['code' => 'no_leads', 'message' => __('Tu plan no incluye los leads: los envíos crean el contacto, pero no el lead ni el aviso al vendedor.')];
        }
        $wa = (array) (($page->content ?? [])['whatsapp'] ?? []);
        if ((bool) ($wa['enabled'] ?? false)) {
            $account = WhatsAppNumber::account(null);
            foreach (self::waWarnings($workspace, $account, WhatsAppNumber::digits($workspace, $account)) as $warning) {
                $out[] = $warning;
            }
        }
        if ($page->site_id !== null && ! Site::query()->whereKey($page->site_id)->whereIn('status', [Site::STATUS_CONNECTED, Site::STATUS_DEGRADED])->exists()) {
            $out[] = ['code' => 'site_disconnected', 'message' => __('El sitio elegido para la página ya no está conectado: los contactos se registran sin sitio.')];
        }

        return $out;
    }

    /** @return list<array{code: string, message: string}> */
    private static function waWarnings(Workspace $workspace, mixed $account, ?string $digits): array
    {
        if ($digits === null) {
            return [['code' => 'no_whatsapp_number', 'message' => __('No hay un número de WhatsApp: conecta WhatsApp en Canales o escribe el número del negocio en su perfil.')]];
        }
        if ($account === null) {
            return [['code' => 'whatsapp_not_connected', 'message' => __('El chat abre el WhatsApp del perfil del negocio, pero no está conectado: los mensajes no llegan a la bandeja ni se cuentan como leads del QR.')]];
        }

        return [];
    }
}
