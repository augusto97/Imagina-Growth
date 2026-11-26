import {
    BarChart3,
    Bell,
    Bot,
    Building2,
    Calculator,
    CalendarDays,
    CalendarHeart,
    ClipboardList,
    CreditCard,
    FileChartColumn,
    FileText,
    Gift,
    Home,
    Inbox,
    Lightbulb,
    Link2,
    ListChecks,
    type LucideIcon,
    Megaphone,
    MessageCircleQuestion,
    MessageSquareQuote,
    MessagesSquare,
    MousePointerClick,
    Palette,
    PanelTop,
    RadioTower,
    Rocket,
    Send,
    ServerCog,
    Settings2,
    Settings,
    Share2,
    Shapes,
    ShieldCheck,
    Sparkles,
    Store,
    Target,
    TrendingUp,
    Users,
    UsersRound,
    Wand2,
    Webhook,
    Workflow,
    Globe,
    LineChart,
    LayoutTemplate,
    Magnet,
    QrCode,
} from 'lucide-react';
import type { TFunction } from 'i18next';
import type { Ability, PlanModule, Workspace, WorkspaceCapabilities, WorkspaceConnections } from '@/api/types';
import type { IconTone } from '@/components/ui/icon-tile';

/*
 * Navegación del panel por secciones con nombre de resultado (docs/diseno/rediseno-v2.md §3). La usan la barra
 * lateral, el menú del celular (que monta la misma barra), la subnavegación de cada sección (`SectionNav`), las
 * páginas de entrada de cada sección, la miga del `PageHeader`, la paleta ⌘K y los atajos «g x».
 *
 * Las URL de siempre no cambian: una sección agrupa rutas existentes y tiene su página de entrada (`to`), que
 * explica en tarjetas lo que hay dentro. Inicio y Estrategias son una sola página (sin `items`).
 *
 * Para agregar una pantalla:
 *   1. Agrega un NavItem en la sección que corresponda (en el orden en que debe salir en la subnavegación).
 *   2. Agrega su nombre en nav.<key> y su «para qué sirve» en sections.items.<key> (locales/es-CO).
 *   3. Si depende del plan, `module`; si solo aplica a tiendas o a servicios, `audience`; si pide un permiso,
 *      `ability`; si necesita la web o un canal conectado, `requires`. Fuera del plan o sin lo que necesita, la
 *      entrada NO se oculta: lleva un candado y la página muestra `LockedState` («bloqueos que enseñan»).
 *      Solo se ocultan lo que el rol no puede ver y lo que no aplica al tipo de negocio.
 *   4. Si la pantalla vive en otra URL que debe contar como suya (Conexiones absorbe Sitios y Canales), `also`.
 *   `shortcut` es la tecla después de «g» (sin repetir).
 */

/** Tiendas WooCommerce o negocios de servicios (leads, agenda, cotizador). */
export type NavAudience = 'store' | 'services';

/** Lo que una pantalla necesita para servir: la web conectada o un canal de conversación (WhatsApp, Messenger, Instagram). */
export type NavRequirement = 'site' | 'channel';

export interface NavItem {
    /** Nombre en nav.<key> y «para qué sirve» en sections.items.<key>. */
    key: string;
    /** Ruta relativa a /w/:workspaceId. */
    to: string;
    icon: LucideIcon;
    /** Color del mosaico del ícono en la página de entrada de la sección. */
    tone?: IconTone;
    ability?: Ability;
    /** Módulo del plan que la habilita (M21). */
    module?: PlanModule;
    /** Solo para tiendas (se oculta si el sitio no tiene WooCommerce) o solo para servicios. */
    audience?: NavAudience;
    /** Requisitos verificables; sin ellos la página enseña qué falta en lugar de verse vacía. */
    requires?: NavRequirement[];
    /** Otras rutas que pertenecen a esta entrada. */
    also?: string[];
    /**
     * Pantallas hermanas que la entrada agrupa (Conexiones: WhatsApp y anuncios, tu web, canales). Se muestran debajo de
     * la entrada cuando está activa; su nombre va en nav.<key>. Sus rutas deben estar en `to` o en `also`.
     */
    children?: { key: string; to: string }[];
    /** Tecla después de «g». */
    shortcut?: string;
}

