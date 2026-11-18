import {
    BarChart3,
    Building2,
    ClipboardList,
    Gift,
    Globe,
    Home,
    Inbox,
    Lightbulb,
    type LucideIcon,
    Megaphone,
    MessageSquareQuote,
    MousePointerClick,
    Plug,
    Ruler,
    Settings2,
    Share2,
    Shapes,
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

/** Navegación completa de hub/CLAUDE.md §14. Solo la Fase 0 está activa. */
export const MAIN_NAV: NavItem[] = [
    { key: 'home', to: '', icon: Home, ability: 'viewDashboards', shortcut: 'h' },
    { key: 'contacts', to: 'contactos', icon: Users, ability: 'viewAssignedContacts', shortcut: 'c' },
    { key: 'segments', icon: Shapes, phase: 1 },
    { key: 'analytics', icon: BarChart3, phase: 1 },
    { key: 'flows', icon: Workflow, phase: 2 },
    { key: 'campaigns', icon: Megaphone, phase: 2 },
    { key: 'inbox', icon: Inbox, phase: 4 },
    { key: 'onsite', icon: MousePointerClick, phase: 3 },
    { key: 'loyalty', icon: Gift, phase: 3 },
    { key: 'referrals', icon: Share2, phase: 3 },
    { key: 'reviews', icon: MessageSquareQuote, phase: 3 },
    { key: 'measurement', icon: Ruler, phase: 1 },
    { key: 'insights', icon: Lightbulb, phase: 1 },
];

export const SETTINGS_NAV: NavItem[] = [
    { key: 'sites', to: 'sitios', icon: Plug, ability: 'viewSites', shortcut: 's' },
    { key: 'team', to: 'equipo', icon: UsersRound, ability: 'viewWorkspace', shortcut: 'e' },
    { key: 'workspace', to: 'ajustes', icon: Settings2, ability: 'viewWorkspace' },
    { key: 'audit', to: 'auditoria', icon: ClipboardList, ability: 'viewAuditLog' },
    { key: 'agency', icon: Building2, phase: 5 },
];

export const GLOBE_ICON = Globe;
