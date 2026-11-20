import { Bot, Inbox as InboxIcon } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { Link, useNavigate, useParams, useSearchParams } from 'react-router';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogTitle } from '@/components/ui/dialog';
import { PageHeader } from '@/components/ui/misc';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { PHONE_QUERY, useMedia, XL_QUERY } from '@/hooks/useMedia';
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
    // El perfil del contacto: columna fija desde xl; por debajo, una hoja aparte que se abre para la conversación actual.
    const wide = useMedia(XL_QUERY);
    const phone = useMedia(PHONE_QUERY);
    const [panelFor, setPanelFor] = useState<string | null>(null);
    const sheetOpen = !wide && conversation !== undefined && panelFor === conversation;
    // En el celular, con un chat abierto, el chat ocupa la pantalla (debajo de la barra del menú): sin encabezado ni pestañas.
    const chatOnly = conversation !== undefined && tab === 'conversaciones';
    const chatRef = useRef<HTMLDivElement>(null);
    // Al cerrar la hoja del perfil, el foco vuelve al botón que la abrió (la hoja no usa Dialog.Trigger).
    const panelOpener = useRef<HTMLElement | null>(null);
    const togglePanel = () => {
        panelOpener.current = document.activeElement instanceof HTMLElement ? document.activeElement : null;
        setPanelFor((v) => (v === conversation || conversation === undefined ? null : conversation));
    };

    useEffect(() => installPwa(), []);
    // Celular: al abrir un chat la lista queda oculta, así que el foco pasa al chat (y al volver, a la conversación en la lista).
    useEffect(() => {
        if (phone && conversation !== undefined) {
            chatRef.current?.focus({ preventScroll: true });
        }
    }, [phone, conversation]);
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
        /*
         * La ruta llena el alto de la pantalla (`handle: { fill: true }`): la lista y el chat se desplazan cada uno por su
         * cuenta. Celular: la lista o el chat (con «Volver»); tableta: lista y chat; desde xl, también el perfil.
         */
        <div className="igr:flex igr:min-h-0 igr:flex-1 igr:flex-col">
            <div className={cn('igr:px-3 igr:pt-3 igr:md:p-0', chatOnly && 'igr:max-md:hidden')}>
                <PageHeader
                    title={t('inbox.title')}
                    description={t('inbox.description')}
                    actions={
                        <>
                            <Availability />
                            <Button asChild size="sm" variant="outline">
                                <Link to={`/w/${workspace.id}/bandeja/agente`}>
                                    <Bot aria-hidden />
                                    {t('inbox.aiAgentLink')}
                                </Link>
                            </Button>
                        </>
                    }
                />
            </div>
            <Tabs value={tab} onValueChange={setTab} className="igr:flex igr:min-h-0 igr:flex-1 igr:flex-col igr:md:mt-2">
                <TabsList className={cn('igr:max-md:px-3', chatOnly && 'igr:max-md:hidden')}>
                    {TABS.filter((k) => k !== 'ajustes' || canManage).map((k) => (
                        <TabsTrigger key={k} value={k}>
                            {t(`inbox.tabs.${k}`)}
                        </TabsTrigger>
                    ))}
                </TabsList>
                <TabsContent value="conversaciones" className="igr:min-h-0 igr:flex-1 igr:max-md:pt-0 igr:md:pt-3">
                    <div
                        className={cn(
                            'igr:grid igr:h-full igr:min-h-72 igr:grid-rows-[minmax(0,1fr)] igr:overflow-hidden igr:bg-card igr:md:min-h-[420px] igr:md:rounded-lg igr:md:border',
                            'igr:grid-cols-1 igr:md:grid-cols-[280px_minmax(0,1fr)] igr:xl:grid-cols-[280px_minmax(0,1fr)_260px]',
                            chatOnly && 'igr:max-md:min-h-0',
                        )}
                    >
                        {/* En el celular el chat se pinta encima de la lista, que sigue montada e invisible: al volver conserva filtros y posición. */}
                        <div className={cn('igr:col-start-1 igr:row-start-1 igr:min-h-0 igr:md:border-r', conversation !== undefined && 'igr:max-md:invisible')}>
                            <ConversationListPane selected={conversation} onSelect={open} />
                        </div>
                        <div
                            ref={chatRef}
                            tabIndex={-1}
                            role="region"
                            aria-label={t('inbox.chatRegion')}
                            className={cn(
                                'igr:col-start-1 igr:row-start-1 igr:min-h-0 igr:min-w-0 igr:flex-col igr:bg-card igr:outline-none igr:md:col-start-2',
                                conversation !== undefined ? 'igr:flex' : 'igr:hidden igr:md:flex',
                            )}
                        >
                            {conversation !== undefined ? (
                                <ChatPane key={conversation} id={conversation} onBack={() => open(null)} onTogglePanel={togglePanel} />
                            ) : (
                                <div className="igr:flex igr:flex-1 igr:flex-col igr:items-center igr:justify-center igr:gap-2 igr:p-8 igr:text-center igr:text-muted-foreground">
                                    <InboxIcon className="igr:size-8" aria-hidden />
                                    <p className="igr:text-sm igr:font-medium igr:text-foreground">{t('inbox.pickTitle')}</p>
                                    <p className="igr:max-w-xs igr:text-xs">{t('inbox.pickHelp')}</p>
                                </div>
                            )}
                        </div>
                        {conversation !== undefined && wide ? (
                            <aside className="igr:col-start-3 igr:row-start-1 igr:min-h-0 igr:overflow-y-auto igr:border-l igr:bg-background" aria-label={t('inbox.panel.title')}>
                                <ContactPanel id={conversation} />
                            </aside>
                        ) : null}
                    </div>
                    <Dialog open={sheetOpen} onOpenChange={(o) => setPanelFor(o && conversation !== undefined ? conversation : null)}>
                        <DialogContent
                            aria-describedby={undefined}
                            onCloseAutoFocus={(e) => {
                                e.preventDefault();
                                panelOpener.current?.focus();
                            }}
                            className="igr:top-0 igr:right-0 igr:left-auto igr:flex igr:h-dvh igr:w-full igr:max-w-sm igr:translate-x-0 igr:flex-col igr:rounded-none igr:border-y-0 igr:border-r-0 igr:p-0"
                        >
                            <div className="igr:flex igr:h-12 igr:shrink-0 igr:items-center igr:border-b igr:pr-10 igr:pl-3">
                                <DialogTitle className="igr:text-sm">{t('inbox.panel.title')}</DialogTitle>
                            </div>
                            <div className="igr:min-h-0 igr:flex-1 igr:overflow-y-auto">{conversation !== undefined ? <ContactPanel id={conversation} /> : null}</div>
                        </DialogContent>
                    </Dialog>
                </TabsContent>
                <TabsContent value="metricas" className="igr:pb-6 igr:max-md:px-3">
                    <MetricsTab />
                </TabsContent>
                {canManage ? (
                    <TabsContent value="ajustes" className="igr:pb-6 igr:max-md:px-3">
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