export interface NavSection {
    /** Nombre en nav.sections.<key> y «para qué sirve» en sections.<key>.purpose. */
    key: string;
    /** Página de entrada, relativa a /w/:workspaceId ('' = Inicio). */
    to: string;
    icon: LucideIcon;
    tone: IconTone;
    /** Permiso para ver la sección cuando no tiene entradas (Inicio, Estrategias). */
    ability?: Ability;
    /** Subnavegación; vacía = la sección es una sola página. */
    items: NavItem[];
    shortcut?: string;
    /** Agencia y plataforma: van aparte, al final del menú. */
    admin?: boolean;
    /** Solo para quien administra la plataforma (superadmin). */
    platformOnly?: boolean;
}

export const SECTIONS: NavSection[] = [
    { key: 'home', to: '', icon: Home, tone: 'indigo', ability: 'viewDashboards', items: [], shortcut: 'h' },
    { key: 'strategies', to: 'estrategias', icon: Rocket, tone: 'violet', ability: 'viewDashboards', items: [], shortcut: 'x' },
    {
        key: 'customers',
        to: 'clientes',
        icon: Users,
        tone: 'sky',
        items: [
            { key: 'contacts', to: 'contactos', icon: Users, tone: 'sky', ability: 'viewAssignedContacts', shortcut: 'c' },
            { key: 'segments', to: 'segmentos', icon: Shapes, tone: 'violet', ability: 'viewAllContacts', shortcut: 'n' },
            { key: 'today', to: 'hoy', icon: ListChecks, tone: 'amber', ability: 'useLeads', module: 'services', audience: 'services', shortcut: 'y' },
            { key: 'leads', to: 'leads', icon: Target, tone: 'rose', ability: 'useLeads', module: 'services', audience: 'services', shortcut: 'd' },
            { key: 'agenda', to: 'agenda', icon: CalendarDays, tone: 'teal', ability: 'useLeads', module: 'bookings', audience: 'services', shortcut: 'g' },
        ],
    },
    {
        key: 'conversations',
        to: 'conversaciones',
        icon: MessagesSquare,
        tone: 'emerald',
        items: [
            { key: 'inbox', to: 'bandeja', icon: Inbox, tone: 'emerald', ability: 'useInbox', module: 'inbox', requires: ['channel'], shortcut: 'b' },
            { key: 'agent', to: 'bandeja/agente', icon: Bot, tone: 'violet', ability: 'useInbox', module: 'ai_agent', requires: ['channel'] },
        ],
    },
    {
        key: 'messages',
        to: 'mensajes',
        icon: Send,
        tone: 'indigo',
        items: [
            { key: 'flows', to: 'flujos', icon: Workflow, tone: 'indigo', ability: 'viewDashboards', shortcut: 'f' },
            { key: 'campaigns', to: 'campanas', icon: Megaphone, tone: 'orange', ability: 'viewDashboards', shortcut: 'p' },
            { key: 'calendar', to: 'campanas/calendario', icon: CalendarHeart, tone: 'rose', ability: 'viewDashboards' },
            { key: 'templates', to: 'plantillas', icon: FileText, tone: 'sky', ability: 'viewDashboards', also: ['canales/plantillas-whatsapp'], shortcut: 't' },
            { key: 'copilot', to: 'copiloto', icon: Wand2, tone: 'violet', ability: 'viewDashboards', module: 'ai' },
        ],
    },
    {
        key: 'onsite',
        to: 'en-tu-web',
        icon: Globe,
        tone: 'teal',
        items: [
            { key: 'onsite', to: 'en-el-sitio', icon: MousePointerClick, tone: 'teal', ability: 'viewDashboards', module: 'onsite', requires: ['site'], shortcut: 'w' },
            // Captación sin web (rediseño v2 §11.3): páginas de captura, QR de WhatsApp y leads de anuncios no necesitan la web conectada.
            { key: 'capturePages', to: 'paginas-de-captura', icon: LayoutTemplate, tone: 'violet', ability: 'viewDashboards', module: 'onsite' },
            { key: 'whatsappLinks', to: 'whatsapp-qr', icon: QrCode, tone: 'emerald', ability: 'viewDashboards', module: 'onsite' },
            { key: 'leadAds', to: 'leads-de-anuncios', icon: Magnet, tone: 'sky', ability: 'viewDashboards', module: 'services', audience: 'services' },
            { key: 'reviews', to: 'resenas', icon: MessageSquareQuote, tone: 'amber', ability: 'viewDashboards', module: 'reviews', requires: ['site'], shortcut: 'v' },
            { key: 'loyalty', to: 'fidelizacion', icon: Gift, tone: 'rose', ability: 'viewDashboards', module: 'loyalty', audience: 'store', requires: ['site'], shortcut: 'l' },
            {
                key: 'referrals',
                to: 'referidos',
                icon: Share2,
                tone: 'violet',
                ability: 'viewDashboards',
                module: 'referrals',
                audience: 'store',
                requires: ['site'],
                shortcut: 'r',
            },
            { key: 'recommendations', to: 'en-el-sitio/recomendaciones', icon: Store, tone: 'indigo', ability: 'viewDashboards', audience: 'store', requires: ['site'] },
            { key: 'watches', to: 'en-el-sitio/avisos', icon: Bell, tone: 'orange', ability: 'viewDashboards', audience: 'store', requires: ['site'] },
            { key: 'banners', to: 'en-el-sitio/banners', icon: PanelTop, tone: 'sky', ability: 'viewDashboards', audience: 'store', requires: ['site'] },
            {
                key: 'quotes',
                to: 'en-el-sitio/cotizador',
                icon: Calculator,
                tone: 'emerald',
                ability: 'viewDashboards',
                module: 'quotes',
                audience: 'services',
                requires: ['site'],
                shortcut: 'q',
            },
        ],
    },
    {
        key: 'results',
        to: 'resultados',
        icon: TrendingUp,
        tone: 'emerald',
        items: [
            { key: 'analytics', to: 'analitica', icon: BarChart3, tone: 'indigo', ability: 'viewDashboards', requires: ['site'], shortcut: 'a' },
            { key: 'reports', to: 'reportes', icon: FileChartColumn, tone: 'emerald', ability: 'viewDashboards', requires: ['site'], also: ['inteligencia/informes'], shortcut: 'o' },
            { key: 'insights', to: 'hallazgos', icon: Lightbulb, tone: 'amber', ability: 'viewDashboards', requires: ['site'], shortcut: 'i' },
            // Predicciones de compra (próxima compra, abandono, valor futuro) y la encuesta de la página de gracias: solo tiendas.
            { key: 'predictions', to: 'inteligencia/predicciones', icon: LineChart, tone: 'violet', ability: 'viewDashboards', audience: 'store', requires: ['site'] },
            { key: 'intelligence', to: 'inteligencia', icon: Sparkles, tone: 'indigo', ability: 'viewDashboards', requires: ['site'], shortcut: 'k' },
            { key: 'measurement', to: 'medicion', icon: RadioTower, tone: 'sky', ability: 'viewDashboards', requires: ['site'], shortcut: 'm' },
            { key: 'survey', to: 'encuesta', icon: MessageCircleQuestion, tone: 'rose', ability: 'viewDashboards', audience: 'store', requires: ['site'] },
        ],
    },
    {
        key: 'settings',
        to: 'configuracion',
        icon: Settings,
        tone: 'slate',
        items: [
            {
                key: 'connections',
                to: 'conexiones',
                icon: Link2,
                tone: 'indigo',
                ability: 'viewWorkspace',
                also: ['sitios', 'canales'],
                children: [
                    { key: 'connectionsAccounts', to: 'conexiones' },
                    { key: 'sites', to: 'sitios' },
                    { key: 'channels', to: 'canales' },
                ],
                shortcut: 's',
            },
            { key: 'team', to: 'equipo', icon: UsersRound, tone: 'sky', ability: 'viewWorkspace', shortcut: 'e' },
            { key: 'workspace', to: 'ajustes', icon: Settings2, tone: 'slate', ability: 'viewWorkspace' },
            { key: 'consent', to: 'consentimiento', icon: ShieldCheck, tone: 'emerald', ability: 'viewWorkspace' },
            { key: 'billing', to: 'facturacion', icon: CreditCard, tone: 'violet', ability: 'manageBilling' },
            { key: 'integrations', to: 'integraciones', icon: Webhook, tone: 'orange', ability: 'manageApi' },
            { key: 'audit', to: 'auditoria', icon: ClipboardList, tone: 'slate', ability: 'viewAuditLog' },
        ],
    },
    { key: 'agency', to: 'agencia', icon: Building2, tone: 'slate', ability: 'viewAgency', items: [], admin: true },
    { key: 'platform', to: 'plataforma', icon: ServerCog, tone: 'slate', ability: 'platformAdmin', items: [], admin: true },
    { key: 'styleguide', to: 'guia-de-estilo', icon: Palette, tone: 'violet', ability: 'platformAdmin', items: [], admin: true, platformOnly: true },
];

