import { ArrowRight, Check, Copy, Download, ExternalLink, FileText, Globe, LogIn, Plug, Plus, QrCode, ShoppingCart } from 'lucide-react';
import { type ReactNode, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import type { Site } from '@/api/types';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Callout } from '@/components/ui/callout';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ErrorState } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { Switch } from '@/components/ui/switch';
import { qrDownloadUrl, useLandingMutations, useLandingPages } from '@/features/capture/api';
import { AccountCard } from '@/features/channels/AccountCard';
import { type ChannelAccount, type ChannelsResponse } from '@/features/channels/api';
import { EmailDialog, platformEmailAllowed } from '@/features/channels/dialogs/EmailDialog';
import { SocialDialog } from '@/features/channels/dialogs/SocialDialog';
import { TestSendDialog } from '@/features/channels/dialogs/TestSendDialog';
import { TwilioDialog } from '@/features/channels/dialogs/TwilioDialog';
import { WhatsAppDialog } from '@/features/channels/dialogs/WhatsAppDialog';
import { ZenderDialog } from '@/features/channels/dialogs/ZenderDialog';
import { type Destination, useSaveDestination } from '@/features/measurement/api';
import { ConnectManualDialog } from '@/features/sites/ConnectManualDialog';
import { PluginDownloadButton } from '@/features/sites/PluginDownload';
import { causeAction, causeTitle, SiteHealthBadge } from '@/features/sites/SiteHealth';
import { useCan, useWorkspace } from '@/hooks/useWorkspace';
import { isApiError } from '@/lib/api';
import { formatRelative } from '@/lib/format';
import { toast } from '@/stores/toast';
import type { Connection, ProviderInfo, RedirectProvider } from './api';
import { useStartConnection } from './api';
import { type CardKey, sendsCtaClicks, toggleCtaClicks } from './hub';
import { ProviderConnection, ProviderUnavailable, WhatsAppConnections } from './ProviderParts';
import { WhatsAppOneClick } from './WhatsAppOneClick';

/* El detalle de cada tarjeta de Conexiones, en la hoja lateral: qué permite, su estado y cómo conectarla o arreglarla. */

/** Un bloque de la hoja con su título. */
export function SheetSection({ title, children, action }: { title: ReactNode; children: ReactNode; action?: ReactNode }) {
    return (
        <section className="igr:space-y-3 igr:border-t igr:py-5 igr:first:border-t-0 igr:first:pt-0">
            <div className="igr:flex igr:items-center igr:justify-between igr:gap-2">
                <h3 className="igr:text-caption igr:font-semibold igr:tracking-wide igr:text-muted-foreground igr:uppercase">{title}</h3>
                {action}
            </div>
            {children}
        </section>
    );
}

/** Lo que permite la conexión, en viñetas (`connections.hub.cards.<clave>.bullets`). */
export function Allows({ card, services }: { card: CardKey; services?: boolean }) {
    const { t } = useTranslation();
    const raw = t(`connections.hub.cards.${card}.${services ? 'bulletsServices' : 'bullets'}`, { returnObjects: true, defaultValue: t(`connections.hub.cards.${card}.bullets`, { returnObjects: true }) });
    const bullets = Array.isArray(raw) ? (raw as string[]) : [];
    return (
        <ul className="igr:space-y-1.5 igr:text-support">
            {bullets.map((b) => (
                <li key={b} className="igr:flex igr:gap-2">
                    <Check className="igr:mt-0.5 igr:size-4 igr:shrink-0 igr:text-success" aria-hidden />
                    <span>{b}</span>
                </li>
            ))}
        </ul>
    );
}

/* ——— Tu web ——— */

