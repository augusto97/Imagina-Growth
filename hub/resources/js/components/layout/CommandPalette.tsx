import { Command } from 'cmdk';
import { Moon, Plug, Plus, Sun, SunMoon, UserPlus } from 'lucide-react';
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { useNavigate } from 'react-router';
import * as DialogPrimitive from '@radix-ui/react-dialog';
import { useWorkspace } from '@/hooks/useWorkspace';
import { useUi } from '@/stores/ui';
import { workspaceInitials } from './Sidebar';
import { MAIN_NAV, SETTINGS_NAV } from './nav';

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

/** Paleta ⌘K: navegar, cambiar de espacio de trabajo y acciones rápidas. */
export function CommandPalette() {
    const { t } = useTranslation();
    const navigate = useNavigate();
    const { workspace, me } = useWorkspace();
    const { commandOpen, setCommandOpen, setTheme } = useUi();
    const base = `/w/${workspace.id}`;

    const go = (path: string) => {
        setCommandOpen(false);
        void navigate(path);
    };
    const pages = [...MAIN_NAV, ...SETTINGS_NAV].filter((i) => i.to !== undefined && (!i.ability || workspace.abilities.includes(i.ability)));

    return (
        <DialogPrimitive.Root open={commandOpen} onOpenChange={setCommandOpen}>
            <DialogPrimitive.Portal>
                <DialogPrimitive.Overlay className="igr:fixed igr:inset-0 igr:z-50 igr:bg-black/40" />
                <DialogPrimitive.Content
                    aria-describedby={undefined}
                    className="igr:fixed igr:left-1/2 igr:top-[15vh] igr:z-50 igr:w-[calc(100%-2rem)] igr:max-w-xl igr:-translate-x-1/2 igr:overflow-hidden igr:rounded-xl igr:border igr:bg-popover igr:text-popover-foreground igr:shadow-2xl"
                >
                    <DialogPrimitive.Title className="igr:sr-only">{t('command.title')}</DialogPrimitive.Title>
                    <Command label={t('command.title')} loop>
                        <Command.Input
                            placeholder={t('command.placeholder')}
                            className="igr:h-11 igr:w-full igr:border-b igr:bg-transparent igr:px-4 igr:text-sm igr:outline-none igr:placeholder:text-muted-foreground"
                        />
                        <Command.List className="igr:max-h-80 igr:overflow-y-auto igr:p-1">
                            <Command.Empty className="igr:py-8 igr:text-center igr:text-[13px] igr:text-muted-foreground">{t('command.empty')}</Command.Empty>
                            <Command.Group heading={t('command.goTo')} className={groupClass}>
                                {pages.map((item) => {
                                    const Icon = item.icon;
                                    return (
                                        <Item key={item.key} value={`ir ${t(`nav.${item.key}`)}`} onSelect={() => go(item.to === '' ? base : `${base}/${item.to ?? ''}`)}>
                                            <Icon />
                                            {t(`nav.${item.key}`)}
                                            {item.shortcut ? <span className="igr:ml-auto igr:text-[11px] igr:text-muted-foreground">G {item.shortcut.toUpperCase()}</span> : null}
                                        </Item>
                                    );
                                })}
                            </Command.Group>
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
                                <Item value={`${t('theme.title')} ${t('theme.light')}`} onSelect={() => { setTheme('light'); setCommandOpen(false); }}>
                                    <Sun />
                                    {t('command.themeLight')}
                                </Item>
                                <Item value={`${t('theme.title')} ${t('theme.dark')}`} onSelect={() => { setTheme('dark'); setCommandOpen(false); }}>
                                    <Moon />
                                    {t('command.themeDark')}
                                </Item>
                                <Item value={`${t('theme.title')} ${t('theme.system')}`} onSelect={() => { setTheme('system'); setCommandOpen(false); }}>
                                    <SunMoon />
                                    {t('command.themeSystem')}
                                </Item>
                            </Command.Group>
                            {me.workspaces.length > 1 ? (
                                <Command.Group heading={t('nav.workspaces')} className={groupClass}>
                                    {me.workspaces
                                        .filter((ws) => ws.id !== workspace.id)
                                        .map((ws) => (
                                            <Item key={ws.id} value={`espacio ${ws.name}`} onSelect={() => go(`/w/${ws.id}`)}>
                                                <span className="igr:flex igr:size-5 igr:items-center igr:justify-center igr:rounded igr:bg-muted igr:text-[10px] igr:font-semibold">{workspaceInitials(ws.name)}</span>
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
