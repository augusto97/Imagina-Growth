import { Check, ChevronsUpDown, Lock, LogOut, Moon, PanelLeftClose, PanelLeftOpen, Plus, Search, ShieldCheck, Sparkles, Sun, SunMoon, Wand2 } from 'lucide-react';
import { useMemo, type ReactNode } from 'react';
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
import { useIntentPrefetch } from '@/app/prefetch';
import { preloadCommandPalette } from './commandPaletteLoader';
import { Logo } from './Logo';
import { locate, navContext, sectionLock, sectionName, sectionPath, visibleSections, type NavLock, type NavSection } from './nav';
import { lockLabelKey, SectionNavTree } from './SectionNav';

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
const ICON_BUTTON = 'igr:mx-auto igr:flex igr:size-9 igr:items-center igr:justify-center igr:rounded-lg';

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
                        collapsed
                            ? ICON_BUTTON
                            : 'igr:flex igr:w-full igr:items-center igr:gap-2.5 igr:rounded-lg igr:border igr:bg-card igr:px-2 igr:py-1.5 igr:text-left igr:shadow-xs',
                    )}
                >
                    <span className="igr:brand-gradient igr:flex igr:size-7 igr:shrink-0 igr:items-center igr:justify-center igr:rounded-lg igr:text-[11px] igr:font-semibold igr:text-primary-foreground igr:shadow-xs">
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

function NavEntry({
    section,
    base,
    state,
    collapsed,
    lock,
    vertical,
}: {
    section: NavSection;
    base: string;
    state: 'page' | 'inside' | null;
    collapsed: boolean;
    lock: NavLock | null;
    vertical: string | null;
}) {
    const { t } = useTranslation();
    const Icon = section.icon;
    const label = sectionName(t, section.key, vertical);
    const lockText = lock ? t(lockLabelKey(lock)) : null;
    const intent = useIntentPrefetch();
    // Contraída no se ve la subnavegación: la sección se marca entera aunque la persona esté en una de sus pantallas.
    const current = state === 'page' || (collapsed && state === 'inside');
    return (
        <WithTooltip collapsed={collapsed} label={lockText ? `${label} · ${lockText}` : label}>
            <Link
                to={sectionPath(base, section)}
                {...intent(sectionPath(base, section))}
                aria-current={current ? 'page' : undefined}
                className={cn(
                    'igr:group igr:relative igr:text-[13.5px] igr:transition-colors',
                    collapsed ? ICON_BUTTON : 'igr:flex igr:h-9 igr:items-center igr:gap-2.5 igr:rounded-lg igr:px-2.5',
                    current
                        ? 'igr:bg-card igr:font-medium igr:text-foreground igr:shadow-xs igr:ring-1 igr:ring-border'
                        : state === 'inside'
                          ? 'igr:font-medium igr:text-foreground igr:hover:bg-accent'
                          : 'igr:text-muted-foreground igr:hover:bg-accent igr:hover:text-foreground',
                )}
            >
                <Icon className={cn('igr:size-[18px] igr:shrink-0', state ? 'igr:text-primary' : 'igr:text-muted-foreground igr:group-hover:text-foreground')} aria-hidden />
                <span className={collapsed ? 'igr:sr-only' : 'igr:min-w-0 igr:flex-1 igr:truncate'}>{label}</span>
                {lock ? (
                    collapsed ? (
                        <span aria-hidden className="igr:absolute igr:top-1.5 igr:right-1.5 igr:size-1.5 igr:rounded-full igr:bg-muted-foreground/60" />
                    ) : (
                        <Lock className="igr:size-3 igr:shrink-0 igr:text-muted-foreground/80" aria-hidden />
                    )
                ) : null}
                {lockText ? <span className="igr:sr-only">{` (${lockText})`}</span> : null}
            </Link>
        </WithTooltip>
    );
}

/**
 * El menú principal (rediseño v2 §3): las secciones con nombre de resultado y, aparte, Agencia y Plataforma para quien
 * tiene ese permiso. Cada sección abre su página de entrada; la sección actual se despliega con su subnavegación
 * (`SectionNavTree`). Una sección que todavía no puede servir (sin web, sin canal, fuera del plan) lleva un candado.
 */
