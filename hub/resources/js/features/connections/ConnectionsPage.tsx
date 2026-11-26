import { Bell, CalendarDays, Camera, CircleCheck, Globe, Mail, MessageCircle, MessagesSquare, QrCode, Smartphone, type LucideIcon } from 'lucide-react';
import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useSearchParams } from 'react-router';
import { useSites } from '@/api/queries';
import { StatusDot } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { IconTile, type IconTone } from '@/components/ui/icon-tile';
import { ErrorState, PageHeader } from '@/components/ui/misc';
import { Progress } from '@/components/ui/progress';
import { Sheet, SheetBody, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { Skeleton } from '@/components/ui/skeleton';
import { itemVisible, navContext } from '@/components/layout/nav';
import { useChannels } from '@/features/channels/api';
import { useCan, useWorkspace } from '@/hooks/useWorkspace';
import { cn } from '@/lib/cn';
import { toast } from '@/stores/toast';
import { ConnectionAlertsNotice } from './AlertsNotice';
import { type Connection, type RedirectProvider, useAdDestinations, useConnections } from './api';
import { ProviderMark } from './bits';
import { adsStatus, calendarStatus, type CardKey, type CardStatus, cardFromParam, channelStatus, destinationFor, emailStatus, GROUPS, summarize, webStatus } from './hub';
import { AssetsDialog } from './Pickers';
import { AdsSheet, CalendarSheet, CaptureSheet, EmailSheet, SmsSheet, SocialSheet, WebSheet, WhatsAppSheet } from './Sheets';

const ICONS: Partial<Record<CardKey, { icon: LucideIcon; tone: IconTone }>> = {
    web: { icon: Globe, tone: 'indigo' },
    capture: { icon: QrCode, tone: 'violet' },
    whatsapp: { icon: MessageCircle, tone: 'emerald' },
    messenger: { icon: MessagesSquare, tone: 'sky' },
    instagram: { icon: Camera, tone: 'rose' },
    email: { icon: Mail, tone: 'indigo' },
    sms: { icon: Smartphone, tone: 'slate' },
    calendar: { icon: CalendarDays, tone: 'teal' },
};
const ADS: RedirectProvider[] = ['meta', 'google', 'tiktok'];
/** Lo esencial para que la plataforma sirva: su botón «Conectar» es el principal; lo opcional va en contorno. */
const ESSENTIALS: CardKey[] = ['web', 'whatsapp', 'email', 'meta'];

/**
 * Configuración → Conexiones, el centro único (docs/diseno/rediseno-v2.md §3; absorbe Sitios y Canales): todo lo que se
 * conecta, agrupado (tu web, conversaciones, correo, anuncios y agenda), con el estado de cada cosa en palabras, lo que
 * permite y el botón para conectarla; el detalle se abre en la hoja lateral (`?tarjeta=`). Las rutas de antes
 * (`sitios`, `canales`, `conexiones/:tarjeta`) llegan aquí con su tarjeta abierta.
 */
export function ConnectionsPage() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const canSeeSites = useCan('viewSites');
    const canSeeDestinations = useCan('viewDashboards');
    const sites = useSites(workspace.id, canSeeSites);
    const channels = useChannels(workspace.id);
    const list = useConnections(workspace.id);
    const destinations = useAdDestinations(workspace.id, canSeeDestinations);
    const [params, setParams] = useSearchParams();
    const card = cardFromParam(params.get('tarjeta'));
    const [chosen, setChosen] = useState<Connection | null>(null);
    const services = itemVisible({ audience: 'services' }, navContext({ ...workspace, abilities: [] }));

    const setCard = (next: CardKey | null) =>
        setParams(
            (p) => {
                if (next === null) {
                    p.delete('tarjeta');
                    p.delete('conectar');
                } else p.set('tarjeta', next);
                return p;
            },
            { replace: next === null },
        );

    // Vuelta del consentimiento: ?conexion=google&estado=ok&id=oac_… (o cancelado / error con motivo). Con «ok» la
    // URL queda con ?elegir=oac_… para abrir la elección de cuentas de la conexión recién hecha.
    const status = params.get('estado');
    useEffect(() => {
        const provider = params.get('conexion');
        if (status === null || provider === null) return;
        const name = t(`connections.provider.${provider}.name`, { defaultValue: provider });
        const id = params.get('id');
        if (status === 'ok') {
            toast.success(t('connections.toast.connected', { provider: name }));
        } else if (status === 'cancelado') {
            toast.info(t('connections.toast.cancelled', { provider: name }));
        } else {
            const reason = params.get('motivo') ?? 'provider';
            toast.error(t('connections.toast.error', { provider: name }), t(`connections.reason.${reason}`, { defaultValue: t('connections.reason.provider') }));
        }
        setParams(
            (p) => {
                ['conexion', 'estado', 'motivo', 'id'].forEach((k) => p.delete(k));
                if (status === 'ok' && id !== null) p.set('elegir', id);
                if (!p.has('tarjeta') && cardFromParam(provider) !== null) p.set('tarjeta', cardFromParam(provider) ?? '');
                return p;
            },
            { replace: true },
        );
    }, [status, params, setParams, t]);

    const providers = list.data?.providers;
    const provider = (key: string) => providers?.find((p) => p.key === key);
    const pickId = params.get('elegir');
    const fromUrl = pickId !== null ? (providers?.flatMap((p) => p.connections).find((c) => c.id === pickId && c.provider !== 'meta_whatsapp') ?? null) : null;
    const picking = chosen ?? fromUrl;
    const closePicker = () => {
        setChosen(null);
        if (pickId !== null) {
            setParams(
                (p) => {
                    p.delete('elegir');
                    return p;
                },
                { replace: true },
            );
        }
    };

    const accounts = channels.data?.data;
    const dest = destinations.data?.destinations;
    const statuses: Partial<Record<CardKey, CardStatus>> = {
        web: canSeeSites ? (sites.data ? webStatus(sites.data) : undefined) : undefined,
        capture: { tone: 'off', label: 'captureAlways' },
        whatsapp: accounts ? channelStatus(accounts, 'whatsapp') : undefined,
        messenger: accounts ? channelStatus(accounts, 'messenger') : undefined,
        instagram: accounts ? channelStatus(accounts, 'instagram') : undefined,
        email: accounts ? emailStatus(accounts) : undefined,
        sms: accounts ? channelStatus(accounts, 'sms') : undefined,
        calendar: list.data ? calendarStatus(list.data.calendar_connections) : undefined,
    };
    for (const key of ADS) {
        statuses[key] = providers && (destinations.data || !canSeeDestinations) ? adsStatus(provider(key), destinationFor(dest, key)) : undefined;
    }
    const groups = GROUPS.filter((g) => g.key !== 'agenda' || services).map((g) => ({ ...g, cards: g.cards.filter((c) => c !== 'web' || canSeeSites) }));
    // El resumen cuenta lo esencial: la web, WhatsApp, el correo y los anuncios de Meta (lo demás es opcional).
    const essentials = ESSENTIALS.filter((k) => k !== 'web' || canSeeSites);
    const summary = summarize(statuses, essentials);
    const loading = channels.isPending || list.isPending;

    return (
        <div>
            <PageHeader title={t('connections.title')} description={t('connections.hub.description')} />
            <ConnectionAlertsNotice className="igr:mb-4" onConnectionsPage />
            {channels.isError || list.isError ? (
                <ErrorState
                    error={channels.error ?? list.error}
                    onRetry={() => {
                        void channels.refetch();
                        void list.refetch();
                    }}
                />
            ) : null}
            <Card className="igr:mb-8 igr:flex igr:flex-col igr:gap-4 igr:p-5 igr:sm:flex-row igr:sm:items-center">
                <IconTile icon={summary.on === summary.total ? CircleCheck : Bell} tone={summary.on === summary.total ? 'emerald' : 'brand'} size="lg" />
                <div className="igr:min-w-0 igr:flex-1">
                    {loading ? (
                        <Skeleton className="igr:h-5 igr:w-64" />
                    ) : (
                        <p className="igr:text-body igr:font-semibold">{summary.on === summary.total ? t('connections.hub.summary.allSet') : t('connections.hub.summary.progress', { on: summary.on, total: summary.total })}</p>
                    )}
                    <p className="igr:mt-0.5 igr:text-support igr:text-muted-foreground">{t('connections.hub.summary.help')}</p>
                </div>
                <div className="igr:w-full igr:sm:w-48">
                    <Progress value={loading ? 0 : summary.on / Math.max(1, summary.total)} tone={summary.on === summary.total ? 'success' : 'brand'} label={t('connections.hub.summary.label')} />
                </div>
            </Card>
            <div className="igr:space-y-10">
                {groups.map((group) => (
                    <section key={group.key} aria-labelledby={`grupo-${group.key}`}>
                        <div className="igr:mb-4">
                            <h2 id={`grupo-${group.key}`} className="igr:text-section igr:font-semibold igr:tracking-tight">
                                {t(`connections.hub.groups.${group.key}.title`)}
                            </h2>
                            <p className="igr:text-support igr:text-muted-foreground">{t(`connections.hub.groups.${group.key}.description${group.key === 'web' && services ? 'Services' : ''}`, { defaultValue: t(`connections.hub.groups.${group.key}.description`) })}</p>
                        </div>
                        <div className="igr:grid igr:gap-3 igr:md:grid-cols-2 igr:xl:grid-cols-3">
                            {group.cards.map((key) => (
                                <ConnectionCard key={key} cardKey={key} status={statuses[key]} onOpen={() => setCard(key)} />
                            ))}
                        </div>
                    </section>
                ))}
            </div>
            <Sheet open={card !== null} onOpenChange={(o) => !o && setCard(null)}>
                <SheetContent className="igr:sm:max-w-lg">
                    {card !== null ? (
                        <>
                            <SheetHeader>
                                <div className="igr:flex igr:items-center igr:gap-3">
                                    <CardMark cardKey={card} />
                                    <div className="igr:min-w-0">
                                        <SheetTitle>{t(`connections.hub.cards.${card}.name`)}</SheetTitle>
                                        {statuses[card] ? <StatusLine status={statuses[card]} /> : null}
                                    </div>
                                </div>
                                <SheetDescription className="igr:mt-2">{t(`connections.hub.cards.${card}.allows`)}</SheetDescription>
                            </SheetHeader>
                            <SheetBody>
                                {card === 'web' ? (
                                    <WebSheet
                                        sites={sites.data}
                                        connectOpen={params.get('conectar') === '1'}
                                        onConnectOpen={(o) =>
                                            setParams(
                                                (p) => {
                                                    if (o) p.set('conectar', '1');
                                                    else p.delete('conectar');
                                                    return p;
                                                },
                                                { replace: true },
                                            )
                                        }
                                        onCapture={() => setCard('capture')}
                                    />
                                ) : null}
                                {card === 'capture' ? <CaptureSheet /> : null}
                                {card === 'whatsapp' ? <WhatsAppSheet accounts={accounts ?? []} meta={channels.data?.meta} signup={provider('meta_whatsapp')} /> : null}
                                {card === 'messenger' || card === 'instagram' ? <SocialSheet kind={card} accounts={accounts ?? []} meta={provider('meta')} onPick={setChosen} /> : null}
                                {card === 'email' ? <EmailSheet accounts={accounts ?? []} meta={channels.data?.meta} /> : null}
                                {card === 'sms' ? <SmsSheet accounts={accounts ?? []} /> : null}
                                {card === 'meta' || card === 'google' || card === 'tiktok' ? (
                                    <AdsSheet provider={card} info={provider(card)} destination={destinationFor(dest, card)} canSeeDestinations={canSeeDestinations} onPick={setChosen} />
                                ) : null}
                                {card === 'calendar' ? <CalendarSheet count={list.data?.calendar_connections ?? 0} /> : null}
                            </SheetBody>
                        </>
                    ) : null}
                </SheetContent>
            </Sheet>
            <AssetsDialog connection={picking} onClose={closePicker} />
        </div>
    );
}

