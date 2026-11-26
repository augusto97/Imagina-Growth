<?php

declare(strict_types=1);

namespace App\Domain\Strategies;

use App\Domain\Strategies\Blocks\AdConversionsBlock;
use App\Domain\Strategies\Blocks\AudiencesBlock;
use App\Domain\Strategies\Blocks\BookingRemindersBlock;
use App\Domain\Strategies\Blocks\FlowBlock;
use App\Domain\Strategies\Blocks\GoogleReviewsBlock;
use App\Domain\Strategies\Blocks\InboxAssignmentBlock;
use App\Domain\Strategies\Blocks\LoyaltyProgramBlock;
use App\Domain\Strategies\Blocks\NpsSurveyBlock;
use App\Domain\Strategies\Blocks\ProductWatchesBlock;
use App\Domain\Strategies\Blocks\QualificationBlock;
use App\Domain\Strategies\Blocks\QuoteCalculatorBlock;
use App\Domain\Strategies\Blocks\ReferralProgramBlock;
use App\Domain\Strategies\Blocks\ReviewRequestBlock;
use App\Domain\Strategies\Blocks\SalesAgentBlock;
use App\Domain\Strategies\Blocks\WidgetBlock;
use App\Domain\Strategies\Support\StrategyDefinition;

/**
 * El catálogo de estrategias (docs/diseno/rediseno-v2.md §6): cada una traduce bloques que ya existen a un resultado
 * («recupera carritos», «trae de vuelta a tus clientes»). Para agregar una: una entrada aquí con sus bloques, sus
 * requisitos y su potencial, y sus textos en `lang/es/strategies.php` → `items.<clave>` (ver hub/CLAUDE.md).
 *
 * La clave es estable: la guarda `workspace_strategies` y la usa la SPA.
 */
final class StrategyRegistry
{
    /** Objetivos, en el orden del catálogo. */
    public const GOALS = ['recover_sales', 'sell_again', 'loyalty', 'capture', 'reputation', 'attention', 'ads', 'services'];

    /** Las 3 que propone el asistente (paso 5) y el Inicio, en orden de preferencia por tipo de negocio. */
    public const RECOMMENDED = [
        'store' => ['abandoned_cart', 'cod_confirm', 'payment_pending', 'second_purchase', 'welcome_popup', 'review_request'],
        'services' => ['lead_response', 'appointment_reminders', 'chat_qualification', 'ai_sales_agent', 'renewal'],
        'both' => ['abandoned_cart', 'lead_response', 'cod_confirm', 'payment_pending', 'appointment_reminders', 'second_purchase'],
    ];

    /**
     * Las que su módulo trae prendidas por defecto (los avisos de stock de una tienda, la asignación de la bandeja):
     * cuentan como activas, pero no como «ya prendiste tus primeras estrategias» en el asistente.
     */
    public const ON_BY_DEFAULT = ['back_in_stock', 'inbox_assignment'];

    /** Los kinds de widget que cuentan como «popup de bienvenida». */
    private const POPUP_KINDS = ['popup', 'two_step', 'spin_wheel', 'teaser', 'bar', 'embedded_form'];

    /** @var list<StrategyDefinition>|null */
    private static ?array $all = null;