export function WebSheet({ sites, connectOpen, onConnectOpen, onCapture }: { sites: Site[] | undefined; connectOpen: boolean; onConnectOpen: (open: boolean) => void; onCapture: () => void }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const canManage = useCan('manageSites');
    const list = sites ?? [];
    return (
        <>
            <SheetSection title={t('connections.hub.sheet.allows')}>
                <Allows card="web" />
            </SheetSection>
            {sites === undefined ? (
                <Skeleton className="igr:h-24" />
            ) : list.length > 0 ? (
                <SheetSection title={t('connections.hub.sheet.yourSites')}>
                    <ul className="igr:space-y-2">
                        {list.map((site) => {
                            const cause = site.status !== 'disconnected' ? site.health_status?.causes[0] : undefined;
                            return (
                                <li key={site.id} className="igr:space-y-2 igr:rounded-lg igr:border igr:p-3">
                                    <div className="igr:flex igr:items-start igr:gap-2.5">
                                        <span className="igr:flex igr:size-8 igr:shrink-0 igr:items-center igr:justify-center igr:rounded-md igr:bg-muted">
                                            {site.platform === 'woocommerce' ? <ShoppingCart className="igr:size-4" aria-hidden /> : <Globe className="igr:size-4" aria-hidden />}
                                        </span>
                                        <div className="igr:min-w-0 igr:flex-1">
                                            <p className="igr:truncate igr:text-support igr:font-medium">{site.name ?? site.host}</p>
                                            <p className="igr:truncate igr:text-xs igr:text-muted-foreground">
                                                {site.host}
                                                {site.plugin_version ? ` · ${t('connections.hub.sheet.plugin', { version: site.plugin_version })}` : ''}
                                                {site.last_event_at ? ` · ${t('connections.hub.sheet.lastData', { when: formatRelative(site.last_event_at, workspace.locale) })}` : ''}
                                            </p>
                                        </div>
                                        <SiteHealthBadge site={site} />
                                    </div>
                                    {cause ? (
                                        <p className="igr:rounded-md igr:bg-muted/60 igr:px-2.5 igr:py-2 igr:text-xs igr:text-muted-foreground">
                                            <span className="igr:font-medium igr:text-foreground">{causeTitle(t, cause)}</span> {causeAction(t, cause)}
                                        </p>
                                    ) : null}
                                    <Button asChild size="sm" variant="outline">
                                        <Link to={`/w/${workspace.id}/sitios/${site.id}`}>
                                            {t('connections.hub.sheet.siteDetail')}
                                            <ArrowRight aria-hidden />
                                        </Link>
                                    </Button>
                                </li>
                            );
                        })}
                    </ul>
                </SheetSection>
            ) : (
                <SheetSection title={t('connections.hub.sheet.howTo')}>
                    <ol className="igr:space-y-2 igr:text-support">
                        {(['step1', 'step2', 'step3'] as const).map((k, i) => (
                            <li key={k} className="igr:flex igr:gap-2.5">
                                <span className="igr:flex igr:size-5 igr:shrink-0 igr:items-center igr:justify-center igr:rounded-full igr:bg-brand-soft igr:text-[11px] igr:font-semibold igr:text-brand-soft-foreground">{i + 1}</span>
                                <span>{t(`connections.hub.sheet.web.${k}`)}</span>
                            </li>
                        ))}
                    </ol>
                </SheetSection>
            )}
            <SheetSection title={list.length > 0 ? t('connections.hub.sheet.another') : t('connections.hub.sheet.connect')}>
                <div className="igr:flex igr:flex-wrap igr:gap-2">
                    {canManage ? (
                        <Button onClick={() => onConnectOpen(true)} variant={list.length > 0 ? 'outline' : 'default'}>
                            <Plug aria-hidden />
                            {list.length > 0 ? t('connections.hub.sheet.web.another') : t('connections.hub.sheet.web.connect')}
                        </Button>
                    ) : (
                        <p className="igr:text-support igr:text-muted-foreground">{t('ui.locked.askAdmin.site')}</p>
                    )}
                    <PluginDownloadButton />
                </div>
            </SheetSection>
            <SheetSection title={t('connections.hub.sheet.web.noWordpress')}>
                <p className="igr:text-support igr:text-muted-foreground">{t('connections.hub.sheet.web.noWordpressHelp')}</p>
                <Button variant="outline" onClick={onCapture}>
                    <QrCode aria-hidden />
                    {t('connections.hub.sheet.web.toCapture')}
                </Button>
            </SheetSection>
            {canManage ? <ConnectManualDialog workspaceId={workspace.id} open={connectOpen} onOpenChange={onConnectOpen} /> : null}
        </>
    );
}

