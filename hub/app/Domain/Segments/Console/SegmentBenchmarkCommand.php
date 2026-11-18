<?php

declare(strict_types=1);

namespace App\Domain\Segments\Console;

use App\Domain\Segments\Compiler\SegmentTimeout;
use App\Domain\Segments\Definition\DefinitionValidator;
use App\Domain\Segments\Queries\SegmentPreview;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Ids\PrefixedId;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Console\Command;

/** Mide la vista previa de segmentos representativos (objetivo M04: < 3 s). */
final class SegmentBenchmarkCommand extends Command
{
    protected $signature = 'igr:segments:benchmark {--workspace= : ws_…} {--runs=3}';

    protected $description = 'Mide el tiempo de la vista previa de segmentos típicos (objetivo de M04: menos de 3 s)';

    public function handle(CurrentWorkspace $current, DefinitionValidator $validator, SegmentPreview $preview): int
    {
        $workspace = Workspace::query()->find(PrefixedId::decode('ws', (string) $this->option('workspace')));
        if ($workspace === null) {
            $this->error('Indica --workspace=ws_…');

            return self::FAILURE;
        }
        $runs = max(1, (int) $this->option('runs'));
        $rows = [];
        $current->run($workspace, function () use ($validator, $preview, $workspace, $runs, &$rows): void {
            foreach (self::cases() as $name => $definition) {
                $ast = $validator->validate($definition);
                $times = [];
                $count = null;
                for ($i = 0; $i < $runs; $i++) {
                    $t = microtime(true);
                    try {
                        $count = $preview->handle($workspace, $ast, true)['count'];
                    } catch (SegmentTimeout) {
                        $count = 'timeout';
                    }
                    $times[] = (microtime(true) - $t) * 1000;
                }
                sort($times);
                $rows[] = [$name, is_int($count) ? number_format($count, 0, ',', '.') : $count, (int) ($times[intdiv(count($times), 2)] ?? 0), (int) ($times === [] ? 0 : max($times))];
            }
        });
        $this->table(['Segmento', 'Contactos', 'Mediana (ms)', 'Máximo (ms)'], $rows);

        return self::SUCCESS;
    }

    /** @return array<string, array<string, mixed>> */
    public static function cases(): array
    {
        $and = fn (array $c) => ['op' => 'and', 'conditions' => $c];

        return [
            'Ciudad + 2 pedidos' => $and([
                ['type' => 'attribute', 'field' => 'city', 'operator' => 'in', 'value' => ['Bogotá', 'Medellín']],
                ['type' => 'purchase', 'field' => 'orders_count', 'operator' => 'gte', 'value' => 2],
            ]),
            'Categoría 2+ veces en 365 días' => $and([
                ['type' => 'purchase', 'scope' => 'category', 'ids' => ['12'], 'operator' => 'count_gte', 'value' => 2, 'window' => ['kind' => 'last_days', 'days' => 365]],
            ]),
            'Vio el producto 7 dos veces en 30 días' => $and([
                ['type' => 'event', 'event' => 'product.viewed', 'filters' => [['prop' => 'product_id', 'operator' => 'eq', 'value' => '7']], 'operator' => 'count_gte', 'value' => 2, 'window' => ['kind' => 'last_days', 'days' => 30]],
            ]),
            'Compuesto (ciudad, sesiones o RFM, WhatsApp)' => $and([
                ['type' => 'attribute', 'field' => 'city', 'operator' => 'in', 'value' => ['Bogotá', 'Medellín', 'Cali']],
                ['op' => 'or', 'conditions' => [
                    ['type' => 'event', 'event' => 'page.viewed', 'measure' => 'sessions', 'operator' => 'count_gte', 'value' => 3, 'window' => ['kind' => 'last_days', 'days' => 30]],
                    ['type' => 'prediction', 'field' => 'rfm_segment', 'operator' => 'in', 'value' => ['champions', 'loyal']],
                ]],
                ['type' => 'consent', 'channel' => 'whatsapp', 'purpose' => 'marketing', 'status' => 'granted'],
            ]),
            'Nunca empezó el pago en 90 días (con compras)' => $and([
                ['type' => 'event', 'event' => 'checkout.started', 'operator' => 'never', 'window' => ['kind' => 'last_days', 'days' => 90]],
                ['type' => 'purchase', 'field' => 'orders_count', 'operator' => 'gte', 'value' => 1],
            ]),
            'Carrito abandonado 7 días' => $and([['type' => 'cart', 'operator' => 'abandoned', 'window' => ['kind' => 'last_days', 'days' => 7]]]),
            'RFM: Campeones' => $and([['type' => 'prediction', 'field' => 'rfm_segment', 'operator' => 'eq', 'value' => 'champions']]),
            'Hizo clic en un mensaje de marketing en 30 días' => $and([
                ['type' => 'messaging', 'action' => 'clicked', 'channel' => 'any', 'kind' => 'marketing', 'operator' => 'ever', 'window' => ['kind' => 'last_days', 'days' => 30]],
            ]),
            'Sin mensajes en 14 días e interacción alta' => $and([
                ['type' => 'messaging', 'action' => 'received', 'channel' => 'any', 'kind' => 'marketing', 'operator' => 'never', 'window' => ['kind' => 'last_days', 'days' => 14]],
                ['type' => 'messaging', 'action' => 'engagement', 'operator' => 'gte', 'value' => 5],
            ]),
            'Con 500 puntos o más' => $and([['type' => 'loyalty', 'field' => 'points_balance', 'operator' => 'gte', 'value' => 500]]),
        ];
    }
}
