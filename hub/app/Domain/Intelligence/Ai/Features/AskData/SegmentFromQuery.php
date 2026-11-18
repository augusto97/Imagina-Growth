<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Ai\Features\AskData;

use App\Domain\Segments\Definition\DefinitionValidator;
use App\Domain\Segments\Definition\InvalidDefinition;

/**
 * «Guardar como segmento» de una respuesta de «Pregúntale a tus datos»: solo
 * cuando la consulta habla de clientes que compraron (métricas de pedidos o
 * clientes) se traduce a un segmento: compró en el rango, y en la ciudad si se
 * filtró por ciudad. Los filtros de tráfico (canal, dispositivo) no tienen
 * equivalente en el perfil, así que esas consultas no se ofrecen como segmento.
 */
final class SegmentFromQuery
{
    private const CUSTOMER_METRICS = ['revenue', 'orders', 'aov', 'new_customers', 'returning_customers', 'net_revenue'];

    public function __construct(private readonly DefinitionValidator $validator) {}

    /**
     * @param  array<string, mixed>|null  $query  la consulta normalizada (`MetricQueryTool::normalize`)
     * @return array<string, mixed>|null
     */
    public function definition(?array $query): ?array
    {
        if ($query === null || array_intersect((array) ($query['metrics'] ?? []), self::CUSTOMER_METRICS) === []
            || ($query['channel'] ?? null) !== null || ($query['device'] ?? null) !== null) {
            return null;
        }
        $conditions = [[
            'type' => 'purchase', 'field' => 'orders_count', 'operator' => 'gte', 'value' => 1,
            'window' => ['kind' => 'between', 'from' => $query['from'], 'to' => $query['to']],
        ]];
        if (is_string($query['city'] ?? null) && $query['city'] !== '') {
            $conditions[] = ['type' => 'attribute', 'field' => 'city', 'operator' => 'eq', 'value' => $query['city']];
        }
        try {
            return $this->validator->validate(['op' => 'and', 'conditions' => $conditions]);
        } catch (InvalidDefinition) {
            return null;
        }
    }
}
