<?php

declare(strict_types=1);

namespace App\Domain\Loyalty\Support;

use App\Domain\Loyalty\Models\LoyaltyProgram;
use App\Domain\Loyalty\Models\LoyaltyReward;
use App\Domain\Loyalty\Models\LoyaltyTier;
use App\Domain\Workspaces\Models\Workspace;
use Illuminate\Support\Collection;

/**
 * La página explicativa del programa, generada sola con sus reglas (modulos.md
 * M13): cómo ganar, cómo canjear, niveles, saldo y condiciones. La muestra el
 * plugin (`GET /p/{key}/program`) y la vista previa del panel.
 */
final class ProgramExplainer
{
    /**
     * @param  Collection<int, LoyaltyTier>  $tiers
     * @param  Collection<int, LoyaltyReward>  $rewards
     * @return array{title: string, intro: string, sections: list<array{key: string, title: string, items: list<string>}>}
     */
    public static function build(LoyaltyProgram $program, Collection $tiers, Collection $rewards, Workspace $workspace): array
    {
        $rules = $program->rules();
        $currency = $workspace->currency;
        $locale = $workspace->locale ?: 'es-CO';
        $name = $program->currency_name;
        $money = fn (string|float $v) => MoneyText::format($v, $currency, $locale);
        $pts = fn (int $p) => MoneyText::points($p, $locale);

        $earn = [];
        if ($rules->pointsPer > 0) {
            $earn[] = __('Gana :points :name por cada :amount en tus compras.', ['points' => $pts($rules->pointsPer), 'name' => $name, 'amount' => $money($rules->amountPer)]);
        }
        $labels = [
            'signup' => __('Crea tu cuenta: :points :name.'),
            'birthday' => __('En tu cumpleaños: :points :name de regalo.'),
            'review' => __('Deja una reseña: :points :name.'),
            'review_media' => __('Reseña con foto o video: :points :name.'),
            'profile' => __('Completa tu perfil: :points :name.'),
        ];
        foreach ($labels as $reason => $text) {
            if ($rules->fixedPoints($reason) > 0) {
                $earn[] = strtr($text, [':points' => $pts($rules->fixedPoints($reason)), ':name' => $name]);
            }
        }
        foreach ($rules->campaigns as $c) {
            $earn[] = __(':name: puntos x:m del :from al :to.', ['name' => $c['name'] !== '' ? $c['name'] : __('Puntos extra'), 'm' => rtrim(rtrim(number_format($c['multiplier'], 2, ',', ''), '0'), ','), 'from' => $c['starts_on'], 'to' => $c['ends_on']]);
        }

        $redeem = [];
        foreach ($rewards->where('status', 'active')->sortBy('points_cost') as $reward) {
            $redeem[] = __(':points :name → :reward', ['points' => $pts($reward->points_cost), 'name' => $name, 'reward' => $reward->name]);
        }

        $levels = [];
        foreach ($tiers->sortBy('rank') as $tier) {
            $threshold = match ($tier->threshold_type) {
                'spend_12m' => __('compras por :amount en 12 meses', ['amount' => $money((string) $tier->threshold)]),
                'points_12m' => __(':points :name ganados en 12 meses', ['points' => $pts((int) $tier->threshold), 'name' => $name]),
                default => __(':n pedidos en 12 meses', ['n' => (int) $tier->threshold]),
            };
            $benefits = self::benefits($tier);
            $levels[] = (float) $tier->threshold > 0
                ? __(':tier: desde :threshold.', ['tier' => $tier->name, 'threshold' => $threshold]).($benefits !== [] ? ' '.implode(' · ', $benefits) : '')
                : $tier->name.($benefits !== [] ? ': '.implode(' · ', $benefits) : '');
        }

        $conditions = [];
        if ($rules->pendingDays > 0) {
            $conditions[] = __('Los :name de una compra se confirman :days días después de recibir tu pedido (periodo de devolución). Si devuelves la compra, se descuentan.', ['name' => $name, 'days' => $rules->pendingDays]);
        }
        $conditions[] = $rules->expiryMonths > 0
            ? __('Tus :name vencen :months meses después de ganados. Te avisaremos antes.', ['name' => $name, 'months' => $rules->expiryMonths])
            : __('Tus :name no vencen.', ['name' => $name]);
        $conditions[] = __('Cada canje te da un cupón de un solo uso para tu próxima compra.');

        $wallet = [];
        if ((float) $rules->cashbackPct > 0) {
            $wallet[] = __('Recibe el :pct% de tus compras como saldo a favor.', ['pct' => rtrim(rtrim($rules->cashbackPct, '0'), '.')]);
        }
        $wallet[] = __('Usa tu saldo a favor en el checkout con el botón «Usar saldo».');

        $sections = array_values(array_filter([
            ['key' => 'earn', 'title' => __('Cómo ganar :name', ['name' => $name]), 'items' => $earn],
            ['key' => 'redeem', 'title' => __('Cómo usar tus :name', ['name' => $name]), 'items' => $redeem],
            ['key' => 'tiers', 'title' => __('Niveles'), 'items' => $levels],
            ['key' => 'wallet', 'title' => __('Saldo a favor'), 'items' => $wallet],
            ['key' => 'conditions', 'title' => __('Condiciones'), 'items' => $conditions],
        ], fn (array $s) => $s['items'] !== []));

        return [
            'title' => __('Programa :name', ['name' => $name]),
            'intro' => __('Cada compra en :store te da :name que puedes cambiar por descuentos y regalos.', ['store' => $workspace->name, 'name' => $name]),
            'sections' => $sections,
        ];
    }

    /** @return list<string> beneficios de un nivel en texto */
    public static function benefits(LoyaltyTier $tier): array
    {
        $b = (array) ($tier->benefits ?? []);
        $out = [];
        if ($tier->multiplier() > 1) {
            $out[] = __(':m veces los puntos', ['m' => rtrim(rtrim(number_format($tier->multiplier(), 2, ',', ''), '0'), ',')]);
        }
        if (! empty($b['free_shipping'])) {
            $out[] = __('Envío gratis');
        }
        if (! empty($b['early_access'])) {
            $out[] = __('Acceso anticipado a lanzamientos y ofertas');
        }
        if ($tier->wc_role !== null && $tier->wc_role !== '') {
            $out[] = __('Precios especiales');
        }
        if (isset($b['gifts']) && is_string($b['gifts']) && trim($b['gifts']) !== '') {
            $out[] = mb_substr(trim($b['gifts']), 0, 120);
        }

        return $out;
    }
}