/** El mosaico de la tarjeta: ícono por categoría, o la inicial de la plataforma de anuncios (sin logos de marca). */
function CardMark({ cardKey }: { cardKey: CardKey }) {
    if (cardKey === 'meta' || cardKey === 'google' || cardKey === 'tiktok') {
        return <ProviderMark provider={cardKey} />;
    }
    const icon = ICONS[cardKey];
    return icon ? <IconTile icon={icon.icon} tone={icon.tone} /> : null;
}

/** El estado en palabras, con un punto de color (nunca el color solo). */
function StatusLine({ status }: { status: CardStatus | undefined }) {
    const { t } = useTranslation();
    if (!status) return <Skeleton className="igr:mt-1 igr:h-3.5 igr:w-28" />;
    const tone = status.tone === 'on' ? 'success' : status.tone === 'attention' ? 'warning' : 'muted';
    return (
        <p className={cn('igr:mt-0.5 igr:flex igr:items-center igr:gap-1.5 igr:text-xs igr:font-medium', status.tone === 'on' ? 'igr:text-success' : status.tone === 'attention' ? 'igr:text-warning' : 'igr:text-muted-foreground')}>
            <StatusDot tone={tone} />
            {t(`connections.hub.status.${status.label}`, status.params ?? {})}
        </p>
    );
}

