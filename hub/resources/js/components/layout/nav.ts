import {
    Link2,
    ListChecks,
    BarChart3,
    Building2,
    Calculator,
    CalendarDays,
    ClipboardList,
    CreditCard,
    FileChartColumn,
    FileText,
    Gift,
    Home,
    Inbox,
    Lightbulb,
    type LucideIcon,
    Megaphone,
    MessageCircleQuestion,
    MessageSquareQuote,
    MousePointerClick,
    Plug,
    RadioTower,
    Ruler,
    ServerCog,
    Settings2,
    Share2,
    Shapes,
    ShieldCheck,
    Sparkles,
    Target,
    Users,
    UsersRound,
    Webhook,
    Workflow,
} from 'lucide-react';
import type { Ability, PlanModule, Workspace, WorkspaceCapabilities } from '@/api/types';

/*
 * Menú del panel agrupado por tarea del negocio (docs/auditoria-2026-11.md U2). Lo usan la barra lateral, el menú del
 * celular (que monta la misma barra), la paleta ⌘K y los atajos «g x».
 *
 * Para agregar una pantalla al menú:
 *   1. Agrega un NavItem al grupo que corresponda en NAV (o un grupo nuevo, en el orden en que debe salir).
 *   2. Agrega su texto en nav.<key> (resources/js/locales/es-CO.json) y, si el grupo es nuevo, en nav.groups.<key>.
 *   3. Si la pantalla depende del plan, ponle `module`; si solo aplica a tiendas o a servicios, `audience`; si pide un
 *      permiso, `ability`. El menú la oculta solo cuando no aplica. `shortcut` es la tecla después de «g» (sin repetir).
 */

/** Tiendas WooCommerce o negocios de servicios (leads, agenda, cotizador). */
export type NavAudience = 'store' | 'services';

export interface NavItem {
    /** Texto en nav.<key>. */
    key: string;
    /** Ruta relativa a /w/:workspaceId ('' = Inicio). */
    to: string;
    icon: LucideIcon;
    ability?: Ability;
    /** Módulo del plan que la habilita (M21). */
    module?: PlanModule;
    /** Solo para tiendas (se oculta si el sitio no tiene WooCommerce) o solo para servicios. */
    audience?: NavAudience;
    /** Tecla después de «g». */
    shortcut?: string;
}

export interface NavGroup {
    /** Texto en nav.groups.<key>. El grupo `home` va sin título ni pliegue. */
    key: string;
    items: NavItem[];
}

export const NAV: NavGroup[] = [
    {
        key: 'home',
        items: [{ key: 'home', to: '', icon: Home, ability: 'viewDashboards', shortcut: 'h' }],
    },
    {
        key: 'customers',
        items: [
            { key: 'contacts', to: 'contactos', icon: Users, ability: 'viewAssignedContacts', shortcut: 'c' },
            { key: 'segments', to: 'segmentos', icon: Shapes, ability: 'viewAllContacts', shortcut: 'n' },
            { key: 'today', to: 'hoy', icon: ListChecks, ability: 'useLeads', module: 'services', audience: 'services', shortcut: 'y' },
            { key: 'leads', to: 'leads', icon: Target, ability: 'useLeads', module: 'services', audience: 'services', shortcut: 'd' },
            { key: 'agenda', to: 'agenda', icon: CalendarDays, ability: 'useLeads', module: 'bookings', audience: 'services', shortcut: 'g' },
        ],
    },
    {
        key: 'sell',
        items: [
            { key: 'inbox', to: 'bandeja', icon: Inbox, ability: 'useInbox', module: 'inbox', shortcut: 'b' },
            { key: 'flows', to: 'flujos', icon: Workflow, ability: 'viewDashboards', shortcut: 'f' },
            { key: 'campaigns', to: 'campanas', icon: Megaphone, ability: 'viewDashboards', shortcut: 'p' },
            { key: 'templates', to: 'plantillas', icon: FileText, ability: 'viewDashboards', shortcut: 't' },
            { key: 'onsite', to: 'en-el-sitio', icon: MousePointerClick, ability: 'viewDashboards', module: 'onsite', shortcut: 'w' },
            { key: 'quotes', to: 'en-el-sitio/cotizador', icon: Calculator, ability: 'viewDashboards', module: 'quotes', audience: 'services', shortcut: 'q' },
        ],
    },
    {
        key: 'retain',
        items: [
            { key: 'loyalty', to: 'fidelizacion', icon: Gift, ability: 'viewDashboards', module: 'loyalty', audience: 'store', shortcut: 'l' },
            { key: 'referrals', to: 'referidos', icon: Share2, ability: 'viewDashboards', module: 'referrals', audience: 'store', shortcut: 'r' },
            { key: 'reviews', to: 'resenas', icon: MessageSquareQuote, ability: 'viewDashboards', module: 'reviews', shortcut: 'v' },
        ],
    },
    {
        key: 'measure',
        items: [
            { key: 'analytics', to: 'analitica', icon: BarChart3, ability: 'viewDashboards', shortcut: 'a' },
            { key: 'insights', to: 'hallazgos', icon: Lightbulb, ability: 'viewDashboards', shortcut: 'i' },
            { key: 'intelligence', to: 'inteligencia', icon: Sparkles, ability: 'viewDashboards', shortcut: 'k' },
            { key: 'measurement', to: 'medicion', icon: Ruler, ability: 'viewDashboards', shortcut: 'm' },
            { key: 'reports', to: 'reportes', icon: FileChartColumn, ability: 'viewDashboards', shortcut: 'o' },
            { key: 'survey', to: 'encuesta', icon: MessageCircleQuestion, ability: 'viewDashboards' },
        ],
    },
    {
        key: 'settings',
        items: [
            { key: 'sites', to: 'sitios', icon: Plug, ability: 'viewSites', shortcut: 's' },
            { key: 'channels', to: 'canales', icon: RadioTower, ability: 'viewWorkspace' },
            { key: 'connections', to: 'conexiones', icon: Link2, ability: 'viewWorkspace' },
            { key: 'team', to: 'equipo', icon: UsersRound, ability: 'viewWorkspace', shortcut: 'e' },
            { key: 'workspace', to: 'ajustes', icon: Settings2, ability: 'viewWorkspace' },
            { key: 'consent', to: 'consentimiento', icon: ShieldCheck, ability: 'viewWorkspace' },
            { key: 'integrations', to: 'integraciones', icon: Webhook, ability: 'manageApi' },
            { key: 'billing', to: 'facturacion', icon: CreditCard, ability: 'manageBilling' },
            { key: 'audit', to: 'auditoria', icon: ClipboardList, ability: 'viewAuditLog' },
        ],
    },
    {
        key: 'admin',
        items: [
            { key: 'agency', to: 'agencia', icon: Building2, ability: 'viewAgency' },
            { key: 'platform', to: 'plataforma', icon: ServerCog, ability: 'platformAdmin' },
        ],
    },
];

