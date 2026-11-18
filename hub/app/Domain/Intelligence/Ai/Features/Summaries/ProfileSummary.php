<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Ai\Features\Summaries;

use App\Domain\Contacts\Models\Contact;
use App\Domain\Intelligence\Ai\Data\AiRequest;
use App\Domain\Intelligence\Ai\Support\NarrativeWriter;
use App\Domain\Intelligence\Insights\Money;
use App\Domain\Loyalty\Models\LoyaltyTier;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Tenancy\RedisKey;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;

/**
 * Resumen de perfil con IA (M17, Fase 4): 2 o 3 frases sobre quién es el cliente y
 * qué conviene hacer. Solo recibe agregados del contacto y su primer nombre, nunca
 * correo, teléfono, dirección ni textos libres. Se guarda en caché mientras los
 * agregados no cambien (un pedido o un evento nuevo los cambian).
 */
final class ProfileSummary
{
    public function __construct(private readonly NarrativeWriter $writer) {}

    /** @return array{text: string, source: string, generated_at: string} */
    public function forContact(Workspace $workspace, Contact $contact): array
    {
        $facts = self::facts($workspace, $contact);
        $key = RedisKey::for($workspace->id, 'ai:profile:'.$contact->id.':'.md5((string) json_encode($facts)));

        return Cache::remember($key, now()->addDays(30), function () use ($workspace, $facts): array {
            $result = $this->writer->write(
                AiRequest::ask($workspace->id, 'profile_summary', self::system(), "Datos del cliente (JSON):\n".json_encode($facts, JSON_UNESCAPED_UNICODE)),
                $facts,
                fn () => self::template($facts, (string) $workspace->currency),
            );

            return ['text' => trim($result->text), 'source' => $result->source, 'generated_at' => now()->toIso8601String()];
        });
    }

    public static function system(): string
    {
        return 'Eres un asesor comercial de una tienda en línea de Latinoamérica. Con los datos agregados de un cliente, escribe en español neutro 2 o 3 frases: '
            .'quién es (cuánto y cada cuánto compra, hace cuánto no compra) y qué conviene hacer con él. Usa solo cifras de los datos, tal como están o redondeadas; '
            .'si un dato falta, no lo menciones. Dinero con $ y puntos de miles. Sin listas ni títulos.';
    }

    /** @return array<string, mixed> */
    public static function facts(Workspace $workspace, Contact $contact): array
    {
        $first = trim((string) explode(' ', trim((string) $contact->first_name))[0]);
        $date = fn (string $attr): ?CarbonInterface => $contact->getAttribute($attr) instanceof CarbonInterface ? $contact->getAttribute($attr) : null;
        $last = $date('last_order_at');
        $tierId = $contact->getAttribute('tier_id');

        return array_filter([
            'nombre' => $first !== '' ? $first : null,
            'etapa' => $contact->getAttribute('lifecycle_stage'),
            'pedidos' => (int) $contact->getAttribute('orders_count'),
            'gasto_total' => round((float) $contact->getAttribute('revenue_total_ws')),
            'ticket_promedio' => round((float) $contact->getAttribute('aov_ws')),
            'primer_pedido' => $date('first_order_at')?->toDateString(),
            'dias_desde_ultimo_pedido' => $last !== null ? (int) $last->diffInDays(now()) : null,
            'dias_promedio_entre_pedidos' => $contact->getAttribute('avg_days_between_orders') !== null ? round((float) $contact->getAttribute('avg_days_between_orders')) : null,
            'segmento_rfm' => $contact->getAttribute('rfm_segment'),
            'riesgo_de_abandono' => $contact->getAttribute('churn_band'),
            'valor_previsto_12_meses' => $contact->getAttribute('predicted_clv_12m_ws') !== null ? round((float) $contact->getAttribute('predicted_clv_12m_ws')) : null,
            'proxima_compra_esperada' => $date('expected_next_order_at')?->toDateString(),
            'categorias_favoritas' => $contact->category_affinity,
            'proporcion_pedidos_con_cupon' => $contact->getAttribute('coupon_orders_ratio') !== null ? round((float) $contact->getAttribute('coupon_orders_ratio'), 2) : null,
            'puntos' => (int) $contact->getAttribute('points_balance') ?: null,
            'nivel' => is_string($tierId) && $tierId !== '' && class_exists(LoyaltyTier::class)
                ? LoyaltyTier::query()->whereKey($tierId)->value('name') : null,
            'canal_preferido' => $contact->getAttribute('preferred_channel'),
            'como_nos_conocio' => $contact->getAttribute('hdyhau'),
            'moneda' => (string) $workspace->currency,
        ], fn ($v) => $v !== null && $v !== '' && $v !== []);
    }

    /** @param array<string, mixed> $facts */
    public static function template(array $facts, string $currency): string
    {
        $name = (string) ($facts['nombre'] ?? __('Este cliente'));
        $orders = (int) ($facts['pedidos'] ?? 0);
        if ($orders === 0) {
            return __(':name todavía no ha comprado.', ['name' => $name]);
        }
        $text = trans_choice(':name ha hecho :count pedido por :total.|:name ha hecho :count pedidos por :total.', $orders, [
            'name' => $name, 'count' => $orders, 'total' => Money::format($facts['gasto_total'] ?? 0, $currency),
        ]);
        if (isset($facts['dias_desde_ultimo_pedido'])) {
            $text .= ' '.__('Su último pedido fue hace :days días.', ['days' => $facts['dias_desde_ultimo_pedido']]);
        }

        return $text;
    }
}
