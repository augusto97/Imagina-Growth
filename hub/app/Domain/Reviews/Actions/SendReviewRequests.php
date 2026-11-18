<?php

declare(strict_types=1);

namespace App\Domain\Reviews\Actions;

use App\Domain\Commerce\Models\Order;
use App\Domain\Messaging\Enums\MessageKind;
use App\Domain\Reviews\Models\Review;
use App\Domain\Reviews\Models\ReviewRequest;
use App\Domain\Reviews\Support\ChannelCascade;
use App\Domain\Reviews\Support\ReviewSettings;
use App\Domain\Reviews\Support\ReviewToken;
use App\Domain\Sites\Models\Site;
use App\Domain\Workspaces\Models\Workspace;
use App\Ingest\HubEvents;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Envía las solicitudes de reseña que ya tocan (scheduler cada 5 minutos). Todo
 * pasa por MessageDispatcher (ChannelCascade) con la clave `review_request:{id}:{canal}`:
 *
 * - Prueba los canales en el orden de los ajustes (WhatsApp → correo): si la
 *   política niega uno (sin consentimiento, sin número, sin plantilla), sigue con
 *   el siguiente.
 * - Si la política aplaza (ventana legal, festivo, frecuencia), la solicitud
 *   queda programada para `until` y se reintenta con la misma clave.
 * - Al salir emite `review.requested`. Si ningún canal sirve, queda `skipped`
 *   con el motivo.
 */
/**
 * @phpstan-import-type Settings from ReviewSettings
 */
final class SendReviewRequests
{
    public function __construct(
        private readonly CurrentWorkspace $current,
        private readonly ChannelCascade $cascade,
        private readonly HubEvents $hubEvents,
    ) {}

    /** @return array{sent: int, deferred: int, skipped: int, expired: int} */
    public function handle(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $totals = ['sent' => 0, 'deferred' => 0, 'skipped' => 0, 'expired' => 0];
        $workspaceIds = ReviewRequest::withoutWorkspaceScope()->where('status', 'scheduled')->where('scheduled_for', '<=', $now)
            ->distinct()->pluck('workspace_id');
        foreach (Workspace::query()->whereIn('id', $workspaceIds)->get() as $workspace) {
            try {
                $result = $this->current->run($workspace, fn (): array => $this->forWorkspace($workspace, $now));
                foreach ($result as $k => $v) {
                    $totals[$k] += $v;
                }
            } catch (Throwable $e) {
                report($e);
                Log::error('igr.reviews.requests_failed', ['workspace' => $workspace->id, 'error' => $e->getMessage()]);
            }
        }

        return $totals;
    }

    /** @return array{sent: int, deferred: int, skipped: int, expired: int} */
    public function forWorkspace(Workspace $workspace, CarbonImmutable $now): array
    {
        $out = ['sent' => 0, 'deferred' => 0, 'skipped' => 0, 'expired' => 0];
        $settings = ReviewSettings::for($workspace);
        $requests = ReviewRequest::query()->where('status', 'scheduled')->where('scheduled_for', '<=', $now)
            ->orderBy('scheduled_for')->limit((int) config('igr-reviews.send_batch', 500))->get();
        foreach ($requests as $request) {
            $outcome = $this->send($workspace, $request, $settings, $now);
            $out[$outcome]++;
        }

        return $out;
    }

    /**
     * @param  Settings  $settings
     * @return 'sent'|'deferred'|'skipped'|'expired'
     */
    public function send(Workspace $workspace, ReviewRequest $request, array $settings, CarbonImmutable $now): string
    {
        if (! $settings['request']['enabled']) {
            return $this->skip($request, 'disabled');
        }
        $expireDays = (int) config('igr-reviews.request_expire_days', 30);
        if ($request->scheduled_for !== null && $request->scheduled_for->lessThan($now->subDays($expireDays))) {
            $request->forceFill(['status' => 'expired', 'skip_reason' => 'not_sent_in_time'])->save();

            return 'expired';
        }
        $order = Order::query()->find($request->order_id);
        if ($order === null || in_array($order->status, ['cancelled', 'refunded', 'failed'], true)) {
            return $this->skip($request, 'order_cancelled');
        }
        $products = array_values(array_map('strval', $request->products ?? []));
        if ($settings['request']['skip_reviewed'] && $products !== []) {
            $reviewed = Review::query()->where('contact_id', $request->contact_id)->whereIn('external_product_id', $products)
                ->where('status', '!=', 'rejected')->pluck('external_product_id')->all();
            if (array_diff($products, $reviewed) === []) {
                return $this->skip($request, 'already_reviewed');
            }
        }

        $kind = MessageKind::tryFrom($request->kind) ?? MessageKind::Marketing;
        $request->increment('attempts');
        $r = $settings['request'];
        $result = $this->cascade->send(
            $workspace, $request->contact_id, $r['channels'],
            ['email' => $r['email_template_id'], 'whatsapp' => $r['whatsapp_template_id'], 'sms' => $r['sms_template_id']],
            'review_request', $kind, $request->id, $this->context($workspace, $request, $order, $products),
            'review_request:'.$request->id, $now,
        );
        if ($result['status'] === 'deferred') {
            $request->forceFill(['scheduled_for' => $result['until']])->save();

            return 'deferred';
        }
        if ($result['status'] === 'skipped') {
            return $this->skip($request, $result['reason']);
        }
        $request->forceFill(['status' => 'sent', 'channel' => $result['channel'], 'message_id' => $result['message_id'], 'sent_at' => $now, 'skip_reason' => null])->save();
        $this->hubEvents->emit($workspace, 'review.requested', [
            'request_id' => $request->publicId(),
            'order_id' => $order->external_id,
            'channel' => $result['channel'],
            'products' => $products,
        ], $request->contact_id, Site::query()->find($order->site_id));

        return 'sent';
    }

    /**
     * Variables de la plantilla `review_request` (M08): `enlaces.resena` termina en
     * `?nota=` (las estrellas del correo agregan el número), el pedido y el primer
     * producto.
     *
     * @param  list<string>  $products
     * @return array<string, mixed>
     */
    private function context(Workspace $workspace, ReviewRequest $request, Order $order, array $products): array
    {
        $context = [
            'enlaces' => ['resena' => ReviewToken::reviewUrl($request).'?nota='],
            'pedido' => array_filter([
                'numero' => (string) ($order->getAttribute('number') ?? $order->external_id),
                'total' => (string) $order->total,
                'moneda' => (string) $order->getAttribute('currency'),
            ], fn ($v) => $v !== ''),
        ];
        if ($products !== []) {
            $product = DB::table('products')->where('workspace_id', $workspace->id)->where('site_id', $order->site_id)
                ->where('external_id', $products[0])->first(['name', 'image_url', 'permalink']);
            if ($product !== null) {
                $context['producto'] = array_filter(['nombre' => (string) $product->name, 'imagen' => (string) $product->image_url, 'url' => (string) $product->permalink], fn ($v) => $v !== '');
            }
        }

        return $context;
    }

    /** @return 'skipped' */
    private function skip(ReviewRequest $request, string $reason): string
    {
        $request->forceFill(['status' => 'skipped', 'skip_reason' => mb_substr($reason, 0, 40)])->save();

        return 'skipped';
    }
}
