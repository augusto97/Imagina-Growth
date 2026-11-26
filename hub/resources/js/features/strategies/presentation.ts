import {
    Bell,
    Bot,
    Cake,
    Calculator,
    CalendarCheck,
    CalendarClock,
    CalendarRange,
    CalendarX,
    CreditCard,
    Eye,
    FileText,
    Gauge,
    Gift,
    GraduationCap,
    HeartHandshake,
    House,
    Inbox,
    ListChecks,
    Mail,
    MapPin,
    Megaphone,
    MessageCircle,
    MessageCircleQuestion,
    MessagesSquare,
    Moon,
    MousePointerClick,
    PackageCheck,
    PackagePlus,
    PartyPopper,
    RadioTower,
    RefreshCw,
    Repeat,
    RotateCcw,
    ShoppingCart,
    Sparkles,
    Share2,
    Star,
    ThumbsUp,
    Ticket,
    Truck,
    Undo2,
    Users,
    Wallet,
    Zap,
    type LucideIcon,
} from 'lucide-react';
import type { IconTone } from '@/components/ui/icon-tile';
import type { StrategyCatalog, StrategyGoal, StrategyKey, StrategyStatus, StrategySummary } from './types';

/* Cómo se ven las estrategias (docs/diseno/rediseno-v2.md §6): ícono y color por objetivo, el tono de cada estado, la
 * acción principal de una tarjeta y los filtros del catálogo. Lógica pura, con pruebas en presentation.test.ts. */

export const GOAL_STYLE: Record<StrategyGoal, { icon: LucideIcon; tone: IconTone }> = {
    recover_sales: { icon: ShoppingCart, tone: 'orange' },
    sell_again: { icon: Repeat, tone: 'indigo' },
    loyalty: { icon: Gift, tone: 'rose' },
    capture: { icon: MousePointerClick, tone: 'teal' },
    reputation: { icon: Star, tone: 'amber' },
    attention: { icon: MessagesSquare, tone: 'violet' },
    ads: { icon: Megaphone, tone: 'sky' },
    services: { icon: CalendarCheck, tone: 'emerald' },
};

/** El ícono de cada estrategia (el color es el de su objetivo). */
const ICONS: Partial<Record<StrategyKey, LucideIcon>> = {
    abandoned_cart: ShoppingCart,
    abandoned_checkout: CreditCard,
    payment_pending: Wallet,
    cod_confirm: Truck,
    browse_abandonment: Eye,
    second_purchase: Repeat,
    replenishment: RefreshCw,
    winback: HeartHandshake,
    ai_reactivate: Sparkles,
    back_in_stock: Bell,
    loyalty_points: Gift,
    birthday: Cake,
    referrals: Share2,
    welcome_popup: MousePointerClick,
    whatsapp_button: MessageCircle,
    product_quiz: ListChecks,
    review_request: Star,
    nps: Gauge,
    google_reviews: MapPin,
    ai_sales_agent: Bot,
    inbox_assignment: Inbox,
    after_hours: Moon,
    ads_conversions: RadioTower,
    ads_audiences: Users,
    service_lead: Zap,
    chat_qualification: MessageCircleQuestion,
    appointment_reminders: CalendarClock,
    renewal: RotateCcw,
    quote_calculator: Calculator,
    lead_nurture: Mail,
    quote_followup: FileText,
    appointment_confirmation: CalendarCheck,
    no_show_recovery: CalendarX,
    post_service: ThumbsUp,
    periodic_service: CalendarRange,
    event_registration: Ticket,
    enrollment_followup: GraduationCap,
    property_interest: House,
    lost_lead_revival: Undo2,
    new_client_welcome: PartyPopper,
    services_reactivation: HeartHandshake,
    cross_sell: PackagePlus,
    delivery_check: PackageCheck,
    first_purchase_anniversary: Cake,
};

export function strategyStyle(strategy: Pick<StrategySummary, 'key' | 'goal'>): { icon: LucideIcon; tone: IconTone } {
    const goal = GOAL_STYLE[strategy.goal];
    return { icon: ICONS[strategy.key] ?? goal.icon, tone: goal.tone };
}

