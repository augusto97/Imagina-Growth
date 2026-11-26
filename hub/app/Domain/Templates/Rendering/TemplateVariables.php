<?php

declare(strict_types=1);

namespace App\Domain\Templates\Rendering;

use App\Domain\Bookings\Support\BookingSettings;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Loyalty\Models\LoyaltyTier;
use App\Domain\Referrals\Models\ReferralProgram;
use App\Domain\Referrals\Support\ReferralCodes;
use App\Domain\Reviews\Support\ReviewSettings;
use App\Domain\Sites\Models\Site;
use App\Domain\Templates\Data\RenderContext;
use App\Domain\Templates\Support\BusinessProfile;
use App\Domain\Workspaces\Models\Workspace;
use Illuminate\Support\Facades\DB;

/**
 * Arma los datos que ve una plantilla Liquid (RenderContext::VARIABLES):
 * `contacto` y `tienda` salen del hub; `carrito`, `cupon`, `pedido`, `producto`
 * y `enlaces` llegan del flujo o la campaña; `recomendaciones` es perezoso.
 *
 * El contacto **nunca** trae correo ni teléfono. En HTML todos los textos se
 * escapan (un nombre con `<script>` no rompe el correo); en el asunto, el texto,
 * el SMS y WhatsApp van sin escapar.
 */
final class TemplateVariables
{
    /** @return array<string, mixed> */
    public function build(RenderContext $context, bool $escapeHtml): array
    {
        $vars = $context->vars;
        // Sin contacto (vista previa con datos de ejemplo), se acepta un `contacto` de ejemplo.
        $sampleContact = $context->contact === null && is_array($vars['contacto'] ?? null) ? $vars['contacto'] : [];
        unset($vars['contacto'], $vars['tienda'], $vars['recomendaciones']);
        $data = [
            'contacto' => array_replace(self::contact($context->contact), array_intersect_key($sampleContact, self::contact(null))),
            'tienda' => self::store($context->workspace),
        ] + $vars + ['enlaces' => []];
        // Enlace de referido del contacto (M14), solo con el programa activo: el código se crea
        // la primera vez que se pide, así que no se generan códigos para mensajes que no lo usan.
        if ($context->contact !== null && ! isset($data['enlaces']['referido'])) {
            $link = self::referralLink($context->workspace, $context->contact);
            if ($link !== null) {
                $data['enlaces'] = (array) $data['enlaces'] + ['referido' => $link];
            }
        }
        $data['enlaces'] = (array) $data['enlaces'] + self::serviceLinks($context->workspace);
        if ($escapeHtml) {
            $data = self::escape($data);
        }
        $data['recomendaciones'] = new RecommendationsDrop($context->workspace->id, $context->contact?->id, $escapeHtml);

        return $data;
    }

    /**
     * Enlaces de servicios: `agendar` (la página de reservas, si el negocio tiene servicios activos en la agenda) y
     * `resena_google` (el enlace para dejar una reseña en Google de Reseñas → Ajustes).
     *
     * @return array<string, string>
     */
    public static function serviceLinks(Workspace $workspace): array
    {
        $links = [];
        $google = ReviewSettings::for($workspace)['google_review_url'];
        if (is_string($google) && $google !== '') {
            $links['resena_google'] = $google;
        }
        if ((bool) (BookingSettings::for($workspace)['enabled'] ?? false)
            && DB::table('booking_services')->where('workspace_id', $workspace->id)->where('active', true)->exists()) {
            $links['agendar'] = BookingSettings::pageUrl($workspace);
        }

        return $links;
    }

    /** @return array<string, mixed> moneda, configuración regional y zona horaria para los filtros */
    public function register(Workspace $workspace): array
    {
        return [
            'currency' => $workspace->currency ?: 'COP',
            'locale' => str_replace('-', '_', (string) ($workspace->locale ?: 'es_CO')),
            'timezone' => $workspace->timezone ?: 'America/Bogota',
        ];
    }

    /** @return array<string, mixed> */
    public static function contact(?Contact $contact): array
    {
        if ($contact === null) {
            return ['nombre' => '', 'apellido' => '', 'nombre_completo' => '', 'ciudad' => '', 'pais' => '', 'puntos' => 0, 'nivel' => '', 'pedidos' => 0];
        }
        $first = trim((string) $contact->first_name);
        $last = trim((string) $contact->last_name);

        return [
            'nombre' => $first,
            'apellido' => $last,
            'nombre_completo' => trim($first.' '.$last),
            'ciudad' => (string) $contact->getAttribute('city'),
            'pais' => (string) $contact->getAttribute('country'),
            'puntos' => (int) $contact->getAttribute('points_balance'),
            'nivel' => self::tierName($contact),
            'pedidos' => (int) $contact->getAttribute('orders_count'),
            'cliente_desde' => $contact->getAttribute('first_order_at')?->toIso8601String() ?? '',
        ];
    }

    /** Nombre del nivel VIP del contacto (M13), vacío si no tiene. */
    private static function tierName(Contact $contact): string
    {
        $tierId = $contact->getAttribute('tier_id');

        return is_string($tierId) && $tierId !== '' ? (string) LoyaltyTier::query()->whereKey($tierId)->value('name') : '';
    }

    private static function referralLink(Workspace $workspace, Contact $contact): ?string
    {
        $program = ReferralProgram::query()->where('workspace_id', $workspace->id)->first();
        if ($program === null || ! $program->isActive()) {
            return null;
        }
        $site = Site::query()->where('workspace_id', $workspace->id)->whereIn('status', [Site::STATUS_CONNECTED, Site::STATUS_DEGRADED])->orderBy('created_at')->first();
        if ($site === null) {
            return null;
        }

        return ReferralCodes::link($site, app(ReferralCodes::class)->forContact($contact));
    }

    /** @return array<string, mixed> */
    public static function store(Workspace $workspace): array
    {
        $profile = BusinessProfile::for($workspace);
        $site = Site::query()->where('workspace_id', $workspace->id)->whereIn('status', [Site::STATUS_CONNECTED, Site::STATUS_DEGRADED])->orderBy('created_at')->first(['url']);
        $whatsapp = preg_replace('/\D+/', '', (string) ($profile['whatsapp'] ?? '')) ?? '';

        return [
            'nombre' => $profile['name'] ?: $workspace->name,
            'url' => rtrim((string) ($site->url ?? ''), '/'),
            'moneda' => $workspace->currency ?: 'COP',
            'direccion' => (string) ($profile['address'] ?? ''),
            'contacto' => (string) ($profile['contact'] ?? ''),
            'whatsapp' => $whatsapp,
            'whatsapp_url' => $whatsapp !== '' ? 'https://wa.me/'.$whatsapp : '',
            'logo' => (string) ($profile['logo_url'] ?? ''),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function escape(array $data): array
    {
        array_walk_recursive($data, function (mixed &$value): void {
            if (is_string($value)) {
                $value = htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false);
            }
        });

        return $data;
    }
}
