<?php

declare(strict_types=1);

namespace App\Domain\Api\Http\V1;

use App\Domain\Api\Support\ApiError;
use App\Domain\Commerce\Models\Order;
use App\Support\Ids\PrefixedId;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Pedidos de WooCommerce ya proyectados en el hub (solo lectura).
 */
#[Group('Pedidos', weight: 3)]
final class OrdersController
{
    use Paginates;

    /**
     * Listar pedidos
     *
     * Filtros opcionales: `contact_id`, `status`, `paid` (true/false), `created_since` y
     * `updated_since` (ISO 8601). Requiere `orders:read`.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'contact_id' => ['sometimes', 'string', 'max:40'],
            'status' => ['sometimes', 'string', 'max:32'],
            'paid' => ['sometimes', 'in:true,false,1,0'],
            'created_since' => ['sometimes', 'date'],
            'updated_since' => ['sometimes', 'date'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $query = Order::query();
        if (is_string($contact = $request->query('contact_id'))) {
            $query->where('contact_id', PrefixedId::decode('ct', $contact) ?? '-');
        }
        if (is_string($status = $request->query('status'))) {
            $query->where('status', $status);
        }
        if ($request->has('paid')) {
            $query->where('is_paid', in_array($request->query('paid'), ['true', '1'], true));
        }
        if (($created = $this->since($request, 'created_since')) !== null) {
            $query->where('created_at', '>=', $created);
        }
        if (($updated = $this->since($request, 'updated_since')) !== null) {
            $query->where('updated_at', '>=', $updated);
        }

        return $this->paginate($query, $request, fn (Order $o) => Presenters::order($o));
    }

    /**
     * Ver un pedido
     *
     * Con sus líneas. Requiere `orders:read`.
     */
    public function show(string $order): JsonResponse
    {
        $model = Order::findByPublicId($order);
        if ($model === null) {
            return ApiError::make(404, 'not_found', __('No existe ese pedido.'));
        }

        return new JsonResponse(['data' => Presenters::order($model->load('items'), true)]);
    }
}
