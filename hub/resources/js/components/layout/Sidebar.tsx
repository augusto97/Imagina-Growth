import { Check, ChevronRight, ChevronsUpDown, LogOut, Moon, Plus, Search, ShieldCheck, Sun, SunMoon } from 'lucide-react';
import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useLocation, useNavigate } from 'react-router';
import { useQueryClient } from '@tanstack/react-query';
import type { Workspace } from '@/api/types';
import { Badge } from '@/components/ui/badge';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Kbd } from '@/components/ui/kbd';
import { useWorkspace } from '@/hooks/useWorkspace';
import { api } from '@/lib/api';
import { cn } from '@/lib/cn';
import { useUi, type ThemePreference } from '@/stores/ui';
import { Logo } from './Logo';
import { activeItem, isGroupOpen, itemPath, navContext, visibleNav, type NavGroup, type NavItem } from './nav';

export function workspaceInitials(name: string): string {
    return name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((w) => w[0]?.toUpperCase() ?? '')
        .join('');
}

function WorkspaceSwitcher() {
    const { t } = useTranslation();
    const navigate = useNavigate();
    const { workspace, me } = useWorkspace();
    return (
        <DropdownMenu>
            <DropdownMenuTrigger className="igr:flex igr:w-full igr:items-center igr:gap-2 igr:rounded-md igr:px-2 igr:py-1.5 igr:text-left igr:hover:bg-accent igr:data-[state=open]:bg-accent">
                <span className="igr:flex igr:size-6 igr:shrink-0 igr:items-center igr:justify-center igr:rounded-md igr:bg-primary/10 igr:text-[11px] igr:font-semibold igr:text-primary">
                    {workspaceInitials(workspace.name)}
                </span>
                <span className="igr:min-w-0 igr:flex-1">
                    <span className="igr:block igr:truncate igr:text-[13px] igr:font-medium">{workspace.name}</span>
                    <span className="igr:block igr:truncate igr:text-[11px] igr:text-muted-foreground">{t(`roles.${workspace.role ?? 'viewer'}`)}</span>
                </span>
                <ChevronsUpDown className="igr:size-3.5 igr:text-muted-foreground" />
            </DropdownMenuTrigger>
            <DropdownMenuContent align="start" className="igr:w-64">
                <DropdownMenuLabel>{t('nav.workspaces')}</DropdownMenuLabel>
                {me.workspaces.map((ws: Workspace) => (
                    <DropdownMenuItem key={ws.id} onSelect={() => void navigate(`/w/${ws.id}`)}>
                        <span className="igr:flex igr:size-5 igr:items-center igr:justify-center igr:rounded igr:bg-muted igr:text-[10px] igr:font-semibold">
                            {workspaceInitials(ws.name)}
                        </span>
                        <span className="igr:flex-1 igr:truncate">{ws.name}</span>
                        {ws.id === workspace.id ? <Check /> : null}
                    </DropdownMenuItem>
                ))}
                <DropdownMenuSeparator />
                <DropdownMenuItem onSelect={() => void navigate('/onboarding')}>
                    <Plus />
                    {t('nav.newWorkspace')}
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

function NavEntry({ item, base, active }: { item: NavItem; base: string; active: boolean }) {
    const { t } = useTranslation();
    const Icon = item.icon;
    return (
        <Link
            to={itemPath(base, item)}
            aria-current={active ? 'page' : undefined}
            className={cn(
                'igr:flex igr:items-center igr:gap-2 igr:rounded-md igr:px-2 igr:py-1.5 igr:text-[13px] igr:transition-colors',
                active ? 'igr:bg-accent igr:font-medium igr:text-foreground' : 'igr:text-muted-foreground igr:hover:bg-accent/60 igr:hover:text-foreground',
            )}
        >
            <Icon className="igr:size-4" />
            <span className="igr:flex-1 igr:truncate">{t(`nav.${item.key}`)}</span>
        </Link>
    );
}

/**
 * Menú agrupado por tarea (docs/auditoria-2026-11.md U2): grupos plegables que se recuerdan, el de la página actual
 * abierto, y solo las entradas que aplican al plan y al tipo de negocio (nav.ts).
 */
function NavGroups({ base }: { base: string }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const { pathname } = useLocation();
    const collapsed = useUi((s) => s.navCollapsed);
    const setCollapsed = useUi((s) => s.setNavGroupCollapsed);
    // Plegar el grupo de la página actual vale mientras se siga en esa página.
    const [closedActiveAt, setClosedActiveAt] = useState<string | null>(null);
    const groups = useMemo(() => visibleNav(navContext(workspace)), [workspace]);
    const current = activeItem(groups, pathname, base);
    const activeKey = current === null ? null : (groups.find((g) => g.items.includes(current))?.key ?? null);

    const toggle = (group: NavGroup, open: boolean) => {
        if (group.key === activeKey) {
            setClosedActiveAt(open ? pathname : null);
        }
        setCollapsed(group.key, open);
    };

    return (
        <>
            {groups.map((group) => {
                const open = isGroupOpen(group.key, collapsed, activeKey, closedActiveAt === pathname);
                const items = (
                    <div id={`nav-group-${group.key}`} className="igr:space-y-0.5">
                        {group.items.map((item) => (
                            <NavEntry key={item.key} item={item} base={base} active={current === item} />
                        ))}
                    </div>
                );
                if (group.key === 'home') {
                    return <div key={group.key}>{items}</div>;
                }
                return (
                    <div key={group.key} className="igr:pt-3">
                        <button
                            type="button"
                            aria-expanded={open}
                            aria-controls={`nav-group-${group.key}`}
                            onClick={() => toggle(group, open)}
                            className="igr:group igr:flex igr:w-full igr:items-center igr:gap-1 igr:rounded-md igr:px-2 igr:py-1 igr:text-[11px] igr:font-medium igr:uppercase igr:tracking-wide igr:text-muted-foreground igr:hover:text-foreground"
                        >
                            <span className="igr:flex-1 igr:text-left">{t(`nav.groups.${group.key}`)}</span>
                            {!open && group.items.some((i) => i === current) ? <span className="igr:size-1.5 igr:rounded-full igr:bg-primary" aria-hidden /> : null}
                            <ChevronRight className={cn('igr:size-3.5 igr:transition-transform', open && 'igr:rotate-90')} aria-hidden />
                        </button>
                        {open ? <div className="igr:mt-0.5">{items}</div> : null}
                    </div>
                );
            })}
        </>
    );
}

const THEMES: { value: ThemePreference; icon: typeof Sun }[] = [
    { value: 'light', icon: Sun },
    { value: 'dark', icon: Moon },
    { value: 'system', icon: SunMoon },
];

function UserMenu() {
    const { t } = useTranslation();
    const navigate = useNavigate();
    const qc = useQueryClient();
    const { me } = useWorkspace();
    const { theme, setTheme } = useUi();

    const logout = async () => {
        try {
            await api.post('/auth/logout');
        } finally {
            qc.clear();
            await navigate('/login', { replace: true });
        }
    };

    return (
        <DropdownMenu>
            <DropdownMenuTrigger className="igr:flex igr:w-full igr:items-center igr:gap-2 igr:rounded-md igr:px-2 igr:py-1.5 igr:text-left igr:hover:bg-accent igr:data-[state=open]:bg-accent">
                <span className="igr:flex igr:size-6 igr:items-center igr:justify-center igr:rounded-full igr:bg-secondary igr:text-[11px] igr:font-semibold">
                    {workspaceInitials(me.user.name)}
                </span>
                <span className="igr:min-w-0 igr:flex-1">
                    <span className="igr:block igr:truncate igr:text-[13px]">{me.user.name}</span>
                    <span className="igr:block igr:truncate igr:text-[11px] igr:text-muted-foreground">{me.user.email}</span>
                </span>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="start" side="top" className="igr:w-60">
                <DropdownMenuItem onSelect={() => void navigate('/seguridad')}>
                    <ShieldCheck />
                    <span className="igr:flex-1">{t('nav.security')}</span>
                    {me.user.two_factor_enabled ? <Badge variant="success">{t('security.on')}</Badge> : <Badge variant="warning">{t('security.off')}</Badge>}
                </DropdownMenuItem>
                <DropdownMenuSeparator />
                <DropdownMenuLabel>{t('theme.title')}</DropdownMenuLabel>
                {THEMES.map(({ value, icon: Icon }) => (
                    <DropdownMenuItem key={value} onSelect={() => setTheme(value)}>
                        <Icon />
                        <span className="igr:flex-1">{t(`theme.${value}`)}</span>
                        {theme === value ? <Check /> : null}
                    </DropdownMenuItem>
                ))}
                <DropdownMenuSeparator />
                <DropdownMenuItem onSelect={() => void logout()}>
                    <LogOut />
                    {t('auth.logout')}
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

export function Sidebar() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const setCommandOpen = useUi((s) => s.setCommandOpen);
    const base = `/w/${workspace.id}`;
    const isMac = typeof navigator !== 'undefined' && /Mac|iPhone|iPad/.test(navigator.platform);

    return (
        <aside className="igr:flex igr:h-full igr:w-60 igr:shrink-0 igr:flex-col igr:border-r igr:bg-sidebar">
            <div className="igr:flex igr:h-12 igr:items-center igr:px-4">
                <Logo brand={workspace.branding ?? null} />
            </div>
            <div className="igr:px-2">
                <WorkspaceSwitcher />
                <button
                    type="button"
                    onClick={() => setCommandOpen(true)}
                    className="igr:mt-2 igr:flex igr:w-full igr:items-center igr:gap-2 igr:rounded-md igr:border igr:bg-background igr:px-2 igr:py-1.5 igr:text-[13px] igr:text-muted-foreground igr:hover:text-foreground"
                >
                    <Search className="igr:size-4" />
                    <span className="igr:flex-1 igr:text-left">{t('command.search')}</span>
                    {/* En el celular no hay teclado: sin la pista del atajo. */}
                    <span className="igr:hidden igr:items-center igr:gap-1 igr:md:flex">
                        <Kbd>{isMac ? '⌘' : 'Ctrl'}</Kbd>
                        <Kbd>K</Kbd>
                    </span>
                </button>
            </div>
            <nav aria-label={t('nav.main')} className="igr:mt-3 igr:flex-1 igr:overflow-y-auto igr:px-2 igr:pb-3">
                <NavGroups base={base} />
            </nav>
            <div className="igr:border-t igr:p-2">
                <UserMenu />
            </div>
        </aside>
    );
}
