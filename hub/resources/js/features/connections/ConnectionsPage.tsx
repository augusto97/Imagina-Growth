import { CircleAlert, ListChecks, RefreshCw, Unplug } from 'lucide-react';
import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useSearchParams } from 'react-router';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { ErrorState, PageHeader } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { useCan, useWorkspace } from '@/hooks/useWorkspace';
import { isApiError } from '@/lib/api';
import { formatDate, formatRelative } from '@/lib/format';
import { toast } from '@/stores/toast';
import { ConnectionAlertsNotice } from './AlertsNotice';
import { type Connection, type ProviderInfo, type ProviderKey, useCheckConnection, useConnections, useDisconnectConnection, useStartConnection, useWhatsAppSignupApi } from './api';
import { ProviderMark, StatusBadge } from './bits';
import { AssetsDialog } from './Pickers';
import { WhatsAppOneClick } from './WhatsAppOneClick';

const ORDER: ProviderKey[] = ['meta_whatsapp', 'meta', 'google', 'tiktok'];
const MANUAL_PATH: Record<ProviderKey, string> = { meta_whatsapp: 'canales?tab=whatsapp', meta: 'medicion', google: 'medicion', tiktok: 'medicion' };

/** Plataforma → Ajustes, en la sección de la app que falta (`meta.app_id` → `meta`). Solo la ve quien administra la plataforma. */
function settingsPath(info: ProviderInfo): string {
    const section = info.missing[0]?.split('.')[0] ?? (info.key === 'meta_whatsapp' ? 'meta' : info.key);
    return `plataforma?pestana=ajustes&seccion=${section}`;
}

/**
 * Configuración → Conexiones: Meta (WhatsApp, anuncios, Messenger e Instagram), Google (Ads, Analytics) y TikTok con
 * un clic. Si la instalación no tiene la app de un proveedor, la tarjeta dice qué falta y lleva a los campos manuales.
 */
export function ConnectionsPage() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const list = useConnections(workspace.id);
    const [params, setParams] = useSearchParams();
    const [chosen, setChosen] = useState<Connection | null>(null);

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
                return p;
            },
            { replace: true },
        );
    }, [status, params, setParams, t]);

    const pickId = params.get('elegir');
    const fromUrl = pickId !== null ? (list.data?.providers.flatMap((p) => p.connections).find((c) => c.id === pickId && c.provider !== 'meta_whatsapp') ?? null) : null;
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

    return (
        <div>
            <PageHeader title={t('connections.title')} description={t('connections.description')} />
            <ConnectionAlertsNotice className="igr:mb-4" onConnectionsPage />
            {list.isError ? (
                <ErrorState error={list.error} onRetry={() => void list.refetch()} />
            ) : list.isPending ? (
                <div className="igr:grid igr:gap-3 igr:md:grid-cols-2">
                    {ORDER.map((k) => (
                        <Skeleton key={k} className="igr:h-40" />
                    ))}
                </div>
            ) : (
                <div className="igr:grid igr:gap-3 igr:md:grid-cols-2">
                    {ORDER.map((key) => {
                        const info = list.data.providers.find((p) => p.key === key);
                        return info ? <ProviderCard key={key} info={info} onPick={setChosen} /> : null;
                    })}
                </div>
            )}
            <AssetsDialog connection={picking} onClose={closePicker} />
        </div>
    );
}

