<?php

declare(strict_types=1);

namespace App\Domain\Flows\Support;

use App\Domain\Bookings\Actions\AppointmentNotifier;
use App\Domain\Bookings\Http\Public\PublicBooking;
use App\Domain\Bookings\Models\Appointment;
use App\Domain\Bookings\Models\BookingService;
use App\Domain\Commerce\Models\Cart;
use App\Domain\Commerce\Support\CartRestoreLink;
use App\Domain\Flows\Engine\RunState;
use App\Domain\Services\Leads\Models\Lead;
use App\Domain\Services\Leads\Support\Pipelines;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Variables de plantilla (RenderContext::VARIABLES) que salen del run: el
 * `carrito` del disparador (con el enlace para restaurarlo y el cupón si ya se
 * generó), el `pedido`, el `producto` visto y el `cupon`. Para servicios: la
 * `cita` del disparador con su hora actual (servicio, fecha, hora, lugar y el
 * enlace para reprogramar), el `lead` (servicio, valor y etapa), la `cotizacion`
 * (servicio y valor) y el `evento` de un formulario de inscripción (los campos
 * `event_name`, `event_date`, `event_url` y `recording_url`). `contacto`,
 * `tienda` y `enlaces` los pone el renderizador o el despachador.
 */
final class TemplateContext
{
    /** @return array<string, mixed> */
    public static function build(RunState $state): array
    {
        $context = (array) ($state->run->context ?? []);
        $props = (array) ($context['props'] ?? []);
        $vars = [];
        $coupon = is_array($context['cupon'] ?? null) ? $context['cupon'] : null;
        if ($coupon !== null) {
            $vars['cupon'] = $coupon;
        }

        if (isset($props['cart_id']) || isset($props['cart_token'])) {
            $vars['carrito'] = self::cart($props, $coupon['codigo'] ?? null);
        }
        $order = is_array($props['order'] ?? null) ? $props['order'] : null;
        if ($order !== null || isset($props['order_id'])) {
            $vars['pedido'] = array_filter([
                'numero' => (string) ($order['number'] ?? $order['order_id'] ?? $props['order_id'] ?? ''),
                'total' => (string) ($order['totals']['total'] ?? $props['total'] ?? ''),
                'moneda' => (string) ($order['currency'] ?? $props['currency'] ?? ''),
                'estado' => (string) ($order['status'] ?? ''),
                'metodo_pago' => (string) ($order['payment_method']['title'] ?? $order['payment_method']['id'] ?? $props['payment_method'] ?? ''),
                'url_pago' => (string) ($props['pay_url'] ?? $order['pay_url'] ?? ''),
            ], fn ($v) => $v !== '');
        }
        if (isset($props['product_id'])) {
            $vars['producto'] = self::product($state->workspace->id, (string) $props['product_id'], $props);
        }
        if (is_string($props['appointment_id'] ?? null)) {
            $cita = self::appointment($state, $props['appointment_id']);
            if ($cita !== null) {
                $vars['cita'] = $cita;
            }
        }
        if (is_string($props['lead_id'] ?? null)) {
            $lead = self::lead($props['lead_id']);
            if ($lead !== null) {
                $vars['lead'] = $lead;
            }
        }
        if (($context['trigger'] ?? null) === 'quote.sent' || ($context['trigger'] ?? null) === 'quote.requested') {
            $vars['cotizacion'] = array_filter([
                'servicio' => (string) ($props['service'] ?? $props['calculator_name'] ?? ($vars['lead']['servicio'] ?? '')),
                'valor' => (string) ($props['value'] ?? $props['estimate_max'] ?? ($vars['lead']['valor'] ?? '')),
                'moneda' => (string) ($props['currency'] ?? ''),
            ], fn ($v) => $v !== '');
        }
        if (is_array($props['fields'] ?? null) && isset($props['fields']['event_date'])) {
            $vars['evento'] = self::event($state, $props);
        }

        return $vars;
    }

