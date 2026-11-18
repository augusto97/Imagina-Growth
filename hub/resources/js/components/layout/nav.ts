import {
    BarChart3,
    Building2,
    ClipboardList,
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
    Settings2,
    Share2,
    Shapes,
    ShieldCheck,
    Users,
    UsersRound,
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

/** Navegación completa de hub/CLAUDE.md §14. Activas las fases 0 a 2. */
export const MAIN_NAV: NavItem[] = [
    { key: 'home', to: '', icon: Home, ability: 'viewDashboards', shortcut: 'h' },
    { key: 'contacts', to: 'contactos', icon: Users, ability: 'viewAssignedContacts', shortcut: 'c' },
    { key: 'segments', to: 'segmentos', icon: Shapes, ability: 'viewAllContacts', shortcut: 'n' },
    { key: 'analytics', to: 'analitica', icon: BarChart3, ability: 'viewDashboards', shortcut: 'a' },
    { key: 'flows', to: 'flujos', icon: Workflow, ability: 'viewDashboards', shortcut: 'f' },
    { key: 'campaigns', to: 'campanas', icon: Megaphone, ability: 'viewDashboards', shortcut: 'p' },
    { key: 'templates', to: 'plantillas', icon: FileText, ability: 'viewDashboards', shortcut: 't' },
    { key: 'inbox', icon: Inbox, phase: 4 },
    { key: 'onsite', icon: MousePointerClick, phase: 3 },
    { key: 'loyalty', icon: Gift, phase: 3 },
    { key: 'referrals', icon: Share2, phase: 3 },
    { key: 'reviews', icon: MessageSquareQuote, phase: 3 },
    { key: 'measurement', to: 'medicion', icon: Ruler, ability: 'viewDashboards', shortcut: 'm' },
    { key: 'insights', to: 'hallazgos', icon: Lightbulb, ability: 'viewDashboards', shortcut: 'i' },
];

export const SETTINGS_NAV: NavItem[] = [
    { key: 'sites', to: 'sitios', icon: Plug, ability: 'viewSites', shortcut: 's' },
    { key: 'team', to: 'equipo', icon: UsersRound, ability: 'viewWorkspace', shortcut: 'e' },
    { key: 'workspace', to: 'ajustes', icon: Settings2, ability: 'viewWorkspace' },
    { key: 'channels', to: 'canales', icon: RadioTower, ability: 'viewWorkspace' },
    { key: 'consent', to: 'consentimiento', icon: ShieldCheck, ability: 'viewWorkspace' },
    { key: 'survey', to: 'encuesta', icon: MessageCircleQuestion, ability: 'viewDashboards' },
    { key: 'audit', to: 'auditoria', icon: ClipboardList, ability: 'viewAuditLog' },
    { key: 'agency', icon: Building2, phase: 5 },
];

export const GLOBE_ICON = Globe;
