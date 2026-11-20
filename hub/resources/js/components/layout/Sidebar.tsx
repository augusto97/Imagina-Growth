import { Check, ChevronRight, ChevronsUpDown, LogOut, Moon, PanelLeftClose, PanelLeftOpen, Plus, Search, ShieldCheck, Sun, SunMoon } from 'lucide-react';
import { useMemo, useState, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useLocation, useNavigate } from 'react-router';
import { useQueryClient } from '@tanstack/react-query';
import type { Workspace } from '@/api/types';
import { Badge } from '@/components/ui/badge';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Kbd } from '@/components/ui/kbd';
import { Tooltip } from '@/components/ui/tooltip';
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

/** ¿Teclado de Mac? (⌘ en lugar de Ctrl en las pistas de los atajos). */
export function isMacPlatform(): boolean {
    return typeof navigator !== 'undefined' && /Mac|iPhone|iPad/.test(navigator.platform);
}

/** Contraída, cada control es solo su ícono: el nombre va en un tooltip (al pasar el puntero o al enfocarlo con el teclado). */
function WithTooltip({ collapsed, label, children }: { collapsed: boolean; label: ReactNode; children: ReactNode }) {
    return collapsed ? (
        <Tooltip content={label} side="right">
            {children}
        </Tooltip>
    ) : (
        children
    );
}

/** Botón cuadrado de la barra contraída (centrado en la columna de 56 px). */
const ICON_BUTTON = 'igr:mx-auto igr:flex igr:size-9 igr:items-center igr:justify-center igr:rounded-md';