/** Lo que decide qué se ve y qué lleva candado: permisos del rol, plan, tipo de negocio y conexiones. */
export interface NavContext {
    abilities: readonly Ability[];
    /** undefined o null: todavía no se sabe, nada lleva candado por plan. */
    modules?: readonly string[] | null;
    capabilities?: WorkspaceCapabilities | null;
    /** undefined o null: no se sabe, nada lleva candado por conexión. */
    connections?: WorkspaceConnections | null;
    upgradePlans?: Partial<Record<PlanModule, string>> | null;
    /** Tienda, servicios o ambos; null o undefined: se decide con `capabilities`. */
    businessType?: Workspace['business_type'];
    /** A qué se dedica (`store`, `health`…): cambia algunas palabras («Pacientes» en salud). */
    vertical?: string | null;
}

export function navContext(workspace: Pick<Workspace, 'abilities' | 'modules' | 'capabilities' | 'connections' | 'upgrade_plans' | 'business_type' | 'vertical'>): NavContext {
    return {
        abilities: workspace.abilities,
        modules: workspace.modules ?? null,
        capabilities: workspace.capabilities ?? null,
        connections: workspace.connections ?? null,
        upgradePlans: workspace.upgrade_plans ?? null,
        businessType: workspace.business_type ?? null,
        vertical: workspace.vertical ?? null,
    };
}

