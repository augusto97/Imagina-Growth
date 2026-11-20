import { AlertTriangle, RefreshCw } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { Button } from '@/components/ui/button';
import { useCan, useWorkspace } from '@/hooks/useWorkspace';
import { isApiError } from '@/lib/api';
import { cn } from '@/lib/cn';
import { toast } from '@/stores/toast';
import { type ConnectionAlert, useConnectionAlerts, useStartConnection } from './api';
import { alertBody, alertTitle, isUrgent, reconnectAction } from './alerts';

/**
 * Aviso de conexiones por vencer (7 días o menos), vencidas o inválidas y de números que Meta desconectó, con
 * «Reconectar». Va en el Inicio y en Conexiones (`onConnectionsPage`: WhatsApp lleva a su tarjeta de la misma página).
 */
export function ConnectionAlertsNotice({ className, onConnectionsPage = false }: { className?: string; onConnectionsPage?: boolean }) {
    const { workspace } = useWorkspace();
    const alerts = useConnectionAlerts(workspace.id);
    const items = alerts.data?.data ?? [];
    if (items.length === 0) {
        return null;
    }
    return (
        <div className={cn('igr:space-y-2', className)}>
            {items.map((alert) => (
                <AlertRow key={alert.id} alert={alert} onConnectionsPage={onConnectionsPage} />
            ))}
        </div>
    );
}

function AlertRow({ alert, onConnectionsPage }: { alert: ConnectionAlert; onConnectionsPage: boolean }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const canManage = useCan('manageWorkspace');
    const start = useStartConnection(workspace.id);
    const action = reconnectAction(alert);
    const label = (
        <>
            <RefreshCw />
            {t('connections.alerts.reconnect')}
        </>
    );

    return (
        <div
            role="status"
            className={cn(
                'igr:flex igr:flex-col igr:gap-2 igr:rounded-lg igr:border igr:px-4 igr:py-3 igr:text-[13px] igr:sm:flex-row igr:sm:items-center',
                isUrgent(alert) ? 'igr:border-destructive/40 igr:bg-destructive/10' : 'igr:border-warning/50 igr:bg-warning/10',
            )}
        >
            <AlertTriangle className={cn('igr:size-4 igr:shrink-0', isUrgent(alert) ? 'igr:text-destructive' : 'igr:text-warning')} aria-hidden />
            <span className="igr:min-w-0 igr:flex-1">
                <span className="igr:font-medium">{alertTitle(t, alert, workspace.locale, workspace.timezone)}</span>
                <span className="igr:text-muted-foreground"> · {alertBody(t, alert)}</span>
            </span>
            {!canManage ? (
                <span className="igr:text-xs igr:text-muted-foreground">{t('connections.alerts.askAdmin')}</span>
            ) : action.kind === 'oauth' ? (
                <Button
                    size="sm"
                    loading={start.isPending}
                    onClick={() =>
                        start.mutate(
                            { provider: action.provider, returnTo: 'conexiones' },
                            { onError: (e) => toast.error(t('connections.toast.startError'), isApiError(e) ? e.message : undefined) },
                        )
                    }
                >
                    {label}
                </Button>
            ) : onConnectionsPage && action.path === 'conexiones' ? (
                <Button asChild size="sm">
                    <a href={`#proveedor-${alert.provider}`}>{label}</a>
                </Button>
            ) : (
                <Button asChild size="sm">
                    <Link to={`/w/${workspace.id}/${action.path}`}>{label}</Link>
                </Button>
            )}
        </div>
    );
}