/* ——— Página de captura y QR (sin WordPress) ——— */

export function CaptureSheet() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const canManage = useCan('manageMarketing');
    const pages = useLandingPages(workspace.id);
    const actions = useLandingMutations(workspace.id);
    const [copied, setCopied] = useState<string | null>(null);
    const list = (pages.data?.data ?? []).filter((p) => p.status !== 'archived');
    const fail = (e: unknown) => toast.error(t('connections.hub.sheet.capture.error'), isApiError(e) ? e.message : undefined);
    const create = () =>
        actions.create.mutate(
            { name: workspace.name },
            {
                onSuccess: (page) =>
                    actions.publish.mutate(page.id, {
                        onSuccess: () => toast.success(t('connections.hub.sheet.capture.created')),
                        onError: fail,
                    }),
                onError: fail,
            },
        );
    const copy = (url: string) => {
        void navigator.clipboard.writeText(url).then(() => {
            setCopied(url);
            window.setTimeout(() => setCopied(null), 2000);
        });
    };

    return (
        <>
            <SheetSection title={t('connections.hub.sheet.allows')}>
                <Allows card="capture" />
            </SheetSection>
            <SheetSection title={t('connections.hub.sheet.capture.yours')}>
                {pages.isError ? (
                    <ErrorState error={pages.error} onRetry={() => void pages.refetch()} />
                ) : pages.isPending ? (
                    <Skeleton className="igr:h-24" />
                ) : list.length === 0 ? (
                    <div className="igr:space-y-3">
                        <p className="igr:text-support igr:text-muted-foreground">{t('connections.hub.sheet.capture.none')}</p>
                        {canManage ? (
                            <Button onClick={create} loading={actions.create.isPending || actions.publish.isPending}>
                                <Plus aria-hidden />
                                {t('connections.hub.sheet.capture.create')}
                            </Button>
                        ) : null}
                    </div>
                ) : (
                    <ul className="igr:space-y-2">
                        {list.map((page) => (
                            <li key={page.id} className="igr:space-y-2.5 igr:rounded-lg igr:border igr:p-3">
                                <div className="igr:flex igr:items-center igr:justify-between igr:gap-2">
                                    <p className="igr:truncate igr:text-support igr:font-medium">{page.name}</p>
                                    <Badge variant={page.status === 'published' ? 'success' : 'outline'}>{t(`connections.hub.sheet.capture.status.${page.status}`)}</Badge>
                                </div>
                                <div className="igr:flex igr:gap-2">
                                    <Label htmlFor={`url-${page.id}`} className="igr:sr-only">
                                        {t('connections.hub.sheet.capture.url')}
                                    </Label>
                                    <Input id={`url-${page.id}`} readOnly value={page.url} className="igr:font-mono igr:text-xs" />
                                    <Button size="icon" variant="outline" onClick={() => copy(page.url)} aria-label={t('connections.hub.sheet.capture.copy')}>
                                        {copied === page.url ? <Check aria-hidden /> : <Copy aria-hidden />}
                                    </Button>
                                </div>
                                <div className="igr:flex igr:flex-wrap igr:gap-2">
                                    {page.status === 'published' ? (
                                        <Button asChild size="sm" variant="outline">
                                            <a href={page.url} target="_blank" rel="noopener noreferrer">
                                                <ExternalLink aria-hidden />
                                                {t('connections.hub.sheet.capture.open')}
                                            </a>
                                        </Button>
                                    ) : canManage ? (
                                        <Button size="sm" loading={actions.publish.isPending} onClick={() => actions.publish.mutate(page.id, { onError: fail })}>
                                            {t('connections.hub.sheet.capture.publish')}
                                        </Button>
                                    ) : null}
                                    <Button asChild size="sm" variant="outline">
                                        <a href={qrDownloadUrl(page.qr.png, 1024)} download>
                                            <Download aria-hidden />
                                            {t('connections.hub.sheet.capture.qr')}
                                        </a>
                                    </Button>
                                </div>
                                {page.stats ? (
                                    <p className="igr:text-xs igr:text-muted-foreground igr:tabular-nums">
                                        {t('connections.hub.sheet.capture.stats', { views: page.stats.views, leads: page.stats.submissions })}
                                    </p>
                                ) : null}
                            </li>
                        ))}
                    </ul>
                )}
            </SheetSection>
        </>
    );
}

