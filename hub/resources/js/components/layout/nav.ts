import {
    BarChart3,
    Building2,
    CalendarDays,
    ClipboardList,
    CreditCard,
    FileText,
    Gift,
    Globe,
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
import type { Ability } from '@/api/types';

export interface NavItem {
    key: string;
    to?: string;            // relativo a /w/:workspaceId; sin `to` = próximamente
    icon: LucideIcon;
    phase?: number;          // fase en la que llega (CLAUDE.md §5)
    ability?: Ability;
    shortcut?: string;       // tecla después de «g»
}

/** Navegación completa de hub/CLAUDE.md §14. Activas las fases 0 a 3. */
export const MAIN_NAV: NavItem[] = [
    { key: 'home', to: '', icon: Home, ability: 'viewDashboards', shortcut: 'h' },
    { key: 'contacts', to: 'contactos', icon: Users, ability: 'viewAssignedContacts', shortcut: 'c' },
    { key: 'segments', to: 'segmentos', icon: Shapes, ability: 'viewAllContacts', shortcut: 'n' },
    { key: 'analytics', to: 'analitica', icon: BarChart3, ability: 'viewDashboards', shortcut: 'a' },
    { key: 'flows', to: 'flujos', icon: Workflow, ability: 'viewDashboards', shortcut: 'f' },
    { key: 'campaigns', to: 'campanas', icon: Megaphone, ability: 'viewDashboards', shortcut: 'p' },
    { key: 'templates', to: 'plantillas', icon: FileText, ability: 'viewDashboards', shortcut: 't' },
    { key: 'inbox', to: 'bandeja', icon: Inbox, ability: 'useInbox', shortcut: 'b' },
    { key: 'leads', to: 'leads', icon: Target, ability: 'useLeads', shortcut: 'd' },
    { key: 'agenda', to: 'agenda', icon: CalendarDays, ability: 'useLeads', shortcut: 'g' },
    { key: 'onsite', to: 'en-el-sitio', icon: MousePointerClick, ability: 'viewDashboards', shortcut: 'w' },
    { key: 'loyalty', to: 'fidelizacion', icon: Gift, ability: 'viewDashboards', shortcut: 'l' },
    { key: 'referrals', to: 'referidos', icon: Share2, ability: 'viewDashboards', shortcut: 'r' },
    { key: 'reviews', to: 'resenas', icon: MessageSquareQuote, ability: 'viewDashboards', shortcut: 'v' },
    { key: 'measurement', to: 'medicion', icon: Ruler, ability: 'viewDashboards', shortcut: 'm' },
    { key: 'insights', to: 'hallazgos', icon: Lightbulb, ability: 'viewDashboards', shortcut: 'i' },
    { key: 'intelligence', to: 'inteligencia', icon: Sparkles, ability: 'viewDashboards', shortcut: 'k' },
];

export const SETTINGS_NAV: NavItem[] = [
    { key: 'sites', to: 'sitios', icon: Plug, ability: 'viewSites', shortcut: 's' },
    { key: 'team', to: 'equipo', icon: UsersRound, ability: 'viewWorkspace', shortcut: 'e' },
    { key: 'workspace', to: 'ajustes', icon: Settings2, ability: 'viewWorkspace' },
    { key: 'channels', to: 'canales', icon: RadioTower, ability: 'viewWorkspace' },
    { key: 'consent', to: 'consentimiento', icon: ShieldCheck, ability: 'viewWorkspace' },
    { key: 'survey', to: 'encuesta', icon: MessageCircleQuestion, ability: 'viewDashboards' },
    { key: 'integrations', to: 'integraciones', icon: Webhook, ability: 'manageApi' },
    { key: 'billing', to: 'facturacion', icon: CreditCard, ability: 'manageBilling' },
    { key: 'audit', to: 'auditoria', icon: ClipboardList, ability: 'viewAuditLog' },
    { key: 'agency', to: 'agencia', icon: Building2, ability: 'viewAgency' },
    { key: 'platform', to: 'plataforma', icon: ServerCog, ability: 'platformAdmin' },
];

export const GLOBE_ICON = Globe;
