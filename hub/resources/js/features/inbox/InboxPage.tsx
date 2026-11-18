import { Bot, Inbox as InboxIcon } from 'lucide-react';
import { useEffect, useState } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { Link, useNavigate, useParams, useSearchParams } from 'react-router';
import { Button } from '@/components/ui/button';
import { PageHeader } from '@/components/ui/misc';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useCan, useWorkspace } from '@/hooks/useWorkspace';
import { cn } from '@/lib/cn';
import { usePrivateChannel } from '@/lib/realtime';
import { inboxKeys, usePresence } from './api';
import { ChatPane } from './ChatPane';
import { ContactPanel } from './ContactPanel';
import { ConversationListPane } from './ConversationListPane';
import { MetricsTab } from './MetricsTab';
import { SettingsTab } from './SettingsTab';
import { installPwa } from './pwa';

const TABS = ['conversaciones', 'metricas', 'ajustes'] as const;
type Tab = (typeof TABS)[number];

/** Bandeja de WhatsApp multiagente (modulos.md M11): lista, chat y perfil al lado, métricas y ajustes. */
export function InboxPage() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const canManage = useCan('manageInbox');
    const [params, setParams] = useSearchParams();
    const { conversation } = useParams();
    const navigate = useNavigate();
    const raw = params.get('tab');
    const tab: Tab = raw === 'metricas' || (raw === 'ajustes' && canManage) ? raw : 'conversaciones';
    const [panelOpen, setPanelOpen] = useState(false);

    useEffect(() => installPwa(), []);
    // Tiempo real: cada cambio de una conversación vuelve a pedir la bandeja (el evento solo trae IDs).
    const queryClient = useQueryClient();
    usePrivateChannel(`workspace.${workspace.id}.inbox`, 'inbox.updated', () => {
        void queryClient.invalidateQueries({ queryKey: inboxKeys.all(workspace.id) });
    });

    const setTab = (next: string) => {
        const p = new URLSearchParams(params);
        if (next === 'conversaciones') p.delete('tab');
        else p.set('tab', next);
        setParams(p, { replace: true });
    };
    const open = (id: string | null) => void navigate(id ? `/w/${workspace.id}/bandeja/${id}` : `/w/${workspace.id}/bandeja`);

    return (
        // En el celular la bandeja ocupa toda la pantalla (la PWA): tapa el menú lateral.
        <div className="igr:max-md:fixed igr:max-md:inset-0 igr:max-md:z-30 igr:max-md:overflow-y-auto igr:max-md:bg-background igr:max-md:px-3 igr:max-md:py-3">
            <PageHeader
                title={t('inbox.title')}
                description={t('inbox.description')}
                actions={
                    <div className="igr:flex igr:items-center igr:gap-2">
                        <Availability />
                        <Button asChild size="sm" variant="outline">
                            <Link to={`/w/${workspace.id}/bandeja/agente`}>
                                <Bot aria-hidden />
                                {t('inbox.aiAgentLink')}
                            </Link>
                        </Button>
                    </div>
                }
            />
            <Tabs value={tab} onValueChange={setTab} className="igr:mt-2">
                <TabsList>
                    {TABS.filter((k) => k !== 'ajustes' || canManage).map((k) => (
                        <TabsTrigger key={k} value={k}>
                            {t(`inbox.tabs.${k}`)}
                        </TabsTrigger>
                    ))}
                </TabsList>
                <TabsContent value="conversaciones" className="igr:mt-3">
                    <div
                        className={cn(
                            'igr:grid igr:h-[calc(100vh-13rem)] igr:min-h-[480px] igr:overflow-hidden igr:rounded-lg igr:border igr:bg-card igr:max-md:h-[calc(100dvh-14rem)] igr:max-md:min-h-0',
                            'igr:grid-cols-1 igr:md:grid-cols-[280px_1fr] igr:xl:grid-cols-[280px_1fr_260px]',
                        )}
                    >
                        <div className={cn('igr:min-h-0 igr:border-r', conversation ? 'igr:hidden igr:md:block' : 'igr:block')}>
                            <ConversationListPane selected={conversation} onSelect={open} />
                        </div>
                        <div className={cn('igr:min-h-0 igr:min-w-0', conversation ? 'igr:flex' : 'igr:hidden igr:md:flex', 'igr:flex-col')}>
                            {conversation ? (
                                <ChatPane key={conversation} id={conversation} onBack={() => open(null)} onTogglePanel={() => setPanelOpen((v) => !v)} />
                            ) : (
                                <div className="igr:flex igr:flex-1 igr:flex-col igr:items-center igr:justify-center igr:gap-2 igr:p-8 igr:text-center igr:text-muted-foreground">
                                    <InboxIcon className="igr:size-8" aria-hidden />
                                    <p className="igr:text-sm igr:font-medium igr:text-foreground">{t('inbox.pickTitle')}</p>
                                    <p className="igr:max-w-xs igr:text-xs">{t('inbox.pickHelp')}</p>
                                </div>
                            )}
                        </div>
                        {conversation ? (
                            <aside
                                className={cn(
                                    'igr:min-h-0 igr:overflow-y-auto igr:border-l igr:bg-background',
                                    panelOpen
                                        ? 'igr:fixed igr:inset-y-0 igr:right-0 igr:z-40 igr:w-[min(320px,90vw)] igr:shadow-xl igr:xl:static igr:xl:w-auto igr:xl:shadow-none'
                                        : 'igr:hidden igr:xl:block',
                                )}
                                aria-label={t('inbox.panel.title')}
                            >
                                <ContactPanel id={conversation} onClose={() => setPanelOpen(false)} />
                            </aside>
                        ) : null}
                    </div>
                </TabsContent>
                <TabsContent value="metricas" className="igr:mt-4">
                    <MetricsTab />
                </TabsContent>
                {canManage ? (
                    <TabsContent value="ajustes" className="igr:mt-4">
                        <SettingsTab />
                    </TabsContent>
                ) : null}
            </Tabs>
        </div>
    );
}

/** «Disponible»: mientras está encendido, la asignación automática puede darle conversaciones. */
function Availability() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const presence = usePresence(workspace.id);
    const storageKey = `igr:inbox:available:${workspace.id}`;
    const [available, setAvailable] = useState(() => localStorage.getItem(storageKey) === '1');
    const { mutate } = presence;

    useEffect(() => {
        localStorage.setItem(storageKey, available ? '1' : '0');
        mutate(available);
        if (!available) return undefined;
        const timer = window.setInterval(() => mutate(true), 60_000);
        return () => window.clearInterval(timer);
    }, [available, storageKey, mutate]);

    return (
        <label className="igr:flex igr:cursor-pointer igr:items-center igr:gap-2 igr:rounded-md igr:border igr:px-2.5 igr:py-1 igr:text-xs">
            <input type="checkbox" className="igr:size-3.5" checked={available} onChange={(e) => setAvailable(e.target.checked)} />
            <span className={cn('igr:size-2 igr:rounded-full', available ? 'igr:bg-success' : 'igr:bg-muted-foreground/40')} aria-hidden />
            {available ? t('inbox.available') : t('inbox.unavailable')}
        </label>
    );
}