export type BadgeTone = 'success' | 'warning' | 'secondary' | 'outline' | 'brand' | 'info';

/** La insignia de cada estado (siempre con texto: el color nunca va solo). */
export function statusBadge(status: StrategyStatus): BadgeTone {
    switch (status) {
        case 'active':
            return 'success';
        case 'attention':
            return 'warning';
        case 'paused':
            return 'secondary';
        case 'available':
            return 'brand';
        case 'not_in_plan':
            return 'info';
        default:
            return 'outline';
    }
}

/** Lo que hace el botón de una tarjeta: prender, ver qué falta, revisar, reanudar, ver el plan o nada (próximamente). */
export type CardAction = 'activate' | 'resume' | 'fix' | 'review' | 'view' | 'plan' | 'soon';

export function cardAction(strategy: Pick<StrategySummary, 'status'>): CardAction {
    switch (strategy.status) {
        case 'available':
            return 'activate';
        case 'paused':
            return 'resume';
        case 'needs_setup':
            return 'fix';
        case 'attention':
            return 'review';
        case 'active':
            return 'view';
        case 'not_in_plan':
            return 'plan';
        default:
            return 'soon';
    }
}

/** Los estados se filtran en cuatro grupos que la persona entiende. */
export type StatusFilter = 'all' | 'on' | 'ready' | 'setup' | 'soon';

export function statusGroup(status: StrategyStatus): Exclude<StatusFilter, 'all'> {
    switch (status) {
        case 'active':
        case 'attention':
            return 'on';
        case 'available':
        case 'paused':
            return 'ready';
        case 'needs_setup':
        case 'not_in_plan':
            return 'setup';
        default:
            return 'soon';
    }
}

export interface CatalogFilter {
    goal: StrategyGoal | 'all';
    status: StatusFilter;
    /** También las que no aplican al tipo de negocio (las de tienda para una clínica, por ejemplo). */
    showAll: boolean;
}

/**
 * El catálogo por objetivo con los filtros aplicados: en cada objetivo, primero las prendidas, después las listas, las
 * que piden algo y al final las de «Próximamente»; sin `showAll`, solo las que le sirven al negocio.
 */
export function filterCatalog(
    catalog: Pick<StrategyCatalog, 'goals' | 'data'>,
    filter: CatalogFilter,
): { goal: StrategyCatalog['goals'][number]; items: StrategySummary[] }[] {
    const order: Record<Exclude<StatusFilter, 'all'>, number> = { on: 0, ready: 1, setup: 2, soon: 3 };
    return catalog.goals
        .filter((g) => filter.goal === 'all' || g.key === filter.goal)
        .map((goal) => ({
            goal,
            items: catalog.data
                .filter((s) => s.goal === goal.key && (filter.showAll || s.relevant) && (filter.status === 'all' || statusGroup(s.status) === filter.status))
                .sort((a, b) => order[statusGroup(a.status)] - order[statusGroup(b.status)] || (a.recommended_rank ?? 99) - (b.recommended_rank ?? 99)),
        }))
        .filter((g) => g.items.length > 0);
}

/** Los objetivos que tienen algo para este negocio (las pestañas del filtro). */
export function relevantGoals(catalog: Pick<StrategyCatalog, 'goals' | 'data'>, showAll: boolean): StrategyCatalog['goals'] {
    return catalog.goals.filter((g) => catalog.data.some((s) => s.goal === g.key && (showAll || s.relevant)));
}

/** Cuántas no aplican al tipo de negocio (para «Ver también las N que no aplican»). */
export function hiddenCount(catalog: Pick<StrategyCatalog, 'data'>): number {
    return catalog.data.filter((s) => !s.relevant).length;
}

/** El dinero que generaron este mes las estrategias (también una que se pausó después de vender este mes). */
export function monthRevenue(strategies: readonly Pick<StrategySummary, 'result'>[]): number {
    return strategies.reduce((sum, s) => sum + Number(s.result?.revenue ?? 0), 0);
}
