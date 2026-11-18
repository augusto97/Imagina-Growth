<?php

declare(strict_types=1);

namespace App\Domain\Recommendations\Watches;

use App\Domain\Consent\Policy\PolicyDecision;
use App\Domain\Messaging\Contracts\MessageDispatcher;
use App\Domain\Messaging\Data\DispatchResult;
use App\Domain\Messaging\Data\OutboundMessage;
use App\Domain\Messaging\Enums\Channel;
use App\Domain\Messaging\Enums\MessageKind;
use App\Domain\Recommendations\Models\CommerceSettings;
use App\Domain\Recommendations\Models\ProductWatch;
use App\Domain\Sites\Models\Site;
use App\Domain\Templates\Library\TemplateLibrary;
use App\Domain\Templates\Models\Template;
use App\Domain\Workspaces\Models\Workspace;
use App\Ingest\HubEvents;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Avisa a quienes pidieron «avísame» cuando el producto volvió a tener stock o
 * su precio quedó por debajo del que tenía al suscribirse (modulos.md M16).
 *
 * - El aviso sale por MessageDispatcher como marketing: respeta consentimiento,
 *   ventana legal, festivos y frecuencia. Si la política lo aplaza, la
 *   suscripción sigue activa con `next_attempt_at` y el barrido la retoma.
 * - Una suscripción se avisa una sola vez (`notified`). Además, a una misma
 *   persona no se le avisa del mismo producto más de una vez cada 7 días
 *   (`frequency_cap_days`), aunque tenga las dos suscripciones o se vuelva a suscribir.
 * - Plantilla: la elegida en el panel por tipo y canal; si no hay, la de la
 *   biblioteca para correo y SMS. WhatsApp necesita una plantilla aprobada elegida.
 * - Si el canal de la suscripción no sirve (sin consentimiento, sin cuenta), prueba
 *   los otros canales de la persona.
 */
final class NotifyProductWatches
{
    /** Negaciones de la política que no cambian solas: ese canal no sirve para esta persona. */
    private const PERMANENT = [PolicyDecision::NO_CONSENT, PolicyDecision::NO_ADDRESS, PolicyDecision::SUPPRESSED, PolicyDecision::UNSUBSCRIBED, PolicyDecision::EXCLUDED_REGISTRY];

    public function __construct(private readonly MessageDispatcher $dispatcher, private readonly HubEvents $events) {}

    /**
     * Revisa las suscripciones activas y vencidas de un producto (o de todo el sitio).
     *
     * @return array{notified: int, deferred: int, waiting: int, cancelled: int}
     */
    public function forProduct(Workspace $workspace, Site $site, ?string $productId = null, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $stats = ['notified' => 0, 'deferred' => 0, 'waiting' => 0, 'cancelled' => 0];
        $settings = CommerceSettings::current()->watchSettings();
        $expireDays = (int) config('igr-commerce.watches.expire_days', 120);

        $watches = ProductWatch::query()->where('site_id', $site->id)->where('status', ProductWatch::ACTIVE)
            ->when($productId !== null, fn ($q) => $q->where('external_product_id', $productId))
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', $now))
            ->orderBy('created_at')->limit(2000)->get();
        if ($watches->isEmpty()) {
            return $stats;
        }
        $products = DB::table('products')->where('workspace_id', $workspace->id)->where('site_id', $site->id)
            ->whereIn('external_id', $watches->pluck('external_product_id')->unique()->values()->all())
            ->get(['external_id', 'name', 'price', 'regular_price', 'sale_price', 'permalink', 'image_url', 'stock_status', 'status', 'deleted_at'])
            ->keyBy('external_id');

        foreach ($watches as $watch) {
            /** @var ProductWatch $watch */
            $product = $products[$watch->external_product_id] ?? null;
            if ($product === null || $product->deleted_at !== null || $product->status !== 'publish' || ($watch->created_at !== null && $watch->created_at->lt($now->subDays($expireDays)))) {
                $watch->forceFill(['status' => ProductWatch::CANCELLED, 'last_reason' => $product === null || $product->deleted_at !== null ? 'product_gone' : 'expired'])->save();
                $stats['cancelled']++;

                continue;
            }
            if (! $this->triggered($watch, $product)) {
                continue;
            }
            if (! $settings['enabled']) {
                $this->retryLater($watch, 'disabled', $now);
                $stats['waiting']++;

                continue;
            }
            $stats[$this->notify($workspace, $site, $watch, $product, $settings, $now)]++;
        }

        return $stats;
    }

    /** ¿El producto ya cumple lo que pidió la persona? */
    public static function triggered(ProductWatch $watch, \stdClass $product): bool
    {
        return match ($watch->kind) {
            'back_in_stock' => in_array($product->stock_status, ['instock', 'onbackorder'], true),
            'price_drop' => $watch->threshold !== null && (float) $product->price > 0 && (float) $product->price < (float) $watch->threshold,
            default => false,
        };
    }