function ConnectionCard({ cardKey, status, onOpen }: { cardKey: CardKey; status: CardStatus | undefined; onOpen: () => void }) {
    const { t } = useTranslation();
    const action = !status ? 'manage' : status.tone === 'off' ? 'connect' : status.tone === 'attention' ? 'fix' : 'manage';
    return (
        <Card className={cn('igr:flex igr:flex-col igr:p-4 igr:transition-shadow igr:hover:shadow-md igr:sm:p-5', status?.tone === 'attention' && 'igr:border-warning/40')}>
            <div className="igr:flex igr:items-start igr:gap-3">
                <CardMark cardKey={cardKey} />
                <div className="igr:min-w-0 igr:flex-1">
                    <h3 className="igr:text-[15px] igr:font-semibold igr:tracking-tight">{t(`connections.hub.cards.${cardKey}.name`)}</h3>
                    <StatusLine status={status} />
                </div>
            </div>
            <p className="igr:mt-3 igr:flex-1 igr:text-support igr:text-muted-foreground">{t(`connections.hub.cards.${cardKey}.allows`)}</p>
            {status?.detail ? <p className="igr:mt-2 igr:truncate igr:text-caption igr:text-muted-foreground">{status.detail}</p> : null}
            <div className="igr:mt-4">
                <Button size="sm" variant={action === 'connect' && ESSENTIALS.includes(cardKey) ? 'default' : 'outline'} onClick={onOpen} aria-label={`${t(`connections.hub.actions.${action === 'connect' && cardKey === 'capture' ? 'capture' : action}`)}: ${t(`connections.hub.cards.${cardKey}.name`)}`}>
                    {t(`connections.hub.actions.${action === 'connect' && cardKey === 'capture' ? 'capture' : action}`)}
                </Button>
            </div>
        </Card>
    );
}
