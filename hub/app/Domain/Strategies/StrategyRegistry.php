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
 * El catálogo de estrategias (docs/diseno/rediseno-v2.md §6 y §11): cada una traduce bloques que ya existen a un
 * resultado («recupera carritos», «responde al instante a cada contacto»). Declara a qué tipos de negocio y verticales
 * sirve y en qué lugar se recomienda por vertical (`recommendedFor`). Para agregar una: una entrada aquí con sus
 * bloques, sus requisitos y su potencial, y sus textos en `lang/es/strategies.php` → `items.<clave>` (ver
 * hub/CLAUDE.md §20).
 *
 * Las de la biblioteca ampliada (§11.4, flujos 21 a 35) se declaran con su FlowBlock aunque el flujo todavía no esté
 * en FlowLibrary: quedan `coming_soon` («Próximamente») y se activan solas cuando el flujo llega.
 *
 * La clave es estable: la guarda `workspace_strategies` y la usa la SPA.
 */
final class StrategyRegistry
{
    /** Objetivos, en el orden del catálogo. */
    public const GOALS = ['recover_sales', 'sell_again', 'loyalty', 'capture', 'reputation', 'attention', 'ads', 'services'];

    /** Las de la biblioteca ampliada (§11.4), en el orden de su tabla (flujos 21 a 35). */
    public const EXTENDED = [
        'lead_nurture', 'quote_followup', 'appointment_confirmation', 'no_show_recovery', 'post_service', 'periodic_service',
        'event_registration', 'enrollment_followup', 'property_interest', 'lost_lead_revival', 'new_client_welcome',
        'services_reactivation', 'cross_sell', 'delivery_check', 'first_purchase_anniversary',
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
            new StrategyDefinition('abandoned_cart', 'recover_sales', 'store', [], ['store'], [new FlowBlock('abandoned_cart')], 'abandoned_carts', money: ['recovered_carts'],
                recommendedFor: ['store' => 1]),
            new StrategyDefinition('abandoned_checkout', 'recover_sales', 'store', [], ['store'], [new FlowBlock('abandoned_checkout')], 'abandoned_checkouts'),
            new StrategyDefinition('payment_pending', 'recover_sales', 'store', [], ['store'], [new FlowBlock('payment_pending')], 'pending_payments', recommendedFor: ['store' => 3]),
            new StrategyDefinition('cod_confirm', 'recover_sales', 'store', [], ['store'], [new FlowBlock('cod_confirm')], 'cod_pending', recommendedFor: ['store' => 2]),
            new StrategyDefinition('browse_abandonment', 'recover_sales', 'store', [], ['store'], [new FlowBlock('browse_abandonment')], 'browsers'),
            // ── Volver a vender ──────────────────────────────────────────────
            new StrategyDefinition('second_purchase', 'sell_again', 'store', [], ['store', 'history'], [new FlowBlock('second_purchase')], 'one_time_buyers', recommendedFor: ['store' => 4]),
            new StrategyDefinition('replenishment', 'sell_again', 'store', [], ['store', 'history'], [new FlowBlock('replenishment')], 'due_soon'),
            new StrategyDefinition('winback', 'sell_again', 'store', [], ['store', 'history'], [new FlowBlock('winback')], 'at_risk'),
            new StrategyDefinition('ai_reactivate', 'sell_again', 'both', ['ai'], ['site', 'history', 'ai'], [new FlowBlock('ai_reactivate')], 'dormant'),
            new StrategyDefinition('back_in_stock', 'sell_again', 'store', [], ['store'], [new ProductWatchesBlock], 'watchers'),
            new StrategyDefinition('periodic_service', 'sell_again', 'both', [], ['site'], [new FlowBlock('periodic_service')], 'customers',
                verticals: ['health', 'automotive', 'store'], recommendedFor: ['health' => 3, 'automotive' => 2]),
            new StrategyDefinition('services_reactivation', 'sell_again', 'services', ['services'], ['site'], [new FlowBlock('services_reactivation')], null,
                recommendedFor: ['professional' => 10, 'other' => 8]),
            new StrategyDefinition('cross_sell', 'sell_again', 'store', [], ['store', 'history'], [new FlowBlock('cross_sell')], 'recent_customers', recommendedFor: ['store' => 7]),
            // ── Fidelizar ────────────────────────────────────────────────────
            new StrategyDefinition('loyalty_points', 'loyalty', 'store', ['loyalty'], ['store'],
                [new LoyaltyProgramBlock, new FlowBlock('vip_welcome', true), new FlowBlock('points_expiring', true)], 'repeat_customers'),
            new StrategyDefinition('birthday', 'loyalty', 'store', [], ['store'], [new FlowBlock('birthday')], 'birthdays'),
            new StrategyDefinition('referrals', 'loyalty', 'store', ['referrals'], ['store'],
                [new ReferralProgramBlock, new FlowBlock('referral_invite', true)], 'happy_customers', money: ['referrals']),
            new StrategyDefinition('first_purchase_anniversary', 'loyalty', 'store', [], ['store', 'history'], [new FlowBlock('first_purchase_anniversary')], 'customers'),
            new StrategyDefinition('new_client_welcome', 'loyalty', 'services', ['services'], ['site'], [new FlowBlock('new_client_welcome')], null,
                recommendedFor: ['professional' => 8, 'b2b' => 8]),
            // ── Captar contactos ─────────────────────────────────────────────
            new StrategyDefinition('welcome_popup', 'capture', 'both', ['onsite'], ['site'],
                [new WidgetBlock(self::POPUP_KINDS), new FlowBlock('welcome', true)], 'visitors', recommendedFor: ['store' => 5, 'hospitality' => 6]),
            new StrategyDefinition('whatsapp_button', 'capture', 'both', ['onsite'], ['site', 'wa_number'], [new WidgetBlock(['wa_widget'])], 'visitors',
                recommendedFor: ['hospitality' => 7, 'real_estate' => 7, 'automotive' => 7]),
            new StrategyDefinition('product_quiz', 'capture', 'store', ['onsite'], ['store'], [new WidgetBlock(['quiz'])], 'visitors'),
            // ── Reputación ───────────────────────────────────────────────────
            new StrategyDefinition('review_request', 'reputation', 'store', ['reviews'], ['store'], [new ReviewRequestBlock], 'unreviewed_orders', recommendedFor: ['store' => 6]),
            new StrategyDefinition('nps', 'reputation', 'store', ['reviews'], ['store'], [new NpsSurveyBlock], 'recent_customers'),
            new StrategyDefinition('google_reviews', 'reputation', 'store', ['reviews'], ['store', 'google_url'], [new GoogleReviewsBlock], 'recent_customers'),
            new StrategyDefinition('post_service', 'reputation', 'services', ['services'], ['site'], [new FlowBlock('post_service')], null,
                recommendedFor: ['health' => 6, 'hospitality' => 3, 'automotive' => 6]),
            new StrategyDefinition('delivery_check', 'reputation', 'store', [], ['store'], [new FlowBlock('delivery_check')], 'paid_orders', recommendedFor: ['store' => 8]),
            // ── Atención ─────────────────────────────────────────────────────
            new StrategyDefinition('ai_sales_agent', 'attention', 'both', ['inbox', 'ai_agent'], ['whatsapp', 'ai'], [new SalesAgentBlock(SalesAgentBlock::ALWAYS)], 'inbound',
                ['manageInbox'], ['after_hours'], ['ai_agent'], recommendedFor: ['professional' => 5, 'health' => 7, 'hospitality' => 4, 'other' => 5]),
            new StrategyDefinition('inbox_assignment', 'attention', 'both', ['inbox'], ['inbox_channel'], [new InboxAssignmentBlock], 'unassigned', ['manageInbox'], money: ['inbox']),
            new StrategyDefinition('after_hours', 'attention', 'both', ['inbox', 'ai_agent'], ['whatsapp', 'ai'], [new SalesAgentBlock(SalesAgentBlock::OUT_OF_HOURS)], 'after_hours_inbound',
                ['manageInbox'], ['ai_sales_agent'], ['ai_agent'], recommendedFor: ['hospitality' => 2]),
            // ── Anuncios ─────────────────────────────────────────────────────
            new StrategyDefinition('ads_conversions', 'ads', 'both', [], ['ad_destination'], [new AdConversionsBlock], 'paid_orders', ['manageWorkspace'], recommendedFor: ['b2b' => 6]),
            new StrategyDefinition('ads_audiences', 'ads', 'store', [], ['ad_destination', 'history'], [new AudiencesBlock], 'customers'),
            // ── Servicios ────────────────────────────────────────────────────
            new StrategyDefinition('service_lead', 'services', 'services', ['services'], ['site'], [new FlowBlock('service_lead')], 'unanswered_leads', money: ['leads'],
                recommendedFor: ['professional' => 1, 'health' => 5, 'education' => 4, 'real_estate' => 1, 'hospitality' => 1, 'automotive' => 1, 'b2b' => 1, 'other' => 1]),
            new StrategyDefinition('lead_nurture', 'services', 'services', ['services'], ['site'], [new FlowBlock('lead_nurture')], 'open_leads',
                recommendedFor: ['professional' => 3, 'education' => 3, 'real_estate' => 6, 'b2b' => 3, 'other' => 2]),
            new StrategyDefinition('quote_followup', 'services', 'services', ['services'], ['site'], [new FlowBlock('quote_followup')], null,
                recommendedFor: ['professional' => 2, 'automotive' => 3, 'b2b' => 2]),
            new StrategyDefinition('chat_qualification', 'services', 'services', ['services', 'ai_agent'], ['agent_on'], [new QualificationBlock], 'chat_leads', ['manageServices'],
                recommendedFor: ['professional' => 4, 'education' => 5, 'real_estate' => 4, 'b2b' => 5, 'other' => 3]),
            new StrategyDefinition('appointment_reminders', 'services', 'services', ['bookings'], ['booking_service'], [new BookingRemindersBlock], 'upcoming_appointments',
                ['manageServices'], money: ['bookings'],
                recommendedFor: ['health' => 4, 'education' => 6, 'real_estate' => 5, 'hospitality' => 5, 'automotive' => 4, 'other' => 4]),
            new StrategyDefinition('appointment_confirmation', 'services', 'services', ['bookings'], ['booking_service'], [new FlowBlock('appointment_confirmation')], 'upcoming_appointments',
                recommendedFor: ['health' => 1, 'automotive' => 5]),
            new StrategyDefinition('no_show_recovery', 'services', 'services', ['bookings'], ['booking_service'], [new FlowBlock('no_show_recovery')], 'no_shows',
                recommendedFor: ['health' => 2]),
            new StrategyDefinition('event_registration', 'services', 'services', [], ['site'], [new FlowBlock('event_registration')], null,
                verticals: ['education', 'b2b'], recommendedFor: ['education' => 1, 'b2b' => 4]),
            new StrategyDefinition('enrollment_followup', 'services', 'services', ['services'], ['site'], [new FlowBlock('enrollment_followup')], 'open_leads',
                verticals: ['education'], recommendedFor: ['education' => 2]),
            new StrategyDefinition('property_interest', 'services', 'services', ['services'], ['site'], [new FlowBlock('property_interest')], 'open_leads',
                verticals: ['real_estate'], recommendedFor: ['real_estate' => 2]),
            new StrategyDefinition('lost_lead_revival', 'services', 'services', ['services'], ['site'], [new FlowBlock('lost_lead_revival')], 'lost_leads',
                recommendedFor: ['real_estate' => 3, 'professional' => 9]),
            new StrategyDefinition('renewal', 'services', 'services', ['services'], [], [new FlowBlock('renewal')], 'renewals', recommendedFor: ['professional' => 7, 'b2b' => 7]),
            new StrategyDefinition('quote_calculator', 'services', 'services', ['quotes'], ['site', 'quote_calculator'], [new QuoteCalculatorBlock], null,
                ['manageServices'], money: ['quotes'], recommendedFor: ['professional' => 6, 'automotive' => 5]),
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

    /** ¿Ya se puede usar? Solo una estrategia de un flujo que todavía no está en la biblioteca queda «Próximamente». */
    public static function available(StrategyDefinition $definition): bool
    {
        $primary = $definition->primary();

        return ! $primary instanceof FlowBlock || $primary->available();
    }

    /**
     * Las estrategias recomendadas para una vertical, de la primera a la última (`recommendedFor`).
     *
     * @return list<string>
     */
    public static function rankedFor(string $vertical): array
    {
        $ranked = [];
        foreach (self::all() as $definition) {
            if (($rank = $definition->rankFor($vertical)) !== null) {
                $ranked[$definition->key] = $rank;
            }
        }
        asort($ranked);

        return array_keys($ranked);
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