    /**
     * @param  array{enabled: bool, channels: list<string>, templates: array<string, array<string, string|null>>}  $settings
     * @return 'notified'|'deferred'|'waiting'|'cancelled'
     */
    private function notify(Workspace $workspace, Site $site, ProductWatch $watch, \stdClass $product, array $settings, CarbonImmutable $now): string
    {
        // Tope por persona y producto.
        $capDays = (int) config('igr-commerce.watches.frequency_cap_days', 7);
        $last = ProductWatch::query()->where('contact_id', $watch->contact_id)->where('site_id', $site->id)
            ->where('external_product_id', $watch->external_product_id)->where('status', ProductWatch::NOTIFIED)
            ->where('notified_at', '>', $now->subDays($capDays))->max('notified_at');
        if ($last !== null) {
            $this->retryLater($watch, 'frequency_cap', $now, CarbonImmutable::parse((string) $last)->addDays($capDays));

            return 'waiting';
        }

        $channels = array_values(array_unique(array_merge([$watch->channel], $settings['channels'])));
        $channels = array_values(array_filter($channels, fn (string $c) => in_array($c, $settings['channels'], true)));
        $permanent = 0;
        $attempted = 0;
        $reason = 'no_channel';
        foreach ($channels as $channel) {
            $templateId = $this->template($workspace, $watch->kind, $channel, $settings);
            if ($templateId === null) {
                $reason = 'no_template';

                continue;
            }
            $attempted++;
            $result = $this->dispatcher->dispatch(new OutboundMessage(
                workspaceId: $workspace->id,
                contactId: $watch->contact_id,
                channel: Channel::from($channel),
                kind: MessageKind::Marketing,
                sourceType: 'system',
                sourceId: $watch->publicId(),
                templateId: $templateId,
                context: ['producto' => self::productVars($watch, $product)],
                idempotencyKey: 'watch:'.$watch->id,
            ));
            if ($result->sent()) {
                $price = number_format((float) $product->price, 2, '.', '');
                $watch->forceFill([
                    'status' => ProductWatch::NOTIFIED, 'notified_at' => $now, 'notified_price' => $price,
                    'message_id' => $result->messageId, 'channel' => $channel, 'attempts' => $watch->attempts + 1, 'last_reason' => null, 'next_attempt_at' => null,
                ])->save();
                $this->events->emit($workspace, 'product_watch.notified', array_filter([
                    'watch_id' => $watch->publicId(),
                    'product_id' => $watch->external_product_id,
                    'kind' => $watch->kind,
                    'price' => $price,
                    'threshold' => $watch->threshold !== null ? number_format((float) $watch->threshold, 2, '.', '') : null,
                ], fn ($v) => $v !== null), $watch->contact_id, $site, $now);

                return 'notified';
            }
            if ($result->status === DispatchResult::DEFERRED) {
                $watch->forceFill(['next_attempt_at' => $result->until, 'last_reason' => mb_substr((string) $result->reason, 0, 64), 'attempts' => $watch->attempts + 1])->save();

                return 'deferred';
            }
            $reason = (string) $result->reason;
            if (in_array($reason, self::PERMANENT, true)) {
                $permanent++;
            }
        }

        // Todos los canales con plantilla dijeron que no para siempre (sin consentimiento, baja, supresión).
        if ($attempted > 0 && $permanent === $attempted) {
            $watch->forceFill(['status' => ProductWatch::CANCELLED, 'last_reason' => mb_substr($reason, 0, 64)])->save();

            return 'cancelled';
        }
        if ($watch->attempts + 1 >= (int) config('igr-commerce.watches.max_attempts', 20)) {
            $watch->forceFill(['status' => ProductWatch::CANCELLED, 'last_reason' => mb_substr($reason, 0, 64), 'attempts' => $watch->attempts + 1])->save();

            return 'cancelled';
        }
        $this->retryLater($watch, $reason, $now);

        return 'waiting';
    }

    /**
     * Variables `producto` de la plantilla (RenderContext::VARIABLES).
     *
     * @return array<string, string>
     */
    public static function productVars(ProductWatch $watch, \stdClass $product): array
    {
        return array_filter([
            'nombre' => (string) $product->name,
            'precio' => number_format((float) $product->price, 2, '.', ''),
            'precio_anterior' => $watch->kind === 'price_drop' && $watch->threshold !== null ? number_format((float) $watch->threshold, 2, '.', '') : '',
            'url' => (string) ($product->permalink ?? ''),
            'imagen' => (string) ($product->image_url ?? ''),
        ], fn ($v) => $v !== '');
    }

    /** @param array{enabled: bool, channels: list<string>, templates: array<string, array<string, string|null>>} $settings */
    private function template(Workspace $workspace, string $kind, string $channel, array $settings): ?string
    {
        $chosen = $settings['templates'][$kind][$channel] ?? null;
        if ($chosen !== null) {
            $template = Template::findByPublicId($chosen) ?? Template::query()->find($chosen);
            if ($template !== null && $template->channel === $channel && $template->status !== 'archived') {
                return $template->publicId();
            }
        }
        $key = config("igr-commerce.watches.library_templates.{$kind}.{$channel}");
        if (! is_string($key) || TemplateLibrary::entry($key) === null) {
            return null;
        }

        return TemplateLibrary::install($workspace, $key)->publicId();
    }

    private function retryLater(ProductWatch $watch, string $reason, CarbonImmutable $now, ?CarbonImmutable $at = null): void
    {
        $watch->forceFill([
            'next_attempt_at' => $at ?? $now->addHours((int) config('igr-commerce.watches.retry_hours', 6)),
            'last_reason' => mb_substr($reason, 0, 64),
            'attempts' => $watch->attempts + ($at === null ? 1 : 0),
        ])->save();
    }
}
