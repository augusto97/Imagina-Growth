import { useQuery } from '@tanstack/react-query';
import { Command } from 'cmdk';
import { Loader2, Lock, Moon, PanelLeftClose, PanelLeftOpen, Plug, Plus, Sun, SunMoon, User, UserPlus } from 'lucide-react';
import { useState, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { useNavigate } from 'react-router';
import * as DialogPrimitive from '@radix-ui/react-dialog';
import { wsPath } from '@/api/queries';
import { useDebounced } from '@/hooks/useDebounced';
import { DESKTOP_QUERY, useMedia } from '@/hooks/useMedia';
import { useCan, useWorkspace } from '@/hooks/useWorkspace';
import { api } from '@/lib/api';
import { useUi } from '@/stores/ui';
import { isMacPlatform, workspaceInitials } from './Sidebar';
import { itemLock, itemPath, navContext, sectionName, sectionPath, sectionPurpose, visibleSections } from './nav';

interface ContactHit {
    id: string;
    name: string;
    email: string | null;
    phone: string | null;
    city: string | null;
    orders_count: number;
}

/** Contactos por nombre, correo o teléfono (índice ciego del hub; GET contacts/search). */
function useContactSearch(ws: string, q: string, enabled: boolean) {
    const term = useDebounced(q.trim(), 250);
    return useQuery({
        queryKey: ['ws', ws, 'contacts', 'search', term],
        queryFn: async ({ signal }) => (await api.get<{ data: ContactHit[] }>(`${wsPath(ws)}/contacts/search?q=${encodeURIComponent(term)}`, signal)).data,
        enabled: enabled && term.length >= 2,
        staleTime: 30_000,
    });
}

function Item({ onSelect, children, value }: { onSelect: () => void; children: ReactNode; value: string }) {
    return (
        <Command.Item
            value={value}
            onSelect={onSelect}
            className="igr:flex igr:cursor-default igr:items-center igr:gap-2 igr:rounded-md igr:px-2 igr:py-2 igr:text-[13px] igr:data-[selected=true]:bg-accent igr:[&_svg]:size-4 igr:[&_svg]:text-muted-foreground"
        >
            {children}
        </Command.Item>
    );
}

const groupClass =
    'igr:px-1 igr:py-1 igr:[&_[cmdk-group-heading]]:px-2 igr:[&_[cmdk-group-heading]]:py-1.5 igr:[&_[cmdk-group-heading]]:text-[11px] igr:[&_[cmdk-group-heading]]:font-medium igr:[&_[cmdk-group-heading]]:text-muted-foreground';

/** Paleta ⌘K: buscar contactos, navegar, cambiar de espacio de trabajo y acciones rápidas. */
export function CommandPalette() {
    const { t } = useTranslation();
    const navigate = useNavigate();
    const { workspace, me } = useWorkspace();
    const { commandOpen, setCommandOpen, setTheme, sidebarCollapsed, setSidebarCollapsed } = useUi();
    const desktop = useMedia(DESKTOP_QUERY);
    const canSearchContacts = useCan('viewAssignedContacts');
    const [query, setQuery] = useState('');
    const contacts = useContactSearch(workspace.id, query, commandOpen && canSearchContacts);
    const base = `/w/${workspace.id}`;

    const close = (open: boolean) => {
        setCommandOpen(open);
        if (!open) {
            setQuery('');
        }
    };
    const go = (path: string) => {
        close(false);
        void navigate(path);
    };
    const nav = navContext(workspace);
    const sections = visibleSections(nav, me.user.is_platform_admin);
    const searching = canSearchContacts && query.trim().length >= 2;

    return (
        <DialogPrimitive.Root open={commandOpen} onOpenChange={close}>
            <DialogPrimitive.Portal>
                <DialogPrimitive.Overlay className="igr:fixed igr:inset-0 igr:z-50 igr:bg-slate-950/40 igr:backdrop-blur-[2px]" />
                <DialogPrimitive.Content
                    aria-describedby={undefined}
                    className="igr-enter igr:fixed igr:left-1/2 igr:top-[15vh] igr:z-50 igr:w-[calc(100%-2rem)] igr:max-w-xl igr:-translate-x-1/2 igr:overflow-hidden igr:rounded-2xl igr:border igr:bg-popover igr:text-popover-foreground igr:shadow-lg"
                >
                    <DialogPrimitive.Title className="igr:sr-only">{t('command.title')}</DialogPrimitive.Title>
                    <Command label={t('command.title')} loop>
                        <Command.Input
                            value={query}
                            onValueChange={setQuery}
                            placeholder={canSearchContacts ? t('command.placeholderContacts') : t('command.placeholder')}
                            className="igr:h-12 igr:w-full igr:border-b igr:bg-transparent igr:px-4 igr:text-sm igr:outline-none igr:placeholder:text-muted-foreground"
                        />
                        <Command.List className="igr:max-h-[min(24rem,60dvh)] igr:overflow-y-auto igr:p-1.5">
                            <Command.Empty className="igr:py-8 igr:text-center igr:text-[13px] igr:text-muted-foreground">
                                {searching && contacts.isFetching ? t('command.searchingContacts') : t('command.empty')}
                            </Command.Empty>
                            {searching ? (
                                <Command.Group heading={t('command.contacts')} className={groupClass}>
                                    {contacts.isFetching && !contacts.data ? (
                                        <Command.Loading>
                                            <div className="igr:flex igr:items-center igr:gap-2 igr:px-2 igr:py-2 igr:text-[13px] igr:text-muted-foreground">
                                                <Loader2 className="igr:size-4 igr:animate-spin" />
                                                {t('command.searchingContacts')}
                                            </div>
                                        </Command.Loading>
                                    ) : null}
                                    {(contacts.data ?? []).map((c) => (
                                        // El valor incluye lo escrito: el filtro de la paleta no esconde lo que encontró el servidor.
                                        <Item key={c.id} value={`contacto ${query} ${c.id}`} onSelect={() => go(`${base}/contactos/${c.id}`)}>
                                            <User />
                                            <span className="igr:min-w-0 igr:flex-1">
                                                <span className="igr:block igr:truncate">{c.name || c.email || c.phone || t('contacts.noName')}</span>
                                                <span className="igr:block igr:truncate igr:text-[11px] igr:text-muted-foreground">
                                                    {[c.email, c.phone, c.city].filter(Boolean).join(' · ')}
                                                </span>
                                            </span>
                                            {c.orders_count > 0 ? (
                                                <span className="igr:text-[11px] igr:text-muted-foreground">{t('command.orders', { count: c.orders_count })}</span>
                                            ) : null}
                                        </Item>
                                    ))}
                                    {contacts.data && contacts.data.length > 0 ? (
                                        <Item value={`contacto ${query} ver todos`} onSelect={() => go(`${base}/contactos?q=${encodeURIComponent(query.trim())}`)}>
                                            <User />
                                            {t('command.allContacts', { q: query.trim() })}
                                        </Item>
                                    ) : null}
                                </Command.Group>
                            ) : null}
                            {/* Cada sección con su página de entrada y sus pantallas; los nombres de antes también encuentran (flujos, segmentos…). */}
                            {/* Las secciones de una sola página juntas en «Ir a»; las demás, cada una con su resumen y sus pantallas. */}
                            <Command.Group heading={t('command.goTo')} className={groupClass}>
                                {sections
                                    .filter((section) => section.items.length === 0)
                                    .map((section) => {
                                        const name = sectionName(t, section.key, nav.vertical);
                                        const SectionIcon = section.icon;
                                        return (
                                            <Item
                                                key={section.key}
                                                value={`ir ${name} ${sectionPurpose(t, section.key, nav.vertical)}`}
                                                onSelect={() => go(sectionPath(base, section))}
                                            >
                                                <SectionIcon />
                                                {name}
                                                {section.shortcut ? (
                                                    <span className="igr:ml-auto igr:text-[11px] igr:text-muted-foreground">G {section.shortcut.toUpperCase()}</span>
                                                ) : null}
                                            </Item>
                                        );
                                    })}
                            </Command.Group>
                            {sections
                                .filter((section) => section.items.length > 0)
                                .map((section) => {
                                    const name = sectionName(t, section.key, nav.vertical);
                                    const SectionIcon = section.icon;
                                    return (
                                        <Command.Group key={section.key} heading={name} className={groupClass}>
                                            <Item value={`ir ${name} ${sectionPurpose(t, section.key, nav.vertical)}`} onSelect={() => go(sectionPath(base, section))}>
                                                <SectionIcon />
                                                {t('command.sectionOverview', { section: name })}
                                            </Item>
                                            {section.items.map((item) => {
                                                const Icon = item.icon;
                                                const locked = itemLock(item, nav) !== null;
                                                return (
                                                    <Item
                                                        key={item.key}
                                                        value={`ir ${t(`nav.${item.key}`)} ${name} ${t(`nav.keywords.${item.key}`, { defaultValue: '' })}`}
                                                        onSelect={() => go(itemPath(base, item))}
                                                    >
                                                        <Icon />
                                                        {t(`nav.${item.key}`)}
                                                        {locked ? <Lock className="igr:size-3!" aria-label={t('ui.locked.short.generic')} /> : null}
                                                        {item.shortcut ? (
                                                            <span className="igr:ml-auto igr:text-[11px] igr:text-muted-foreground">G {item.shortcut.toUpperCase()}</span>
                                                        ) : null}
                                                    </Item>
                                                );
                                            })}
                                        </Command.Group>
                                    );
                                })}
                            <Command.Group heading={t('command.actions')} className={groupClass}>
                                {workspace.abilities.includes('manageSites') ? (
                                    <Item value={t('command.connectSite')} onSelect={() => go(`${base}/sitios?conectar=1`)}>
                                        <Plug />
                                        {t('command.connectSite')}
                                    </Item>
                                ) : null}
                                {workspace.abilities.includes('manageMembers') ? (
                                    <Item value={t('command.invite')} onSelect={() => go(`${base}/equipo?invitar=1`)}>
                                        <UserPlus />
                                        {t('command.invite')}
                                    </Item>
                                ) : null}
                                <Item value={t('nav.newWorkspace')} onSelect={() => go('/onboarding')}>
                                    <Plus />
                                    {t('nav.newWorkspace')}
                                </Item>
                                <Item
                                    value={`${t('theme.title')} ${t('theme.light')}`}
                                    onSelect={() => {
                                        setTheme('light');
                                        close(false);
                                    }}
                                >
                                    <Sun />
                                    {t('command.themeLight')}
                                </Item>
                                <Item
                                    value={`${t('theme.title')} ${t('theme.dark')}`}
                                    onSelect={() => {
                                        setTheme('dark');
                                        close(false);
                                    }}
                                >
                                    <Moon />
                                    {t('command.themeDark')}
                                </Item>
                                <Item
                                    value={`${t('theme.title')} ${t('theme.system')}`}
                                    onSelect={() => {
                                        setTheme('system');
                                        close(false);
                                    }}
                                >
                                    <SunMoon />
                                    {t('command.themeSystem')}
                                </Item>
                                {desktop ? (
                                    <Item
                                        value={sidebarCollapsed ? t('nav.expandSidebar') : t('nav.collapseSidebar')}
                                        onSelect={() => {
                                            setSidebarCollapsed(!sidebarCollapsed);
                                            close(false);
                                        }}
                                    >
                                        {sidebarCollapsed ? <PanelLeftOpen /> : <PanelLeftClose />}
                                        {sidebarCollapsed ? t('nav.expandSidebar') : t('nav.collapseSidebar')}
                                        <span className="igr:ml-auto igr:text-[11px] igr:text-muted-foreground">{isMacPlatform() ? '⌘B' : 'Ctrl+B'}</span>
                                    </Item>
                                ) : null}
                            </Command.Group>
                            {me.workspaces.length > 1 ? (
                                <Command.Group heading={t('nav.workspaces')} className={groupClass}>
                                    {me.workspaces
                                        .filter((ws) => ws.id !== workspace.id)
                                        .map((ws) => (
                                            <Item key={ws.id} value={`espacio ${ws.name}`} onSelect={() => go(`/w/${ws.id}`)}>
                                                <span className="igr:flex igr:size-5 igr:items-center igr:justify-center igr:rounded igr:bg-muted igr:text-[10px] igr:font-semibold">
                                                    {workspaceInitials(ws.name)}
                                                </span>
                                                {t('command.switchTo', { name: ws.name })}
                                            </Item>
                                        ))}
                                </Command.Group>
                            ) : null}
                        </Command.List>
                    </Command>
                </DialogPrimitive.Content>
            </DialogPrimitive.Portal>
        </DialogPrimitive.Root>
    );
}