    /** @return list<StrategyDefinition> */
    public static function all(): array
    {
        return self::$all ??= [
            // ── Recuperar ventas ─────────────────────────────────────────────
            new StrategyDefinition('abandoned_cart', 'recover_sales', 'store', [], ['store'], [new FlowBlock('abandoned_cart')], 'abandoned_carts', money: ['recovered_carts']),
            new StrategyDefinition('abandoned_checkout', 'recover_sales', 'store', [], ['store'], [new FlowBlock('abandoned_checkout')], 'abandoned_checkouts'),
            new StrategyDefinition('payment_pending', 'recover_sales', 'store', [], ['store'], [new FlowBlock('payment_pending')], 'pending_payments'),
            new StrategyDefinition('cod_confirm', 'recover_sales', 'store', [], ['store'], [new FlowBlock('cod_confirm')], 'cod_pending'),
            new StrategyDefinition('browse_abandonment', 'recover_sales', 'store', [], ['store'], [new FlowBlock('browse_abandonment')], 'browsers'),
            // ── Volver a vender ──────────────────────────────────────────────
            new StrategyDefinition('second_purchase', 'sell_again', 'store', [], ['store', 'history'], [new FlowBlock('second_purchase')], 'one_time_buyers'),
            new StrategyDefinition('replenishment', 'sell_again', 'store', [], ['store', 'history'], [new FlowBlock('replenishment')], 'due_soon'),
            new StrategyDefinition('winback', 'sell_again', 'store', [], ['store', 'history'], [new FlowBlock('winback')], 'at_risk'),
            new StrategyDefinition('ai_reactivate', 'sell_again', 'both', ['ai'], ['site', 'history', 'ai'], [new FlowBlock('ai_reactivate')], 'dormant'),
            new StrategyDefinition('back_in_stock', 'sell_again', 'store', [], ['store'], [new ProductWatchesBlock], 'watchers'),
            // ── Fidelizar ────────────────────────────────────────────────────
            new StrategyDefinition('loyalty_points', 'loyalty', 'store', ['loyalty'], ['store'],
                [new LoyaltyProgramBlock, new FlowBlock('vip_welcome', true), new FlowBlock('points_expiring', true)], 'repeat_customers'),
            new StrategyDefinition('birthday', 'loyalty', 'store', [], ['store'], [new FlowBlock('birthday')], 'birthdays'),
            new StrategyDefinition('referrals', 'loyalty', 'store', ['referrals'], ['store'],
                [new ReferralProgramBlock, new FlowBlock('referral_invite', true)], 'happy_customers', money: ['referrals']),
            // ── Captar contactos ─────────────────────────────────────────────
            new StrategyDefinition('welcome_popup', 'capture', 'both', ['onsite'], ['site'],
                [new WidgetBlock(self::POPUP_KINDS), new FlowBlock('welcome', true)], 'visitors'),
            new StrategyDefinition('whatsapp_button', 'capture', 'both', ['onsite'], ['site', 'wa_number'], [new WidgetBlock(['wa_widget'])], 'visitors'),
            new StrategyDefinition('product_quiz', 'capture', 'store', ['onsite'], ['store'], [new WidgetBlock(['quiz'])], 'visitors'),
            // ── Reputación ───────────────────────────────────────────────────
            new StrategyDefinition('review_request', 'reputation', 'store', ['reviews'], ['store'], [new ReviewRequestBlock], 'unreviewed_orders'),
            new StrategyDefinition('nps', 'reputation', 'store', ['reviews'], ['store'], [new NpsSurveyBlock], 'recent_customers'),
            new StrategyDefinition('google_reviews', 'reputation', 'store', ['reviews'], ['store', 'google_url'], [new GoogleReviewsBlock], 'recent_customers'),
            // ── Atención ─────────────────────────────────────────────────────
            new StrategyDefinition('ai_sales_agent', 'attention', 'both', ['inbox', 'ai_agent'], ['whatsapp', 'ai'], [new SalesAgentBlock(SalesAgentBlock::ALWAYS)], 'inbound',
                ['manageInbox'], ['after_hours'], ['ai_agent']),
            new StrategyDefinition('inbox_assignment', 'attention', 'both', ['inbox'], ['inbox_channel'], [new InboxAssignmentBlock], 'unassigned', ['manageInbox'], money: ['inbox']),
            new StrategyDefinition('after_hours', 'attention', 'both', ['inbox', 'ai_agent'], ['whatsapp', 'ai'], [new SalesAgentBlock(SalesAgentBlock::OUT_OF_HOURS)], 'after_hours_inbound',
                ['manageInbox'], ['ai_sales_agent'], ['ai_agent']),
            // ── Anuncios ─────────────────────────────────────────────────────
            new StrategyDefinition('ads_conversions', 'ads', 'both', [], ['ad_destination'], [new AdConversionsBlock], 'paid_orders', ['manageWorkspace']),
            new StrategyDefinition('ads_audiences', 'ads', 'store', [], ['ad_destination', 'history'], [new AudiencesBlock], 'customers'),
            // ── Servicios ────────────────────────────────────────────────────
            new StrategyDefinition('lead_response', 'services', 'services', ['services'], ['site'], [new FlowBlock('service_lead')], 'unanswered_leads', money: ['leads']),
            new StrategyDefinition('chat_qualification', 'services', 'services', ['services', 'ai_agent'], ['agent_on'], [new QualificationBlock], 'chat_leads', ['manageServices']),
            new StrategyDefinition('appointment_reminders', 'services', 'services', ['bookings'], ['booking_service'], [new BookingRemindersBlock], 'upcoming_appointments',
                ['manageServices'], money: ['bookings']),
            new StrategyDefinition('renewal', 'services', 'services', ['services'], [], [new FlowBlock('renewal')], 'renewals'),
            new StrategyDefinition('quote_calculator', 'services', 'services', ['quotes'], ['site', 'quote_calculator'], [new QuoteCalculatorBlock], null,
                ['manageServices'], money: ['quotes']),
        ];
    }

    public static function find(string $key): ?StrategyDefinition
    {
        foreach (self::all() as $definition) {
            if ($definition->key === $key) {
                return $definition;
            }
        }

        return null;
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_map(fn (StrategyDefinition $d) => $d->key, self::all());
    }

    /** La estrategia de un flujo de la biblioteca (`flows.template_key`), si la hay. */
    public static function forFlow(string $templateKey): ?string
    {
        foreach (self::all() as $definition) {
            if (in_array($templateKey, $definition->flowKeys(), true)) {
                return $definition->key;
            }
        }

        return null;
    }

    /** La estrategia de un tipo de widget, si la hay (el de bienvenida a referidos es de Referidos). */
    public static function forWidget(string $kind): ?string
    {
        if ($kind === 'referral_welcome') {
            return 'referrals';
        }
        foreach (self::all() as $definition) {
            if (in_array($kind, $definition->widgetKinds(), true)) {
                return $definition->key;
            }
        }

        return null;
    }
}