    /** @return array<string, string>|null la cita con su hora de ahora (si se reprogramó, la nueva) */
    private static function appointment(RunState $state, string $publicId): ?array
    {
        $appointment = Appointment::findByPublicId($publicId);
        $service = $appointment !== null ? BookingService::query()->find($appointment->service_id) : null;

        return $appointment !== null && $service !== null ? AppointmentNotifier::vars($state->workspace, $appointment, $service) : null;
    }

    /** @return array<string, string>|null */
    private static function lead(string $publicId): ?array
    {
        $lead = Lead::findByPublicId($publicId);
        if ($lead === null) {
            return null;
        }

        return array_filter([
            'servicio' => (string) ($lead->service ?? ''),
            'valor' => (string) ($lead->value_ws ?? ''),
            'etapa' => (string) (Pipelines::for($lead)->stage($lead->stage)['label'] ?? ''),
        ], fn ($v) => $v !== '');
    }

    /**
     * @param  array<string, mixed>  $props
     * @return array<string, string>
     */
    private static function event(RunState $state, array $props): array
    {
        $fields = (array) $props['fields'];
        $tz = (string) ($state->workspace->timezone ?: 'America/Bogota');
        $date = '';
        $time = '';
        try {
            $at = PublicBooking::es(CarbonImmutable::parse((string) $fields['event_date'], $tz)->setTimezone($tz));
            $date = $at->isoFormat('dddd D [de] MMMM');
            $time = $at->format('g:i a');
        } catch (Throwable) {
            // Sin fecha válida, la plantilla usa su respaldo.
        }

        return array_filter([
            'nombre' => mb_substr((string) ($fields['event_name'] ?? $props['form_name'] ?? ''), 0, 160),
            'fecha' => $date,
            'hora' => $time,
            'enlace' => self::url($fields['event_url'] ?? null),
            'grabacion' => self::url($fields['recording_url'] ?? null),
        ], fn ($v) => $v !== '');
    }

    private static function url(mixed $value): string
    {
        return is_string($value) && preg_match('#^https://[^\s<>"]+$#i', trim($value)) === 1 ? trim($value) : '';
    }

    /**
     * @param  array<string, mixed>  $props
     * @return array<string, mixed>
     */
    private static function cart(array $props, ?string $couponCode): array
    {
        $items = [];
        foreach ((array) ($props['items'] ?? []) as $item) {
            if (! is_array($item)) {
                continue;
            }
            $items[] = array_filter([
                'nombre' => (string) ($item['name'] ?? ''),
                'cantidad' => $item['qty'] ?? 1,
                'precio' => (string) ($item['price'] ?? ''),
                'imagen' => (string) ($item['image_url'] ?? ''),
                'url' => (string) ($item['permalink'] ?? ''),
            ], fn ($v) => $v !== '');
        }
        $url = (string) ($props['restore_url'] ?? '');
        $cart = is_string($props['cart_id'] ?? null) ? Cart::findByPublicId($props['cart_id']) : null;
        if ($cart !== null && ($couponCode !== null || $url === '')) {
            $url = CartRestoreLink::for($cart, $couponCode) ?? $url;
        }

        return array_filter([
            'items' => $items,
            'subtotal' => (string) ($props['subtotal'] ?? ''),
            'moneda' => (string) ($props['currency'] ?? ''),
            'cantidad' => $props['items_count'] ?? count($items),
            'url_restaurar' => $url,
        ], fn ($v) => $v !== '' && $v !== []);
    }

    /**
     * @param  array<string, mixed>  $props
     * @return array<string, mixed>
     */
    private static function product(string $workspaceId, string $externalId, array $props): array
    {
        $row = DB::table('products')->where('workspace_id', $workspaceId)->where('external_id', $externalId)
            ->first(['name', 'price', 'permalink', 'image_url']);

        return array_filter([
            'nombre' => (string) ($row->name ?? $props['name'] ?? ''),
            'precio' => (string) ($row->price ?? $props['price'] ?? ''),
            'url' => (string) ($row->permalink ?? ''),
            'imagen' => (string) ($row->image_url ?? ''),
        ], fn ($v) => $v !== '');
    }
}