/* ——— Conversaciones: WhatsApp, Messenger, Instagram ——— */

function Accounts({ accounts, onTest, onDns }: { accounts: ChannelAccount[]; onTest: (a: ChannelAccount) => void; onDns?: (a: ChannelAccount) => void }) {
    return (
        <div className="igr:space-y-2">
            {accounts.map((a) => (
                <AccountCard key={a.id} account={a} onTest={() => onTest(a)} onDns={() => onDns?.(a)} />
            ))}
        </div>
    );
}

export function WhatsAppSheet({ accounts, meta, signup }: { accounts: ChannelAccount[]; meta: ChannelsResponse['meta'] | undefined; signup: ProviderInfo | undefined }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const canManage = useCan('manageWorkspace');
    const canMarketing = useCan('manageMarketing');
    const [dialog, setDialog] = useState<'manual' | 'zender' | null>(null);
    const [testFor, setTestFor] = useState<ChannelAccount | null>(null);
    const mine = accounts.filter((a) => a.channel === 'whatsapp');
    const configured = signup?.configured ?? meta?.embedded_signup.configured ?? false;
    return (
        <>
            <SheetSection title={t('connections.hub.sheet.allows')}>
                <Allows card="whatsapp" />
            </SheetSection>
            {mine.length > 0 ? (
                <SheetSection
                    title={t('connections.hub.sheet.numbers')}
                    action={
                        canMarketing ? (
                            <Button asChild size="sm" variant="ghost">
                                <Link to={`/w/${workspace.id}/canales/plantillas-whatsapp`}>
                                    <FileText aria-hidden />
                                    {t('channels.actions.templates')}
                                </Link>
                            </Button>
                        ) : null
                    }
                >
                    <Accounts accounts={mine} onTest={setTestFor} />
                    {signup ? <WhatsAppConnections info={signup} /> : null}
                </SheetSection>
            ) : null}
            <SheetSection title={mine.length > 0 ? t('connections.hub.sheet.whatsapp.another') : t('connections.hub.sheet.connect')}>
                {canManage ? (
                    <div className="igr:space-y-3">
                        {configured ? (
                            <WhatsAppOneClick configured onConnected={(a) => toast.success(t('connections.toast.whatsapp', { number: a.identity ?? a.display_name }))} />
                        ) : signup ? (
                            <ProviderUnavailable info={signup} />
                        ) : null}
                        <Callout tone="info" title={t('connections.hub.sheet.whatsapp.coexistenceTitle')}>
                            {t('connections.hub.sheet.whatsapp.coexistence')}
                        </Callout>
                        <div className="igr:flex igr:flex-wrap igr:gap-2">
                            <Button variant="outline" size="sm" onClick={() => setDialog('manual')}>
                                {t('connections.hub.sheet.whatsapp.manual')}
                            </Button>
                        </div>
                        <p className="igr:text-xs igr:text-muted-foreground">{t('connections.hub.sheet.whatsapp.cost')}</p>
                    </div>
                ) : (
                    <p className="igr:text-support igr:text-muted-foreground">{t('ui.locked.askAdmin.channel')}</p>
                )}
            </SheetSection>
            <WhatsAppDialog open={dialog === 'manual'} meta={meta} onClose={() => setDialog(null)} onZender={() => setDialog('zender')} />
            <ZenderDialog open={dialog === 'zender'} caps={meta?.zender_caps ?? { hourly: 30, daily: 200 }} onClose={() => setDialog(null)} />
            <TestSendDialog account={testFor} onClose={() => setTestFor(null)} />
        </>
    );
}

