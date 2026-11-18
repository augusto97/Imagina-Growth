<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Predictions\Actions;

use App\Domain\Intelligence\Predictions\Models\PredictionRun;
use App\Domain\Workspaces\Models\Workspace;
use Illuminate\Support\Facades\DB;

/**
 * «Siguiente producto» (inteligencia.md §4.5): matriz de transición del pedido N
 * al N+1 de cada cliente, por producto y por categoría. `support` = clientes con
 * esa transición; `probability` = P(siguiente contiene «to» | el anterior contenía
 * «from»). Complementa las reglas de «comprados juntos» de M16 (mismo pedido),
 * que siguen en `product_associations`.
 */
final class BuildProductTransitions
{
    /** @return array{status: string} */
    public function handle(Workspace $workspace): array
    {
        $min = (int) config('igr-predictions.transitions.min_support', 3);
        $top = (int) config('igr-predictions.transitions.top', 10);
        $rows = DB::table('order_items as i')->join('orders as o', 'o.id', '=', 'i.order_id')
            ->where('o.workspace_id', $workspace->id)->where('o.is_paid', true)->whereNotNull('o.contact_id')
            ->orderBy('o.contact_id')->orderBy('o.created_at')->orderBy('o.id')
            ->select(['o.contact_id', 'o.id as order_id', 'o.site_id', 'i.external_product_id', 'i.category_ids'])->cursor();

        $pairs = ['product' => [], 'category' => []];
        $froms = ['product' => [], 'category' => []];
        $contact = null;
        /** @var array<string, array{0: string, 1: array{product: array<string, true>, category: array<string, true>}}> $orders */
        $orders = [];
        $flush = function () use (&$orders, &$pairs, &$froms): void {
            $seenPair = [];
            $seenFrom = [];
            $list = array_values($orders);
            for ($n = 0; $n + 1 < count($list); $n++) {
                [$site, $a] = $list[$n];
                $b = $list[$n + 1][1];
                foreach (['product', 'category'] as $level) {
                    foreach ($a[$level] as $f => $_) {
                        $seenFrom[$level][$site][$f] = true;
                        foreach ($b[$level] as $t => $__) {
                            $seenPair[$level][$site][$f][$t] = true;
                        }
                    }
                }
            }
            foreach ($seenFrom as $level => $bySite) {
                foreach ($bySite as $site => $fs) {
                    foreach ($fs as $f => $_) {
                        $froms[$level][$site][$f] = ($froms[$level][$site][$f] ?? 0) + 1;
                    }
                }
            }
            foreach ($seenPair as $level => $bySite) {
                foreach ($bySite as $site => $fs) {
                    foreach ($fs as $f => $ts) {
                        foreach ($ts as $t => $_) {
                            $pairs[$level][$site][$f][$t] = ($pairs[$level][$site][$f][$t] ?? 0) + 1;
                        }
                    }
                }
            }
            $orders = [];
        };
        foreach ($rows as $r) {
            if ($contact !== null && $contact !== (string) $r->contact_id) {
                $flush();
            }
            $contact = (string) $r->contact_id;
            $oid = (string) $r->order_id;
            $orders[$oid] ??= [(string) $r->site_id, ['product' => [], 'category' => []]];
            if ($r->external_product_id !== null && $r->external_product_id !== '') {
                $orders[$oid][1]['product'][(string) $r->external_product_id] = true;
            }
            $cats = json_decode((string) $r->category_ids, true);
            foreach (is_array($cats) ? $cats : [] as $c) {
                $orders[$oid][1]['category'][(string) $c] = true;
            }
        }
        $flush();

        $now = now()->toDateTimeString();
        $insert = [];
        foreach ($pairs as $level => $bySite) {
            foreach ($bySite as $site => $fs) {
                foreach ($fs as $f => $ts) {
                    arsort($ts);
                    foreach (array_slice($ts, 0, $top, true) as $t => $support) {
                        if ($support < $min) {
                            continue;
                        }
                        $insert[] = [
                            'workspace_id' => $workspace->id, 'site_id' => (string) $site, 'level' => $level,
                            'from_key' => mb_substr((string) $f, 0, 64), 'to_key' => mb_substr((string) $t, 0, 64),
                            'support' => $support, 'probability' => round($support / max(1, $froms[$level][$site][$f] ?? 1), 5), 'computed_at' => $now,
                        ];
                    }
                }
            }
        }
        DB::transaction(function () use ($workspace, $insert): void {
            DB::table('product_transitions')->where('workspace_id', $workspace->id)->delete();
            foreach (array_chunk($insert, 1000) as $chunk) {
                DB::table('product_transitions')->insert($chunk);
            }
        });
        PredictionRun::record($workspace->id, 'next_product', 'completed', [
            'transitions' => count($insert),
            'product' => count(array_filter($insert, fn ($r) => $r['level'] === 'product')),
            'category' => count(array_filter($insert, fn ($r) => $r['level'] === 'category')),
        ], ['min_support' => $min, 'top' => $top]);

        return ['status' => 'completed'];
    }
}
