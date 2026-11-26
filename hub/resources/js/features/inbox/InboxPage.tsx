import { Bot, MessagesSquare, RefreshCw } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { Link, useNavigate, useParams, useSearchParams } from 'react-router';
import { Button } from '@/components/ui/button';
import { Callout } from '@/components/ui/callout';
import { Dialog, DialogContent, DialogTitle } from '@/components/ui/dialog';
import { PageHeader } from '@/components/ui/misc';
import { Switch } from '@/components/ui/switch';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useAgentSettings } from '@/features/agent/api';
import { PHONE_QUERY, useMedia, XL_QUERY } from '@/hooks/useMedia';
import { useCan, useWorkspace } from '@/hooks/useWorkspace';
import { cn } from '@/lib/cn';
import { usePrivateChannel } from '@/lib/realtime';
import { invalidateConversations, type ListFilters, useConversations, usePresence } from './api';
import { ChatPane } from './ChatPane';
import { ContactPanel } from './ContactPanel';
import { ConversationListPane, DEFAULT_FILTERS } from './ConversationListPane';
import { accountsNeedingAttention } from './lib';
import { MetricsTab } from './MetricsTab';
import { SettingsTab } from './SettingsTab';
import { installPwa } from './pwa';
import type { InboxAccount } from './types';

const TABS = ['conversaciones', 'metricas', 'ajustes'] as const;
type Tab = (typeof TABS)[number];

/**
 * Bandeja de WhatsApp, Messenger e Instagram (modulos.md M11): lista, chat y perfil al lado, métricas y ajustes. La lista
 * se pide aquí (una sola consulta para la lista, los contadores y el estado de las cuentas que conversan).
 */
export function InboxPage() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const canManage = useCan('manageInbox');
    const [params, setParams] = useSearchParams();
    const { conversation } = useParams();
    const navigate = useNavigate();
    const raw = params.get('tab');
    const tab: Tab = raw === 'metricas' || (raw === 'ajustes' && canManage) ? raw : 'conversaciones';
    const [filters, setFilters] = useState<ListFilters>(DEFAULT_FILTERS);
    const list = useConversations(workspace.id, filters);
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
    // Tiempo real: un cambio de una conversación vuelve a pedir la lista y el chat abierto (el evento solo trae IDs).
    const queryClient = useQueryClient();
    usePrivateChannel(`workspace.${workspace.id}.inbox`, 'inbox.updated', () => invalidateConversations(queryClient, workspace.id));

    const setTab = (next: string) => {
        const p = new URLSearchParams(params);
        if (next === 'conversaciones') p.delete('tab');
        else p.set('tab', next);
        setParams(p, { replace: true });
    };
    const open = (id: string | null) => void navigate(id ? `/w/${workspace.id}/bandeja/${id}` : `/w/${workspace.id}/bandeja`);
    const attention = accountsNeedingAttention(list.data?.accounts);

    return (
        /*
         * La ruta llena el alto de la pantalla (`handle: { fill: true }`): la lista y el chat se desplazan cada uno por su
         * cuenta. Celular: la lista o el chat (con «Volver»); tableta: lista y chat; desde xl, también el perfil.
         */
        <div className="igr:flex igr:min-h-0 igr:flex-1 igr:flex-col">
            <div className={cn('igr:px-3 igr:pt-3 igr:md:p-0', chatOnly && 'igr:max-md:hidden')}>
                <PageHeader
                    title={t('inbox.title')}
                    description={<span className="igr:max-md:hidden">{t('inbox.description')}</span>}
                    className="igr:max-md:mb-3"
                    actions={
                        <>
                            <Availability />
                            <AgentLink />
                        </>
                    }
                />
                {attention.length > 0 ? <AccountsNotice accounts={attention} /> : null}
            </div>
            <Tabs value={tab} onValueChange={setTab} className="igr:flex igr:min-h-0 igr:flex-1 igr:flex-col">
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
                            'igr:grid igr:h-full igr:min-h-72 igr:grid-rows-[minmax(0,1fr)] igr:overflow-hidden igr:bg-card igr:md:min-h-[440px] igr:md:rounded-xl igr:md:border igr:md:shadow-xs',
                            'igr:grid-cols-1 igr:md:grid-cols-[300px_minmax(0,1fr)] igr:xl:grid-cols-[320px_minmax(0,1fr)_280px]',
                            chatOnly && 'igr:max-md:min-h-0',
                        )}
                    >
                        {/* En el celular el chat se pinta encima de la lista, que sigue montada e invisible: al volver conserva filtros y posición. */}
                        <div className={cn('igr:col-start-1 igr:row-start-1 igr:min-h-0 igr:md:border-r', conversation !== undefined && 'igr:max-md:invisible')}>
                            <ConversationListPane selected={conversation} onSelect={open} list={list} filters={filters} onFiltersChange={setFilters} />
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
                                <PickConversation empty={list.data !== undefined && list.data.data.length === 0} />
                            )}
                        </div>
                        {conversation !== undefined && wide ? (
                            <aside className="igr:col-start-3 igr:row-start-1 igr:min-h-0 igr:overflow-y-auto igr:border-l igr:bg-background/60" aria-label={t('inbox.panel.title')}>
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
                            <div className="igr:flex igr:h-12 igr:shrink-0 igr:items-center igr:border-b igr:pr-10 igr:pl-4">
                                <DialogTitle className="igr:text-sm">{t('inbox.panel.title')}</DialogTitle>
                            </div>
                            <div className="igr:min-h-0 igr:flex-1 igr:overflow-y-auto">{conversation !== undefined ? <ContactPanel id={conversation} /> : null}</div>
                        </DialogContent>
                    </Dialog>
                </TabsContent>
                <TabsContent value="metricas" className="igr:pb-6 igr:max-md:px-3 igr:md:pt-4">
                    <MetricsTab />
                </TabsContent>
                {canManage ? (
                    <TabsContent value="ajustes" className="igr:pb-6 igr:max-md:px-3 igr:md:pt-4">
                        <SettingsTab />
                    </TabsContent>
                ) : null}
            </Tabs>
        </div>
    );
}

