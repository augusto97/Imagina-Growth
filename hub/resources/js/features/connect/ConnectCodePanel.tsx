import { useQuery } from '@tanstack/react-query';
import { Check, Copy, Download, RefreshCw } from 'lucide-react';
import { useEffect, useState, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import type { ConnectCode } from '@/api/types';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { PLUGIN_DOWNLOAD_URL } from '@/features/sites/PluginDownload';
import { api } from '@/lib/api';
import { cn } from '@/lib/cn';

/*
 * Conectar la web con el plugin (docs/api-connector.md §3.2, rediseño v2 §4.2 paso 2): descargar el plugin, el código
 * de un solo uso con «Copiar» y su vencimiento (10 minutos; se genera otro con un clic) y la espera en vivo mientras el
 * plugin lo canjea. Quien lo usa (el asistente) sabe cuándo quedó conectada y celebra.
 */

/** Segundos que le quedan al código (0 si venció). */
export function secondsLeft(expiresAt: string | undefined, now: number): number {
    return expiresAt ? Math.max(0, Math.round((new Date(expiresAt).getTime() - now) / 1000)) : 0;
}

/** «09:41». */
export function clock(seconds: number): string {
    return `${String(Math.floor(seconds / 60)).padStart(2, '0')}:${String(seconds % 60).padStart(2, '0')}`;
}

function useNow(active: boolean): number {
    const [now, setNow] = useState(() => Date.now());
    useEffect(() => {
        if (!active) return undefined;
        const id = window.setInterval(() => setNow(Date.now()), 1000);
        return () => window.clearInterval(id);
    }, [active]);
    return now;
}

function Step({ n, title, children }: { n: number; title: string; children?: ReactNode }) {
    return (
        <li className="igr:flex igr:gap-3">
            <span className="igr:flex igr:size-7 igr:shrink-0 igr:items-center igr:justify-center igr:rounded-full igr:bg-brand-soft igr:text-caption igr:font-semibold igr:text-brand-soft-foreground igr:tabular-nums">
                {n}
            </span>
            <div className="igr:min-w-0 igr:flex-1 igr:pt-1">
                <p className="igr:text-support igr:font-medium igr:text-foreground">{title}</p>
                {children ? <div className="igr:mt-2">{children}</div> : null}
            </div>
        </li>
    );
}

export function ConnectCodePanel({ workspaceId, waiting, store }: { workspaceId: string; waiting: boolean; store: boolean }) {
    const { t } = useTranslation();
    const [round, setRound] = useState(0);
    const [copied, setCopied] = useState(false);
    const code = useQuery({
        queryKey: ['connect-code', workspaceId, round],
        queryFn: () => api.post<ConnectCode>(`/api/w/${workspaceId}/sites/connect-codes`, {}),
        staleTime: Infinity,
        gcTime: 0,
        retry: false,
        refetchOnWindowFocus: false,
    });
    const now = useNow(code.data !== undefined);
    const left = secondsLeft(code.data?.expires_at, now);
    const expired = code.data !== undefined && left === 0;

    const copy = async () => {
        if (!code.data) return;
        try {
            await navigator.clipboard.writeText(code.data.code);
            setCopied(true);
            window.setTimeout(() => setCopied(false), 2500);
        } catch {
            setCopied(false);
        }
    };

    return (
        <ol className="igr:space-y-5">
            <Step n={1} title={t(store ? 'connect.panel.download' : 'connect.panel.downloadWeb')}>
                <Button asChild variant="outline" size="sm">
                    <a href={PLUGIN_DOWNLOAD_URL} download>
                        <Download aria-hidden />
                        {t('connect.panel.downloadButton')}
                    </a>
                </Button>
            </Step>
            <Step n={2} title={t('connect.panel.install')}>
                <p className="igr:text-caption igr:text-muted-foreground">{t('connect.panel.installHint')}</p>
            </Step>
            <Step n={3} title={t('connect.panel.paste')}>
                <div className="igr:rounded-xl igr:border igr:bg-muted/40 igr:p-4">
                    {code.isError ? (
                        <div className="igr:flex igr:flex-wrap igr:items-center igr:gap-3">
                            <p className="igr:text-support igr:text-destructive">{t('connect.panel.error')}</p>
                            <Button variant="outline" size="sm" onClick={() => setRound((r) => r + 1)}>
                                <RefreshCw aria-hidden />
                                {t('connect.panel.retry')}
                            </Button>
                        </div>
                    ) : code.data && !expired ? (
                        <div className="igr:flex igr:flex-wrap igr:items-center igr:gap-x-4 igr:gap-y-3">
                            <p
                                className="igr:font-mono igr:text-xl igr:leading-8 igr:font-semibold igr:tracking-[0.08em] igr:whitespace-nowrap igr:text-foreground igr:sm:text-[26px] igr:sm:tracking-[0.18em]"
                                data-testid="connect-code"
                                aria-label={t('connect.panel.codeLabel')}
                            >
                                {code.data.code}
                            </p>
                            <Button variant={copied ? 'secondary' : 'default'} size="sm" onClick={() => void copy()}>
                                {copied ? <Check aria-hidden /> : <Copy aria-hidden />}
                                {copied ? t('common.copied') : t('common.copy')}
                            </Button>
                            <p className="igr:w-full igr:text-caption igr:text-muted-foreground igr:tabular-nums" aria-live="off">
                                {t('connect.panel.expires', { time: clock(left) })}
                            </p>
                        </div>
                    ) : expired ? (
                        <div className="igr:flex igr:flex-wrap igr:items-center igr:gap-3">
                            <p className="igr:text-support igr:text-muted-foreground">{t('connect.panel.expired')}</p>
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() => {
                                    setCopied(false);
                                    setRound((r) => r + 1);
                                }}
                            >
                                <RefreshCw aria-hidden />
                                {t('connect.panel.regenerate')}
                            </Button>
                        </div>
                    ) : (
                        <Skeleton className="igr:h-8 igr:w-56" />
                    )}
                </div>
                <p className={cn('igr:mt-3 igr:flex igr:items-center igr:gap-2 igr:text-caption', waiting ? 'igr:text-foreground' : 'igr:text-muted-foreground')} role="status">
                    <span className="igr:relative igr:flex igr:size-2.5" aria-hidden>
                        <span className="igr:absolute igr:inline-flex igr:h-full igr:w-full igr:animate-ping igr:rounded-full igr:bg-primary igr:opacity-60 igr:motion-reduce:animate-none" />
                        <span className="igr:relative igr:inline-flex igr:size-2.5 igr:rounded-full igr:bg-primary" />
                    </span>
                    {t(store ? 'connect.panel.waiting' : 'connect.panel.waitingWeb')}
                </p>
            </Step>
        </ol>
    );
}