/** Lo que decide qué entradas se ven: permisos del rol, módulos del plan y tipo de negocio. */
export interface NavContext {
    abilities: readonly Ability[];
    /** undefined o null: todavía no se sabe, no se oculta nada por plan. */
    modules?: readonly string[] | null;
    capabilities?: WorkspaceCapabilities | null;
}

export function navContext(workspace: Pick<Workspace, 'abilities' | 'modules' | 'capabilities'>): NavContext {
    return { abilities: workspace.abilities, modules: workspace.modules ?? null, capabilities: workspace.capabilities ?? null };
}

export function itemVisible(item: NavItem, ctx: NavContext): boolean {
    if (item.ability && !ctx.abilities.includes(item.ability)) {
        return false;
    }
    if (item.module && Array.isArray(ctx.modules) && !ctx.modules.includes(item.module)) {
        return false;
    }
    // Solo se oculta cuando el dato existe y dice que no aplica (false); con null se muestra.
    if (item.audience === 'store' && ctx.capabilities?.store === false) {
        return false;
    }
    if (item.audience === 'services' && ctx.capabilities?.services === false) {
        return false;
    }
    return true;
}

/** Los grupos con las entradas que aplican; un grupo sin entradas no se muestra. */
export function visibleNav(ctx: NavContext, groups: readonly NavGroup[] = NAV): NavGroup[] {
    return groups.map((g) => ({ ...g, items: g.items.filter((i) => itemVisible(i, ctx)) })).filter((g) => g.items.length > 0);
}

export function flattenNav(groups: readonly NavGroup[] = NAV): NavItem[] {
    return groups.flatMap((g) => g.items);
}

export function itemPath(base: string, item: Pick<NavItem, 'to'>): string {
    return item.to === '' ? base : `${base}/${item.to}`;
}

/** La entrada de la página actual: la ruta más larga que coincide (en-el-sitio/cotizador gana a en-el-sitio). */
export function activeItem(groups: readonly NavGroup[], pathname: string, base: string): NavItem | null {
    const path = pathname.replace(/\/+$/, '');
    let best: NavItem | null = null;
    for (const item of flattenNav(groups)) {
        const target = itemPath(base, item);
        const matches = item.to === '' ? path === base : path === target || path.startsWith(`${target}/`);
        if (matches && (best === null || item.to.length > best.to.length)) {
            best = item;
        }
    }
    return best;
}

export function activeGroupKey(groups: readonly NavGroup[], pathname: string, base: string): string | null {
    const item = activeItem(groups, pathname, base);
    return item === null ? null : (groups.find((g) => g.items.includes(item))?.key ?? null);
}

/**
 * ¿Se ve abierto el grupo? Los plegados se recuerdan; el grupo de la página actual se abre solo, salvo que la persona
 * lo haya plegado estando en esa misma página.
 */
export function isGroupOpen(key: string, collapsed: readonly string[], activeKey: string | null, closedActiveHere: boolean): boolean {
    if (key === 'home') {
        return true;
    }
    if (key === activeKey) {
        return !closedActiveHere;
    }
    return !collapsed.includes(key);
}