function ProviderCard({ info, onPick }: { info: ProviderInfo; onPick: (c: Connection) => void }) {
    const { t } = useTranslation();
    const { workspace, me } = useWorkspace();
    const canManage = useCan('manageWorkspace');
    const start = useStartConnection(workspace.id);
    const check = useCheckConnection(workspace.id);
    const [disconnecting, setDisconnecting] = useState<Connection | null>(null);
    const retry = useWhatsAppSignupApi(workspace.id).chooseNumber;
    const retryNumber = (connectionId: string, phoneId: string) =>
        retry.mutate(
            { id: connectionId, phone_number_id: phoneId },
            {
                onSuccess: (r) =>
                    r.data.status === 'active'
                        ? toast.success(t('connections.toast.whatsapp', { number: r.data.identity ?? r.data.display_name }))
                        : toast.error(t('connections.toast.retryError'), r.data.last_error ?? undefined),
                onError: (e) => toast.error(t('connections.toast.retryError'), isApiError(e) ? e.message : undefined),
            },
        );
    const connection = info.connections[0] ?? null;
    const isWhatsApp = info.key === 'meta_whatsapp';
    const state = !info.configured ? 'unavailable' : isWhatsApp ? (info.connections.length > 0 ? 'active' : 'none') : (connection?.status ?? 'none');

    const connect = () => {
        if (info.key === 'meta_whatsapp') return;
        start.mutate({ provider: info.key, returnTo: 'conexiones' }, { onError: (e) => toast.error(t('connections.toast.startError'), isApiError(e) ? e.message : undefined) });
    };

    return (
        <Card id={`proveedor-${info.key}`} className="igr:scroll-mt-4">
            <CardContent className="igr:space-y-3 igr:p-4">
                <div className="igr:flex igr:items-start igr:gap-3">
                    <ProviderMark provider={info.key} />
                    <div className="igr:min-w-0 igr:flex-1">
                        <div className="igr:flex igr:flex-wrap igr:items-center igr:gap-2">
                            <h2 className="igr:text-sm igr:font-semibold">{t(`connections.provider.${info.key}.name`)}</h2>
                            <StatusBadge status={state} />
                        </div>
                        <p className="igr:text-xs igr:text-muted-foreground">{t(`connections.provider.${info.key}.description`)}</p>
                    </div>
                </div>

                {!info.configured ? (
                    <div className="igr:space-y-1 igr:rounded-md igr:bg-muted igr:p-2.5 igr:text-xs igr:text-muted-foreground">
                        <p>{t('connections.unavailable', { provider: t(`connections.provider.${info.key}.name`) })}</p>
                        <p>
                            {t('connections.missing')} {info.missing.map((key) => t(`platformSettings.fields.${key}.label`, { defaultValue: key })).join(', ')}
                        </p>
                        {me.user.is_platform_admin ? (
                            <Link className="igr:block igr:text-primary igr:underline-offset-4 igr:hover:underline" to={`/w/${workspace.id}/${settingsPath(info)}`}>
                                {t('connections.configure')}
                            </Link>
                        ) : null}
                        <Link className="igr:text-primary igr:underline-offset-4 igr:hover:underline" to={`/w/${workspace.id}/${MANUAL_PATH[info.key]}`}>
                            {t('connections.manual')}
                        </Link>
                    </div>
                ) : null}

                {isWhatsApp ? (
                    <>
                        {info.connections.map((c) => (
                            <ConnectionSummary key={c.id} connection={c} />
                        ))}
                        {canManage ? (
                            <WhatsAppOneClick
                                configured={info.configured}
                                onConnected={(a) => toast.success(t('connections.toast.whatsapp', { number: a.identity ?? a.display_name }))}
                            />
                        ) : null}
                    </>
                ) : connection !== null ? (
                    <ConnectionSummary connection={connection} />
                ) : null}

                {canManage && info.configured && !isWhatsApp ? (
                    <div className="igr:flex igr:flex-wrap igr:gap-2">
                        {connection === null || connection.status !== 'active' ? (
                            <Button onClick={connect} loading={start.isPending}>
                                {t(connection === null ? 'connections.actions.connect' : 'connections.actions.reconnect')}
                            </Button>
                        ) : (
                            <Button onClick={() => onPick(connection)}>
                                <ListChecks />
                                {t('connections.actions.pick')}
                            </Button>
                        )}
                        {connection !== null ? (
                            <>
                                <Button
                                    variant="outline"
                                    loading={check.isPending}
                                    onClick={() =>
                                        check.mutate(connection.id, {
                                            onSuccess: () => toast.success(t('connections.toast.checked')),
                                            onError: (e) => toast.error(t('connections.toast.checkError'), isApiError(e) ? e.message : undefined),
                                        })
                                    }
                                >
                                    <RefreshCw />
                                    {t('connections.actions.check')}
                                </Button>
                                <Button variant="ghost" onClick={() => setDisconnecting(connection)}>
                                    <Unplug />
                                    {t('connections.actions.disconnect')}
                                </Button>
                            </>
                        ) : null}
                    </div>
                ) : null}
                {canManage && isWhatsApp && info.connections.length > 0 ? (
                    <div className="igr:flex igr:flex-wrap igr:gap-2">
                        {info.connections.map((c) => (
                            <span key={c.id} className="igr:flex igr:flex-wrap igr:gap-2">
                                {c.used_by.channels
                                    .filter((ch) => ch.status === 'error' && ch.external_id !== null)
                                    .map((ch) => (
                                        <Button key={ch.id} variant="outline" size="sm" loading={retry.isPending} onClick={() => retryNumber(c.id, ch.external_id ?? '')}>
                                            <RefreshCw />
                                            {t('connections.actions.retryNumber', { number: ch.identity ?? ch.name })}
                                        </Button>
                                    ))}
                                <Button variant="ghost" size="sm" onClick={() => setDisconnecting(c)}>
                                    <Unplug />
                                    {t('connections.actions.disconnectWaba', { id: c.whatsapp?.waba_id ?? '' })}
                                </Button>
                            </span>
                        ))}
                    </div>
                ) : null}
            </CardContent>
            <DisconnectDialog connection={disconnecting} onClose={() => setDisconnecting(null)} />
        </Card>
    );
}