export function SocialSheet({ kind, accounts, meta, onPick }: { kind: 'messenger' | 'instagram'; accounts: ChannelAccount[]; meta: ProviderInfo | undefined; onPick: (c: Connection) => void }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const canManage = useCan('manageWorkspace');
    const start = useStartConnection(workspace.id);
    const [manual, setManual] = useState(false);
    const [testFor, setTestFor] = useState<ChannelAccount | null>(null);
    const mine = accounts.filter((a) => a.channel === kind);
    const connection = meta?.connections.find((c) => c.status === 'active') ?? null;
    return (
        <>
            <SheetSection title={t('connections.hub.sheet.allows')}>
                <Allows card={kind} />
            </SheetSection>
            {mine.length > 0 ? (
                <SheetSection title={t(`connections.hub.sheet.${kind}.accounts`)}>
                    <Accounts accounts={mine} onTest={setTestFor} />
                </SheetSection>
            ) : null}
            <SheetSection title={mine.length > 0 ? t('connections.hub.sheet.another') : t('connections.hub.sheet.connect')}>
                {canManage ? (
                    <div className="igr:space-y-3">
                        <p className="igr:text-support igr:text-muted-foreground">{t(`connections.hub.sheet.${kind}.how`)}</p>
                        <div className="igr:flex igr:flex-wrap igr:gap-2">
                            {meta?.configured ? (
                                connection ? (
                                    <Button onClick={() => onPick(connection)}>{t('connections.hub.sheet.social.pickPage')}</Button>
                                ) : (
                                    <Button
                                        loading={start.isPending}
                                        onClick={() => start.mutate({ provider: 'meta', returnTo: 'conexiones' }, { onError: (e) => toast.error(t('connections.toast.startError'), isApiError(e) ? e.message : undefined) })}
                                    >
                                        <LogIn aria-hidden />
                                        {t('connections.hub.sheet.social.withMeta')}
                                    </Button>
                                )
                            ) : null}
                            <Button variant="outline" onClick={() => setManual(true)}>
                                {t('connections.hub.sheet.social.manual')}
                            </Button>
                        </div>
                        {meta && !meta.configured ? <ProviderUnavailable info={meta} /> : null}
                    </div>
                ) : (
                    <p className="igr:text-support igr:text-muted-foreground">{t('ui.locked.askAdmin.channel')}</p>
                )}
            </SheetSection>
            <SocialDialog kind={kind} open={manual} onClose={() => setManual(false)} />
            <TestSendDialog account={testFor} onClose={() => setTestFor(null)} />
        </>
    );
}

/* ——— Correo y SMS ——— */

export function EmailSheet({ accounts, meta }: { accounts: ChannelAccount[]; meta: ChannelsResponse['meta'] | undefined }) {
    const { t } = useTranslation();
    const canManage = useCan('manageWorkspace');
    const [open, setOpen] = useState(false);
    const [dnsFor, setDnsFor] = useState<ChannelAccount | null>(null);
    const [testFor, setTestFor] = useState<ChannelAccount | null>(null);
    const mine = accounts.filter((a) => a.channel === 'email');
    const platform = platformEmailAllowed(meta);
    return (
        <>
            <SheetSection title={t('connections.hub.sheet.allows')}>
                <Allows card="email" />
            </SheetSection>
            {mine.length > 0 ? (
                <SheetSection title={t('connections.hub.sheet.email.accounts')}>
                    <Accounts accounts={mine} onTest={setTestFor} onDns={setDnsFor} />
                </SheetSection>
            ) : null}
            <SheetSection title={mine.length > 0 ? t('connections.hub.sheet.another') : t('connections.hub.sheet.connect')}>
                <div className="igr:grid igr:gap-2">
                    <div className="igr:rounded-lg igr:border igr:p-3">
                        <p className="igr:flex igr:items-center igr:gap-2 igr:text-support igr:font-medium">
                            {t('connections.hub.sheet.email.platform')}
                            {platform ? <Badge variant="brand">{t('connections.hub.sheet.email.recommended')}</Badge> : <Badge variant="outline">{t('connections.hub.sheet.email.notInPlan')}</Badge>}
                        </p>
                        <p className="igr:mt-1 igr:text-xs igr:text-muted-foreground">{t('connections.hub.sheet.email.platformHelp')}</p>
                    </div>
                    <div className="igr:rounded-lg igr:border igr:p-3">
                        <p className="igr:text-support igr:font-medium">{t('connections.hub.sheet.email.own')}</p>
                        <p className="igr:mt-1 igr:text-xs igr:text-muted-foreground">{t('connections.hub.sheet.email.ownHelp')}</p>
                    </div>
                </div>
                {canManage ? (
                    <Button onClick={() => setOpen(true)}>
                        <Plus aria-hidden />
                        {t('connections.hub.sheet.email.connect')}
                    </Button>
                ) : (
                    <p className="igr:text-support igr:text-muted-foreground">{t('connections.alerts.askAdmin')}</p>
                )}
            </SheetSection>
            <EmailDialog
                open={open || dnsFor !== null}
                dnsFor={dnsFor}
                onClose={() => {
                    setOpen(false);
                    setDnsFor(null);
                }}
            />
            <TestSendDialog account={testFor} onClose={() => setTestFor(null)} />
        </>
    );
}

