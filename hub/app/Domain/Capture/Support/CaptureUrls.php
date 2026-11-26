<?php

declare(strict_types=1);

namespace App\Domain\Capture\Support;

use App\Domain\Agency\Domains\CustomDomains;
use App\Domain\Agency\Models\CustomDomain;
use App\Domain\Bookings\Support\BookingSettings;
use App\Domain\Capture\Models\LandingPage;
use App\Domain\Capture\Models\WhatsAppLink;
use App\Domain\Workspaces\Models\Workspace;
use Illuminate\Http\Request;

/**
 * Las direcciones públicas de la captación: la página (`/p/{slug}`), su versión para el QR impreso (UTM `qr`), el
 * enlace corto de un chat de WhatsApp (`/p/w/{código}`) y su QR. Viven en el dominio propio verificado de las páginas
 * públicas del espacio de trabajo (el de la agenda, M22, que Caddy ya lleva a `/p/*`) o en el del hub.
 */
final class CaptureUrls
{
    /** UTM del QR impreso: así se cuentan las visitas y los leads que vinieron del papel. */
    public const QR_UTM = ['utm_source' => 'qr', 'utm_medium' => 'qr'];

    public static function page(Workspace $workspace, LandingPage $page): string
    {
        return BookingSettings::origin($workspace).'/p/'.$page->slug;
    }

    public static function pageQr(Workspace $workspace, LandingPage $page): string
    {
        return self::page($workspace, $page).'?'.http_build_query(self::QR_UTM + ['utm_campaign' => $page->slug]);
    }

    public static function waLink(Workspace $workspace, WhatsAppLink $link): string
    {
        return BookingSettings::origin($workspace).'/p/w/'.$link->code;
    }

    /** El enlace del QR de un chat: el corto, marcado como escaneo (`via=qr`). */
    public static function waLinkQr(Workspace $workspace, WhatsAppLink $link): string
    {
        return self::waLink($workspace, $link).($link->kind === 'qr' ? '' : '?via=qr');
    }

    /**
     * wa.me con el número (solo dígitos) y el mensaje de alta con el código al final, en el formato del widget de
     * WhatsApp y del tracker (eventos.md §6.3): «{mensaje} (ref: CÓDIGO)».
     */
    public static function waMe(string $digits, string $message, string $code): string
    {
        return 'https://wa.me/'.$digits.'?text='.rawurlencode(trim($message).' (ref: '.$code.')');
    }

    /**
     * ¿La página se puede servir en el host de la petición? En un dominio propio verificado, solo las páginas de su
     * cuenta (y de su espacio de trabajo, si el dominio es de uno): el dominio de una agencia no sirve las de otra.
     */
    public static function hostAllows(Request $request, Workspace $workspace): bool
    {
        $host = CustomDomains::normalize($request->getHost());
        if ($host === null || ! CustomDomains::servable($host)) {
            return true;
        }

        return CustomDomain::query()->where('host', $host)->where('status', 'verified')
            ->where('account_id', (string) $workspace->account_id)
            ->where(fn ($q) => $q->whereNull('workspace_id')->orWhere('workspace_id', $workspace->id))
            ->exists();
    }

    /** Origen de los enlaces de la página servida: el dominio por el que entró la persona, si es propio y verificado. */
    public static function origin(Request $request, Workspace $workspace): string
    {
        $host = $request->getHost();

        return $host !== '' && CustomDomains::servable($host) ? 'https://'.$host : BookingSettings::origin($workspace);
    }
}