/** El nombre de una sección con la palabra de la vertical cuando la tiene («Pacientes» en salud: nav.sections.customers_health). */
export function sectionName(t: TFunction, key: string, vertical?: string | null): string {
    return t(`nav.sections.${key}`, { context: vertical ?? undefined });
}

/** El «para qué sirve» de una sección, también con la palabra de la vertical. */
export function sectionPurpose(t: TFunction, key: string, vertical?: string | null): string {
    return t(`sections.${key}.purpose`, { context: vertical ?? undefined, defaultValue: '' });
}

/** ¿Aplica al rol y al tipo de negocio? (lo que no aplica se oculta; lo que falta lleva candado). */
export function itemVisible(item: Pick<NavItem, 'ability' | 'audience'>, ctx: NavContext): boolean {
    if (item.ability && !ctx.abilities.includes(item.ability)) {
        return false;
    }
    // El tipo de negocio (asistente, vertical o sitios) decide; sin él, las capacidades. Solo se oculta cuando el dato
    // existe y dice que no aplica: un negocio sin tienda no ve carritos, pedidos ni catálogo; con null se muestra todo.
    const type = ctx.businessType ?? null;
    if (item.audience === 'store' && (type === 'services' || (type === null && ctx.capabilities?.store === false))) {
        return false;
    }
    if (item.audience === 'services' && (type === 'store' || (type === null && ctx.capabilities?.services === false))) {
        return false;
    }
    return true;
}

/** Por qué una pantalla no puede servir todavía: fuera del plan, sin la web conectada o sin un canal de conversación. */
export type NavLock = { kind: 'plan'; module: PlanModule; plan: string | null } | { kind: 'site'; state: 'none' | 'pending' } | { kind: 'channel' };

/** Canales que conversan en la bandeja (el correo y el SMS no). */
export const CONVERSATION_CHANNELS = ['whatsapp', 'messenger', 'instagram'] as const;

export function hasConversationChannel(connections: WorkspaceConnections): boolean {
    return connections.channels.some((c) => (CONVERSATION_CHANNELS as readonly string[]).includes(c));
}

/** El primer bloqueo que aplica, en el orden en que conviene resolverlos (plan, web, canal), o null si está lista. */
export function itemLock(item: Pick<NavItem, 'module' | 'requires'>, ctx: NavContext): NavLock | null {
    if (item.module && Array.isArray(ctx.modules) && !ctx.modules.includes(item.module)) {
        return { kind: 'plan', module: item.module, plan: ctx.upgradePlans?.[item.module] ?? null };
    }
    const connections = ctx.connections;
    if (!connections) {
        return null;
    }
    // Una web que se desconectó conserva sus datos: no se bloquea (el aviso de salud del sitio explica qué hacer).
    if (item.requires?.includes('site') && (connections.site === 'none' || connections.site === 'pending')) {
        return { kind: 'site', state: connections.site };
    }
    if (item.requires?.includes('channel') && !hasConversationChannel(connections)) {
        return { kind: 'channel' };
    }
    return null;
}