export function SmsSheet({ accounts }: { accounts: ChannelAccount[] }) {
    const { t } = useTranslation();
    const canManage = useCan('manageWorkspace');
    const [open, setOpen] = useState(false);
    const [testFor, setTestFor] = useState<ChannelAccount | null>(null);
    const mine = accounts.filter((a) => a.channel === 'sms');
    return (
        <>
            <SheetSection title={t('connections.hub.sheet.allows')}>
                <Allows card="sms" />
            </SheetSection>
            {mine.length > 0 ? (
                <SheetSection title={t('connections.hub.sheet.sms.accounts')}>
                    <Accounts accounts={mine} onTest={setTestFor} />
                </SheetSection>
            ) : null}
            <SheetSection title={mine.length > 0 ? t('connections.hub.sheet.another') : t('connections.hub.sheet.connect')}>
                <p className="igr:text-support igr:text-muted-foreground">{t('channels.empty.sms.description')}</p>
                {canManage ? (
                    <Button onClick={() => setOpen(true)} variant={mine.length > 0 ? 'outline' : 'default'}>
                        <Plus aria-hidden />
                        {t('channels.actions.connectSms')}
                    </Button>
                ) : null}
            </SheetSection>
            <TwilioDialog open={open} onClose={() => setOpen(false)} />
            <TestSendDialog account={testFor} onClose={() => setTestFor(null)} />
        </>
    );
}

/* ——— Anuncios: Meta, Google, TikTok ——— */

