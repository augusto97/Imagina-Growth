import { CircleAlert, ListChecks, LogIn, RefreshCw, Settings2, Unplug } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { Button } from '@/components/ui/button';
import { Callout } from '@/components/ui/callout';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { useCan, useWorkspace } from '@/hooks/useWorkspace';
import { isApiError } from '@/lib/api';
import { formatDate, formatRelative } from '@/lib/format';
import { toast } from '@/stores/toast';
import { type Connection, type ProviderInfo, type RedirectProvider, useCheckConnection, useDisconnectConnection, useStartConnection, useWhatsAppSignupApi } from './api';

/** Plataforma → Ajustes, en la sección de la app que falta (`meta.app_id` → `meta`). Solo la ve quien administra la plataforma. */
function settingsPath(info: ProviderInfo): string {
    const section = info.missing[0]?.split('.')[0] ?? (info.key === 'meta_whatsapp' ? 'meta' : info.key);
    return `plataforma?pestana=ajustes&seccion=${section}`;
}

/**
 * Sin la app del proveedor en esta instalación: qué falta, quién lo arregla (el superadmin, en Plataforma → Ajustes) y
 * cómo conectar mientras tanto pegando los datos a mano (`manual`).
 */
export function ProviderUnavailable({ info, manual }: { info: ProviderInfo; manual?: { to: string; label: string } }) {
    const { t } = useTranslation();
    const { workspace, me } = useWorkspace();
    return (
        <Callout tone="neutral" title={t('connections.hub.unavailableTitle')}>
            {t('connections.unavailable', { provider: t(`connections.provider.${info.key}.name`) })}{' '}
            {me.user.is_platform_admin ? `${t('connections.missing')} ${info.missing.map((key) => t(`platformSettings.fields.${key}.label`, { defaultValue: key })).join(', ')}` : null}
            {me.user.is_platform_admin || manual ? (
                <span className="igr:mt-3 igr:flex igr:flex-wrap igr:gap-2">
                    {me.user.is_platform_admin ? (
                        <Button asChild size="sm" variant="outline">
                            <Link to={`/w/${workspace.id}/${settingsPath(info)}`}>
                                <Settings2 aria-hidden />
                                {t('connections.configure')}
                            </Link>
                        </Button>
                    ) : null}
                    {manual ? (
                        <Button asChild size="sm" variant="outline">
                            <Link to={`/w/${workspace.id}/${manual.to}`}>{manual.label}</Link>
                        </Button>
                    ) : null}
                </span>
            ) : null}
        </Callout>
    );
}

/**
 * Meta, Google o TikTok con un clic: conectar (o reconectar) en la página del proveedor, elegir las cuentas, revisar la
 * conexión y desconectar. La vuelta del consentimiento la atiende ConnectionsPage (`?conexion=…&estado=…`).
 */
export function ProviderConnection({ info, onPick, connectLabel }: { info: ProviderInfo & { key: RedirectProvider }; onPick: (c: Connection) => void; connectLabel?: string }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const canManage = useCan('manageWorkspace');
    const start = useStartConnection(workspace.id);
    const check = useCheckConnection(workspace.id);
    const [disconnecting, setDisconnecting] = useState<Connection | null>(null);
    const connection = info.connections[0] ?? null;
    const connect = () => start.mutate({ provider: info.key, returnTo: 'conexiones' }, { onError: (e) => toast.error(t('connections.toast.startError'), isApiError(e) ? e.message : undefined) });

    return (
        <div className="igr:space-y-3">
            {connection ? <ConnectionSummary connection={connection} /> : null}
            {canManage ? (
                <div className="igr:flex igr:flex-wrap igr:gap-2">
                    {connection === null || connection.status !== 'active' ? (
                        <Button onClick={connect} loading={start.isPending}>
                            <LogIn aria-hidden />
                            {connection === null ? (connectLabel ?? t('connections.actions.connect')) : t('connections.actions.reconnect')}
                        </Button>
                    ) : (
                        <Button onClick={() => onPick(connection)}>
                            <ListChecks aria-hidden />
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
                                <RefreshCw aria-hidden />
                                {t('connections.actions.check')}
                            </Button>
                            <Button variant="ghost" onClick={() => setDisconnecting(connection)}>
                                <Unplug aria-hidden />
                                {t('connections.actions.disconnect')}
                            </Button>
                        </>
                    ) : null}
                </div>
            ) : (
                <p className="igr:text-support igr:text-muted-foreground">{t('connections.alerts.askAdmin')}</p>
            )}
            <DisconnectDialog connection={disconnecting} onClose={() => setDisconnecting(null)} />
        </div>
    );
}