/** Las secciones con las entradas que aplican; una sección sin entradas visibles (y que las tenía) no se muestra. */
export function visibleSections(ctx: NavContext, isPlatformAdmin = false, sections: readonly NavSection[] = SECTIONS): NavSection[] {
    return sections
        .filter((s) => !s.platformOnly || isPlatformAdmin)
        .flatMap((s) => {
            if (s.items.length === 0) {
                return !s.ability || ctx.abilities.includes(s.ability) ? [s] : [];
            }
            const items = s.items.filter((i) => itemVisible(i, ctx));
            return items.length > 0 ? [{ ...s, items }] : [];
        });
}

/** Un bloqueo común a toda la sección (todas sus entradas lo tienen), para la marca del menú y el aviso de la entrada. */
export function sectionLock(section: NavSection, ctx: NavContext): NavLock | null {
    if (section.items.length === 0) {
        return null;
    }
    const locks = section.items.map((i) => itemLock(i, ctx));
    const first = locks[0];
    if (!first || locks.some((l) => l === null || l.kind !== first.kind)) {
        return null;
    }
    return first;
}

export function sectionPath(base: string, section: Pick<NavSection, 'to'>): string {
    return section.to === '' ? base : `${base}/${section.to}`;
}

export function itemPath(base: string, item: Pick<NavItem, 'to'>): string {
    return item.to === '' ? base : `${base}/${item.to}`;
}

/** Todas las entradas (para ⌘K y los atajos), con las secciones de una sola página como entradas. */
export function flattenNav(sections: readonly NavSection[] = SECTIONS): (NavItem | NavSection)[] {
    return sections.flatMap((s) => (s.items.length === 0 ? [s] : s.items));
}

/** La pantalla hermana activa de una entrada con `children` (la ruta más larga que coincide), o null. */
export function activeChild(item: Pick<NavItem, 'children'>, pathname: string, base: string): string | null {
    const path = pathname.replace(/\/+$/, '');
    let best: { key: string; length: number } | null = null;
    for (const child of item.children ?? []) {
        const target = `${base}/${child.to}`;
        if ((path === target || path.startsWith(`${target}/`)) && (best === null || child.to.length > best.length)) {
            best = { key: child.key, length: child.to.length };
        }
    }
    return best?.key ?? null;
}

/** Dónde está la persona: la sección, la entrada de la subnavegación y si es la página de entrada o un detalle. */
export interface NavLocation {
    section: NavSection;
    /** null en la página de entrada de la sección o en una sección de una sola página. */
    item: NavItem | null;
    /** La página de entrada de la sección (o la sección de una sola página). */
    isEntry: boolean;
    /** Una pantalla dentro de la entrada (un contacto, un flujo, «importar»…). */
    isDetail: boolean;
}

function matchPath(path: string, target: string): 'exact' | 'inside' | null {
    if (path === target) {
        return 'exact';
    }
    return path.startsWith(`${target}/`) ? 'inside' : null;
}

/** La entrada más específica que coincide (en-el-sitio/cotizador gana a en-el-sitio; canales/plantillas-whatsapp a canales). */
export function locate(sections: readonly NavSection[], pathname: string, base: string): NavLocation | null {
    const path = pathname.replace(/\/+$/, '');
    const candidates: { section: NavSection; item: NavItem | null; to: string; allowInside: boolean }[] = sections.flatMap((section) => [
        { section, item: null, to: section.to, allowInside: section.items.length === 0 },
        ...section.items.flatMap((item) => [item.to, ...(item.also ?? [])].map((to) => ({ section, item, to, allowInside: true }))),
    ]);
    let best: { section: NavSection; item: NavItem | null; length: number; exact: boolean } | undefined;
    for (const c of candidates) {
        const match = c.to === '' ? (path === base ? 'exact' : null) : matchPath(path, `${base}/${c.to}`);
        if (match === null || (match === 'inside' && !c.allowInside)) {
            continue;
        }
        if (best === undefined || c.to.length > best.length) {
            best = { section: c.section, item: c.item, length: c.to.length, exact: match === 'exact' };
        }
    }
    if (best === undefined) {
        return null;
    }
    return { section: best.section, item: best.item, isEntry: best.item === null, isDetail: best.item !== null && !best.exact };
}