export function AdsSheet({
    provider,
    info,
    destination,
    canSeeDestinations,
    onPick,
}: {
    provider: RedirectProvider;
    info: ProviderInfo | undefined;
    destination: Destination | undefined;
    canSeeDestinations: boolean;
    onPick: (c: Connection) => void;
}) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const canManage = useCan('manageWorkspace');
    const save = useSaveDestination(workspace.id);
    const connection = info?.connections[0] ?? null;
    const cta = sendsCtaClicks(destination);
    const setCta = (on: boolean) =>
        destination &&
        save.mutate(
            { id: destination.id, input: { events_enabled: toggleCtaClicks(destination.events_enabled, on) } },
            {
                onSuccess: () => toast.success(t(on ? 'connections.hub.sheet.ads.ctaOn' : 'connections.hub.sheet.ads.ctaOff')),
                onError: (e) => toast.error(t('connections.hub.sheet.ads.saveError'), isApiError(e) ? e.message : undefined),
            },
        );
    const contactAction = destination?.credentials.conversion_action_contact ?? null;

    return (
        <>
            <SheetSection title={t('connections.hub.sheet.allows')}>
                <Allows card={provider} />
            </SheetSection>
            <SheetSection title={t('connections.hub.sheet.ads.account')}>
                {info && !info.configured && connection === null && destination ? (
                    <div className="igr:flex igr:flex-wrap igr:items-center igr:justify-between igr:gap-2">
                        <p className="igr:text-support igr:text-muted-foreground">{t('connections.hub.sheet.ads.manualConnected', { id: destination.pixel_id ?? '—' })}</p>
                        <Button asChild size="sm" variant="outline">
                            <Link to={`/w/${workspace.id}/medicion`}>{t('connections.hub.sheet.ads.editManual')}</Link>
                        </Button>
                    </div>
                ) : info && !info.configured && connection === null ? (
                    <ProviderUnavailable info={info} manual={{ to: 'medicion', label: t('connections.hub.sheet.ads.manual') }} />
                ) : info ? (
                    <ProviderConnection info={{ ...info, key: provider }} onPick={onPick} connectLabel={t(`connections.hub.sheet.ads.connect_${provider}`)} />
                ) : (
                    <Skeleton className="igr:h-16" />
                )}
            </SheetSection>
            {destination ? (
                <SheetSection title={t('connections.hub.sheet.ads.sending')}>
                    <div className="igr:flex igr:flex-wrap igr:items-center igr:gap-2 igr:text-support">
                        <Badge variant={destination.status === 'active' && !destination.last_error ? 'success' : 'warning'}>
                            {destination.status === 'active' ? t('connections.hub.sheet.ads.active') : t('connections.hub.sheet.ads.paused')}
                        </Badge>
                        <span className="igr:text-muted-foreground">
                            {destination.last_delivered_at ? t('connections.hub.sheet.ads.lastSent', { when: formatRelative(destination.last_delivered_at, workspace.locale) }) : t('connections.hub.sheet.ads.notYet')}
                        </span>
                    </div>
                    {destination.last_error ? <Callout tone="warning">{destination.last_error}</Callout> : null}
                    <div className="igr:rounded-lg igr:border igr:p-3">
                        <Switch
                            checked={cta}
                            disabled={!canManage || save.isPending}
                            onCheckedChange={setCta}
                            label={t('connections.hub.sheet.ads.cta')}
                            description={t(`connections.hub.sheet.ads.ctaHelp_${provider}`)}
                        />
                        {provider === 'google' && cta ? (
                            <div className="igr:mt-3 igr:ml-14 igr:space-y-2 igr:text-xs">
                                <p className={contactAction ? 'igr:text-muted-foreground' : 'igr:text-warning'}>
                                    {contactAction ? t('connections.hub.sheet.ads.contactAction', { id: contactAction }) : t('connections.hub.sheet.ads.contactMissing')}
                                </p>
                                {canManage && connection?.status === 'active' ? (
                                    <Button size="sm" variant="outline" onClick={() => onPick(connection)}>
                                        {t('connections.hub.sheet.ads.pickContact')}
                                    </Button>
                                ) : canManage ? (
                                    <Button asChild size="sm" variant="outline">
                                        <Link to={`/w/${workspace.id}/medicion`}>{t('connections.hub.sheet.ads.pickContact')}</Link>
                                    </Button>
                                ) : null}
                            </div>
                        ) : null}
                    </div>
                </SheetSection>
            ) : null}
            {canSeeDestinations ? (
                <SheetSection title={t('connections.hub.sheet.ads.more')}>
                    <div className="igr:flex igr:flex-wrap igr:gap-2">
                        <Button asChild size="sm" variant="outline">
                            <Link to={`/w/${workspace.id}/medicion?tab=entregas`}>{t('connections.hub.sheet.ads.deliveries')}</Link>
                        </Button>
                        <Button asChild size="sm" variant="outline">
                            <Link to={`/w/${workspace.id}/medicion?tab=audiencias`}>{t('connections.hub.sheet.ads.audiences')}</Link>
                        </Button>
                    </div>
                </SheetSection>
            ) : null}
        </>
    );
}

/* ——— Agenda ——— */

export function CalendarSheet({ count }: { count: number }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    return (
        <>
            <SheetSection title={t('connections.hub.sheet.allows')}>
                <Allows card="calendar" />
            </SheetSection>
            <SheetSection title={t('connections.hub.sheet.calendar.who')}>
                <p className="igr:text-support igr:text-muted-foreground">{count > 0 ? t('connections.hub.sheet.calendar.count', { count }) : t('connections.hub.sheet.calendar.none')}</p>
                <Button asChild>
                    <Link to={`/w/${workspace.id}/agenda?pestana=personas`}>
                        {t('connections.hub.sheet.calendar.go')}
                        <ArrowRight aria-hidden />
                    </Link>
                </Button>
            </SheetSection>
        </>
    );
}
