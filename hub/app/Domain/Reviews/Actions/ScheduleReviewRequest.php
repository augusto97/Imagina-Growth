<?php

declare(strict_types=1);

namespace App\Domain\Reviews\Actions;

use App\Domain\Commerce\Models\Order;
use App\Domain\Reviews\Models\Review;
use App\Domain\Reviews\Models\ReviewRequest;
use App\Domain\Reviews\Support\ReviewSettings;
use App\Domain\Workspaces\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Programa la solicitud de reseña de un pedido (M15): cuando llega el evento
 * elegido en los ajustes (`order.completed`, la entrega, o `order.paid`), crea
 * la solicitud para dentro de N días (los de la categoría del pedido que más
 * espera, o los de por defecto). Una sola por pedido: un segundo evento del
 * mismo pedido no cambia nada. Los productos que el contacto ya reseñó no se
 * vuelven a pedir. Corre dentro del contexto del espacio de trabajo.
 */
final class ScheduleReviewRequest
{
    /** Máximo de productos por solicitud. */
    private const MAX_PRODUCTS = 10;

    public function handle(Workspace $workspace, Order $order, string $trigger, ?CarbonImmutable $now = null): ?ReviewRequest
    {
        $settings = ReviewSettings::for($workspace)['request'];
        if (! $settings['enabled'] || $settings['trigger'] !== $trigger || $order->contact_id === null) {
            return null;
        }
        if (in_array($order->status, ['cancelled', 'refunded', 'failed'], true)) {
            return null;
        }
        $existing = ReviewRequest::query()->where('order_id', $order->id)->first();
        if ($existing !== null) {
            return $existing;
        }

        $items = DB::table('order_items')->where('workspace_id', $workspace->id)->where('order_id', $order->id)
            ->orderBy('line_total', 'desc')->get(['external_product_id', 'category_ids']);
        $products = [];
        $categories = [];
        foreach ($items as $item) {
            $products[(string) $item->external_product_id] = true;
            foreach ((array) json_decode((string) $item->category_ids, true) as $category) {
                $categories[] = (string) $category;
            }
        }
        $products = array_slice(array_map('strval', array_keys($products)), 0, self::MAX_PRODUCTS);
        if ($settings['skip_reviewed'] && $products !== []) {
            $reviewed = Review::query()->where('contact_id', $order->contact_id)->whereIn('external_product_id', $products)
                ->where('status', '!=', 'rejected')->pluck('external_product_id')->all();
            $products = array_values(array_diff($products, $reviewed));
            if ($products === []) {
                return null; // ya reseñó todo lo de este pedido
            }
        }

        $now ??= CarbonImmutable::now();
        $at = $now->addDays(ReviewSettings::daysFor(array_values(array_unique($categories)), $settings));
        try {
            return ReviewRequest::query()->create([
                'workspace_id' => $workspace->id,
                'site_id' => $order->site_id,
                'order_id' => $order->id,
                'contact_id' => $order->contact_id,
                'channel' => 'pending',
                'kind' => $settings['kind'],
                'status' => 'scheduled',
                'products' => $products,
                'scheduled_for' => $at,
                'expires_at' => $at->addDays($settings['link_days']),
            ]);
        } catch (UniqueConstraintViolationException) {
            return ReviewRequest::query()->where('order_id', $order->id)->first();
        }
    }
}