/** El panel del chat sin conversación elegida: qué hacer (o, sin ninguna, que los mensajes llegarán aquí). */
function PickConversation({ empty }: { empty: boolean }) {
    const { t } = useTranslation();
    return (
        <div className="igr:flex igr:flex-1 igr:flex-col igr:items-center igr:justify-center igr:gap-2 igr:bg-muted/20 igr:p-8 igr:text-center">
            <span className="igr:mb-1 igr:flex igr:size-12 igr:items-center igr:justify-center igr:rounded-2xl igr:bg-brand-soft igr:text-brand-soft-foreground">
                <MessagesSquare className="igr:size-6" aria-hidden />
            </span>
            <p className="igr:text-body igr:font-medium igr:text-foreground">{t(empty ? 'inbox.pickEmptyTitle' : 'inbox.pickTitle')}</p>
            <p className="igr:max-w-xs igr:text-support igr:text-muted-foreground">{t(empty ? 'inbox.pickEmptyHelp' : 'inbox.pickHelp')}</p>
        </div>
    );
}

/** Una cuenta desconectada o con error: se lee el historial, pero para responder hay que reconectarla. */
function AccountsNotice({ accounts }: { accounts: InboxAccount[] }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const canFix = useCan('manageWorkspace');
    const first = accounts[0];
    if (!first) return null;
    const name = `${t(`inbox.channel.${first.channel}`)}${first.identity ? ` ${first.identity}` : ''}`;
    return (
        <Callout
            tone="warning"
            className="igr:mb-4"
            title={t(first.status === 'error' ? 'inbox.accounts.errorTitle' : 'inbox.accounts.disconnectedTitle', { name, count: accounts.length })}
            action={
                canFix ? (
                    <Button asChild size="sm" variant="outline">
                        <Link to={`/w/${workspace.id}/conexiones?tarjeta=${first.channel}`}>
                            <RefreshCw aria-hidden />
                            {t('inbox.accounts.reconnect')}
                        </Link>
                    </Button>
                ) : undefined
            }
        >
            <span className="igr:max-sm:hidden">{t(canFix ? 'inbox.accounts.body' : 'inbox.accounts.bodyAskAdmin')}</span>
        </Callout>
    );
}

/** El agente con IA a la vista: qué está haciendo (apagado, sugiere o responde solo) y el enlace a su configuración. */
function AgentLink() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const agent = useAgentSettings(workspace.id);
    const s = agent.data?.settings;
    const state = !s ? null : !s.enabled || s.default_mode === 'off' ? 'off' : s.default_mode;
    return (
        <Button asChild size="sm" variant="outline">
            <Link to={`/w/${workspace.id}/bandeja/agente`}>
                <Bot aria-hidden />
                {t('inbox.aiAgentLink')}
                {state ? (
                    <span
                        className={cn(
                            'igr:ml-0.5 igr:rounded-full igr:px-1.5 igr:py-px igr:text-[11px] igr:font-medium',
                            state === 'off' ? 'igr:bg-muted igr:text-muted-foreground' : 'igr:bg-success/12 igr:text-success',
                        )}
                    >
                        {t(`inbox.agentState.${state}`)}
                    </span>
                ) : null}
            </Link>
        </Button>
    );
}

/** «Disponible»: mientras está encendido, la asignación automática puede darle conversaciones (se avisa cada minuto). */
function Availability() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const presence = usePresence(workspace.id);
    const storageKey = `igr:inbox:available:${workspace.id}`;
    const [available, setAvailable] = useState(() => localStorage.getItem(storageKey) === '1');
    const { mutate } = presence;
    const first = useRef(true);

    useEffect(() => {
        localStorage.setItem(storageKey, available ? '1' : '0');
        // Al entrar sin estar disponible no hay nada que avisar; al apagarlo sí (para dejar de recibir).
        if (available || !first.current) mutate(available);
        first.current = false;
        if (!available) return undefined;
        const timer = window.setInterval(() => mutate(true), 60_000);
        return () => window.clearInterval(timer);
    }, [available, storageKey, mutate]);

    return (
        <div className="igr:flex igr:h-8 igr:items-center igr:gap-2 igr:rounded-md igr:border igr:bg-card igr:px-2.5 igr:shadow-xs">
            <Switch size="sm" checked={available} onCheckedChange={setAvailable} label={available ? t('inbox.available') : t('inbox.unavailable')} className="igr:items-center" />
        </div>
    );
}