/** Las cuentas de WhatsApp Business conectadas con un clic: reintentar un número con error o desconectar la cuenta. */
export function WhatsAppConnections({ info }: { info: ProviderInfo }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const canManage = useCan('manageWorkspace');
    const retry = useWhatsAppSignupApi(workspace.id).chooseNumber;
    const [disconnecting, setDisconnecting] = useState<Connection | null>(null);
    if (info.connections.length === 0) return null;
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
    return (
        <div className="igr:space-y-3">
            {info.connections.map((c) => (
                <div key={c.id} className="igr:space-y-2 igr:rounded-lg igr:border igr:p-3">
                    <ConnectionSummary connection={c} />
                    {canManage ? (
                        <div className="igr:flex igr:flex-wrap igr:gap-2">
                            {c.used_by.channels
                                .filter((ch) => ch.status === 'error' && ch.external_id !== null)
                                .map((ch) => (
                                    <Button key={ch.id} variant="outline" size="sm" loading={retry.isPending} onClick={() => retryNumber(c.id, ch.external_id ?? '')}>
                                        <RefreshCw aria-hidden />
                                        {t('connections.actions.retryNumber', { number: ch.identity ?? ch.name })}
                                    </Button>
                                ))}
                            <Button variant="ghost" size="sm" onClick={() => setDisconnecting(c)}>
                                <Unplug aria-hidden />
                                {t('connections.actions.disconnectWaba', { id: c.whatsapp?.waba_id ?? '' })}
                            </Button>
                        </div>
                    ) : null}
                </div>
            ))}
            <DisconnectDialog connection={disconnecting} onClose={() => setDisconnecting(null)} />
        </div>
    );
}

/** La cuenta autorizada, desde cuándo, qué permite, quién la usa y si hay que reconectarla pronto. */
export function ConnectionSummary({ connection }: { connection: Connection }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const used = [
        ...connection.used_by.destinations.map((d) => t('connections.usedBy.destination', { platform: d.name ?? d.platform })),
        ...connection.used_by.channels.map((c) => `${t(`connections.usedBy.${c.channel}`)} ${c.identity ?? c.name}`),
    ];
    return (
        <div className="igr:space-y-1.5 igr:text-support">
            <p>
                <span className="igr:text-muted-foreground">{t('connections.account')}</span> <span className="igr:font-medium">{connection.account_name ?? '—'}</span>
                {connection.connected_at ? <span className="igr:text-muted-foreground"> · {formatRelative(connection.connected_at, workspace.locale)}</span> : null}
                {connection.whatsapp?.coexistence ? <span className="igr:text-muted-foreground"> · {t('connections.whatsapp.coexistence')}</span> : null}
            </p>
            {connection.expires_at && connection.provider !== 'google' ? <p className="igr:text-warning">{t('connections.expiresAt', { date: formatDate(connection.expires_at, workspace.locale, workspace.timezone) })}</p> : null}
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

/** Confirmación antes de desconectar: lo que deja de funcionar (es reversible conectando otra vez). */
export function DisconnectDialog({ connection, onClose }: { connection: Connection | null; onClose: () => void }) {
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