function NavSections({ base, iconsOnly }: { base: string; iconsOnly: boolean }) {
    const { t } = useTranslation();
    const { workspace, me } = useWorkspace();
    const { pathname } = useLocation();
    const ctx = useMemo(() => navContext(workspace), [workspace]);
    const sections = useMemo(() => visibleSections(ctx, me.user.is_platform_admin), [ctx, me.user.is_platform_admin]);
    const location = locate(sections, pathname, base);
    const main = sections.filter((s) => !s.admin);
    const admin = sections.filter((s) => s.admin);
    const stateOf = (section: NavSection) => (location?.section.key !== section.key ? null : location.isEntry ? 'page' : 'inside');

    const render = (section: NavSection, lock: NavLock | null) => (
        <li key={section.key}>
            <NavEntry section={section} base={base} state={stateOf(section)} collapsed={iconsOnly} lock={lock} vertical={ctx.vertical ?? null} />
            {!iconsOnly && location && location.section.key === section.key && section.items.length > 0 ? (
                <SectionNavTree section={section} location={location} base={base} ctx={ctx} />
            ) : null}
        </li>
    );

    return (
        <>
            <ul className="igr:space-y-0.5">{main.map((section) => render(section, sectionLock(section, ctx)))}</ul>
            {admin.length > 0 ? (
                <div role="group" aria-label={t('nav.groups.admin')} className={cn('igr:mt-4', iconsOnly && 'igr:border-t igr:pt-3')}>
                    {iconsOnly ? null : (
                        <p aria-hidden className="igr:px-2.5 igr:pb-1 igr:text-caption igr:font-semibold igr:tracking-wide igr:text-muted-foreground/80 igr:uppercase">
                            {t('nav.groups.admin')}
                        </p>
                    )}
                    <ul className="igr:space-y-0.5">{admin.map((section) => render(section, null))}</ul>
                </div>
            ) : null}
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
    const { me, workspace } = useWorkspace();
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
                {/* Primera vez (rediseño v2 §4): volver a ver la bienvenida y retomar el asistente. */}
                <DropdownMenuItem onSelect={() => void navigate(`/w/${workspace.id}/bienvenida`)}>
                    <Sparkles />
                    {t('nav.welcome')}
                </DropdownMenuItem>
                {workspace.abilities.includes('manageWorkspace') ? (
                    <DropdownMenuItem onSelect={() => void navigate(`/w/${workspace.id}/configurar`)}>
                        <Wand2 />
                        {t('nav.setupWizard')}
                    </DropdownMenuItem>
                ) : null}
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
            <div className={cn('igr:flex igr:h-14 igr:shrink-0 igr:items-center', collapsed ? 'igr:justify-center igr:px-2' : 'igr:gap-2 igr:pr-2 igr:pl-4')}>
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
                        onMouseEnter={preloadCommandPalette}
                        onFocus={preloadCommandPalette}
                        aria-label={collapsed ? searchLabel : undefined}
                        aria-keyshortcuts={isMac ? 'Meta+K' : 'Control+K'}
                        className={cn(
                            'igr:mt-2 igr:text-[13px] igr:text-muted-foreground igr:hover:text-foreground',
                            collapsed
                                ? `${ICON_BUTTON} igr:hover:bg-accent`
                                : 'igr:flex igr:h-9 igr:w-full igr:items-center igr:gap-2 igr:rounded-lg igr:border igr:bg-card/70 igr:px-2.5 igr:transition-colors igr:hover:bg-card',
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
            <nav aria-label={t('nav.main')} className="igr:mt-4 igr:flex-1 igr:overflow-y-auto igr:px-2 igr:pb-3">
                <NavSections base={base} iconsOnly={collapsed} />
            </nav>
            <div className="igr:border-t igr:p-2">
                <UserMenu collapsed={collapsed} />
            </div>
        </aside>
    );
}