function WorkspaceSwitcher({ collapsed }: { collapsed: boolean }) {
    const { t } = useTranslation();
    const navigate = useNavigate();
    const { workspace, me } = useWorkspace();
    return (
        <DropdownMenu>
            <WithTooltip collapsed={collapsed} label={workspace.name}>
                <DropdownMenuTrigger
                    className={cn(
                        'igr:hover:bg-accent igr:aria-expanded:bg-accent',
                        collapsed ? ICON_BUTTON : 'igr:flex igr:w-full igr:items-center igr:gap-2 igr:rounded-md igr:px-2 igr:py-1.5 igr:text-left',
                    )}
                >
                    <span className="igr:flex igr:size-6 igr:shrink-0 igr:items-center igr:justify-center igr:rounded-md igr:bg-primary/10 igr:text-[11px] igr:font-semibold igr:text-primary">
                        {workspaceInitials(workspace.name)}
                    </span>
                    <span className={collapsed ? 'igr:sr-only' : 'igr:min-w-0 igr:flex-1'}>
                        <span className="igr:block igr:truncate igr:text-[13px] igr:font-medium">{workspace.name}</span>
                        <span className="igr:block igr:truncate igr:text-[11px] igr:text-muted-foreground">{t(`roles.${workspace.role ?? 'viewer'}`)}</span>
                    </span>
                    {collapsed ? null : <ChevronsUpDown className="igr:size-3.5 igr:text-muted-foreground" aria-hidden />}
                </DropdownMenuTrigger>
            </WithTooltip>
            <DropdownMenuContent align="start" side={collapsed ? 'right' : 'bottom'} className="igr:w-64">
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

function NavEntry({ item, base, active, collapsed }: { item: NavItem; base: string; active: boolean; collapsed: boolean }) {
    const { t } = useTranslation();
    const Icon = item.icon;
    const label = t(`nav.${item.key}`);
    return (
        <WithTooltip collapsed={collapsed} label={label}>
            <Link
                to={itemPath(base, item)}
                aria-current={active ? 'page' : undefined}
                className={cn(
                    'igr:text-[13px] igr:transition-colors',
                    collapsed ? ICON_BUTTON : 'igr:flex igr:items-center igr:gap-2 igr:rounded-md igr:px-2 igr:py-1.5',
                    active ? 'igr:bg-accent igr:font-medium igr:text-foreground' : 'igr:text-muted-foreground igr:hover:bg-accent/60 igr:hover:text-foreground',
                )}
            >
                <Icon className="igr:size-4 igr:shrink-0" aria-hidden />
                <span className={collapsed ? 'igr:sr-only' : 'igr:flex-1 igr:truncate'}>{label}</span>
            </Link>
        </WithTooltip>
    );
}

/**
 * Menú agrupado por tarea (docs/auditoria-2026-11.md U2): grupos plegables que se recuerdan, el de la página actual
 * abierto, y solo las entradas que aplican al plan y al tipo de negocio (nav.ts). Con la barra contraída, todas las
 * entradas como íconos y cada grupo separado por una línea (con su nombre para los lectores de pantalla); los grupos
 * plegados vuelven a respetarse al expandirla.
 */
function NavGroups({ base, iconsOnly }: { base: string; iconsOnly: boolean }) {
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

    if (iconsOnly) {
        return (
            <>
                {groups.map((group, i) => (
                    <div
                        key={group.key}
                        role={group.key === 'home' ? undefined : 'group'}
                        aria-label={group.key === 'home' ? undefined : t(`nav.groups.${group.key}`)}
                        className={cn('igr:space-y-0.5', i > 0 && 'igr:mt-2 igr:border-t igr:pt-2')}
                    >
                        {group.items.map((item) => (
                            <NavEntry key={item.key} item={item} base={base} active={current === item} collapsed />
                        ))}
                    </div>
                ))}
            </>
        );
    }

    return (
        <>
            {groups.map((group) => {
                const open = isGroupOpen(group.key, collapsed, activeKey, closedActiveAt === pathname);
                const items = (
                    <div id={`nav-group-${group.key}`} className="igr:space-y-0.5">
                        {group.items.map((item) => (
                            <NavEntry key={item.key} item={item} base={base} active={current === item} collapsed={false} />
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

function UserMenu({ collapsed }: { collapsed: boolean }) {
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
            <WithTooltip collapsed={collapsed} label={me.user.name}>
                <DropdownMenuTrigger
                    className={cn(
                        'igr:hover:bg-accent igr:aria-expanded:bg-accent',
                        collapsed ? ICON_BUTTON : 'igr:flex igr:w-full igr:items-center igr:gap-2 igr:rounded-md igr:px-2 igr:py-1.5 igr:text-left',
                    )}
                >
                    <span className="igr:flex igr:size-6 igr:shrink-0 igr:items-center igr:justify-center igr:rounded-full igr:bg-secondary igr:text-[11px] igr:font-semibold">
                        {workspaceInitials(me.user.name)}
                    </span>
                    <span className={collapsed ? 'igr:sr-only' : 'igr:min-w-0 igr:flex-1'}>
                        <span className="igr:block igr:truncate igr:text-[13px]">{me.user.name}</span>
                        <span className="igr:block igr:truncate igr:text-[11px] igr:text-muted-foreground">{me.user.email}</span>
                    </span>
                </DropdownMenuTrigger>
            </WithTooltip>
            <DropdownMenuContent align={collapsed ? 'end' : 'start'} side={collapsed ? 'right' : 'top'} className="igr:w-60">
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

/**
 * Barra lateral. `fixed`: la del escritorio (desde `lg`), que se contrae a una columna de íconos con el botón de arriba
 * o ⌘B / Ctrl+B y lo recuerda (`useUi().sidebarCollapsed`). `drawer`: la misma navegación dentro del panel del menú del
 * celular y la tableta (`MobileNav`), siempre expandida.
 */
export function Sidebar({ mode = 'fixed' }: { mode?: 'fixed' | 'drawer' }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const setCommandOpen = useUi((s) => s.setCommandOpen);
    const collapsedPref = useUi((s) => s.sidebarCollapsed);
    const setSidebarCollapsed = useUi((s) => s.setSidebarCollapsed);
    const collapsed = mode === 'fixed' && collapsedPref;
    const base = `/w/${workspace.id}`;
    const isMac = isMacPlatform();
    const mod = isMac ? '⌘' : 'Ctrl+';
    const toggleLabel = collapsed ? t('nav.expandSidebar') : t('nav.collapseSidebar');
    const searchLabel = t('command.search');

    return (
        <aside
            id={mode === 'fixed' ? 'igr-sidebar' : undefined}
            data-collapsed={collapsed ? '' : undefined}
            className={cn('igr:flex igr:h-full igr:shrink-0 igr:flex-col igr:border-r igr:bg-sidebar igr:transition-[width] igr:duration-150', collapsed ? 'igr:w-14' : 'igr:w-60')}
        >
            <div className={cn('igr:flex igr:h-12 igr:shrink-0 igr:items-center', collapsed ? 'igr:justify-center igr:px-2' : 'igr:gap-2 igr:pr-2 igr:pl-4')}>
                {collapsed ? null : <Logo className="igr:min-w-0 igr:flex-1" brand={workspace.branding ?? null} />}
                {mode === 'fixed' ? (
                    <Tooltip content={`${toggleLabel} · ${mod}B`} side="right">
                        <button
                            type="button"
                            onClick={() => setSidebarCollapsed(!collapsed)}
                            aria-label={toggleLabel}
                            aria-expanded={!collapsed}
                            aria-controls="igr-sidebar"
                            aria-keyshortcuts={isMac ? 'Meta+B' : 'Control+B'}
                            className="igr:flex igr:size-8 igr:shrink-0 igr:items-center igr:justify-center igr:rounded-md igr:text-muted-foreground igr:hover:bg-accent igr:hover:text-foreground"
                        >
                            {collapsed ? <PanelLeftOpen className="igr:size-4" aria-hidden /> : <PanelLeftClose className="igr:size-4" aria-hidden />}
                        </button>
                    </Tooltip>
                ) : null}
            </div>
            <div className="igr:px-2">
                <WorkspaceSwitcher collapsed={collapsed} />
                <WithTooltip collapsed={collapsed} label={`${searchLabel} · ${mod}K`}>
                    <button
                        type="button"
                        onClick={() => setCommandOpen(true)}
                        aria-label={collapsed ? searchLabel : undefined}
                        aria-keyshortcuts={isMac ? 'Meta+K' : 'Control+K'}
                        className={cn(
                            'igr:mt-2 igr:text-[13px] igr:text-muted-foreground igr:hover:text-foreground',
                            collapsed
                                ? `${ICON_BUTTON} igr:hover:bg-accent`
                                : 'igr:flex igr:w-full igr:items-center igr:gap-2 igr:rounded-md igr:border igr:bg-background igr:px-2 igr:py-1.5',
                        )}
                    >
                        <Search className="igr:size-4" aria-hidden />
                        {collapsed ? null : (
                            <>
                                <span className="igr:flex-1 igr:text-left">{searchLabel}</span>
                                {/* En el celular no hay teclado: sin la pista del atajo. */}
                                <span className="igr:hidden igr:items-center igr:gap-1 igr:md:flex" aria-hidden>
                                    <Kbd>{isMac ? '⌘' : 'Ctrl'}</Kbd>
                                    <Kbd>K</Kbd>
                                </span>
                            </>
                        )}
                    </button>
                </WithTooltip>
            </div>
            <nav aria-label={t('nav.main')} className="igr:mt-3 igr:flex-1 igr:overflow-y-auto igr:px-2 igr:pb-3">
                <NavGroups base={base} iconsOnly={collapsed} />
            </nav>
            <div className="igr:border-t igr:p-2">
                <UserMenu collapsed={collapsed} />
            </div>
        </aside>
    );
}