function ConnectionSummary({ connection }: { connection: Connection }) {
    const { t } = useTranslation();
    const used = [
        ...connection.used_by.destinations.map((d) => t(`connections.usedBy.destination`, { platform: d.name ?? d.platform })),
        ...connection.used_by.channels.map((c) => `${t(`connections.usedBy.${c.channel}`)} ${c.identity ?? c.name}`),
    ];
    return (
        <div className="igr:space-y-1.5 igr:text-xs">
            <p>
                <span className="igr:text-muted-foreground">{t('connections.account')}</span> <span className="igr:font-medium">{connection.account_name ?? '—'}</span>
                {connection.connected_at ? <span className="igr:text-muted-foreground"> · {formatRelative(connection.connected_at)}</span> : null}
                {connection.whatsapp?.coexistence ? <span className="igr:text-muted-foreground"> · {t('connections.whatsapp.coexistence')}</span> : null}
            </p>
            {connection.expires_at && connection.provider !== 'google' ? (
                <p className="igr:text-warning">{t('connections.expiresAt', { date: formatDate(connection.expires_at) })}</p>
            ) : null}
            {connection.uses.length > 0 ? (
                <p className="igr:text-muted-foreground">
                    {t('connections.allows')} {connection.uses.map((u) => t(`connections.uses.${u}`)).join(', ')}
                </p>
            ) : null}
            {used.length > 0 ? (
                <p className="igr:text-muted-foreground">
                    {t('connections.usedByLabel')} {used.join(', ')}
                </p>
            ) : null}
            {connection.used_by.channels.map((c) =>
                c.history ? (
                    <p key={c.id} className="igr:text-muted-foreground">
                        {c.history.declined
                            ? t('connections.whatsapp.historyDeclined')
                            : c.history.progress >= 100
                              ? t('connections.whatsapp.historyDone', { count: c.history.messages })
                              : t('connections.whatsapp.historyProgress', { progress: c.history.progress, count: c.history.messages })}
                    </p>
                ) : null,
            )}
            {connection.last_error ? (
                <p className="igr:flex igr:items-start igr:gap-1 igr:text-warning">
                    <CircleAlert className="igr:mt-0.5 igr:size-3.5 igr:shrink-0" aria-hidden="true" />
                    {connection.last_error}
                </p>
            ) : null}
        </div>
    );
}

function DisconnectDialog({ connection, onClose }: { connection: Connection | null; onClose: () => void }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const disconnect = useDisconnectConnection(workspace.id);
    return (
        <Dialog open={connection !== null} onOpenChange={(next) => !next && onClose()}>
            <DialogContent className="igr:max-w-md">
                <DialogHeader>
                    <DialogTitle>{t('connections.disconnect.title')}</DialogTitle>
                    <DialogDescription>{t('connections.disconnect.description')}</DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button variant="outline" onClick={onClose}>
                        {t('common.cancel')}
                    </Button>
                    <Button
                        variant="destructive"
                        loading={disconnect.isPending}
                        onClick={() =>
                            connection &&
                            disconnect.mutate(connection.id, {
                                onSuccess: () => {
                                    toast.success(t('connections.toast.disconnected'));
                                    onClose();
                                },
                            })
                        }
                    >
                        {t('connections.actions.disconnect')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
