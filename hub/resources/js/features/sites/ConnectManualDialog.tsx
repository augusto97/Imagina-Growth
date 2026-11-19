import { useQuery, useQueryClient } from '@tanstack/react-query';
import { Check, Copy, RefreshCw } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { keys } from '@/api/queries';
import type { ConnectCode, Site } from '@/api/types';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Skeleton } from '@/components/ui/skeleton';
import { api } from '@/lib/api';
import { toast } from '@/stores/toast';
import { PLUGIN_DOWNLOAD_URL } from './PluginDownload';

function useSecondsLeft(expiresAt: string | undefined): number {
    const [now, setNow] = useState(() => Date.now());
    useEffect(() => {
        const id = window.setInterval(() => setNow(Date.now()), 1000);
        return () => window.clearInterval(id);
    }, []);
    return expiresAt ? Math.max(0, Math.round((new Date(expiresAt).getTime() - now) / 1000)) : 0;
}

function siteSignature(sites: Site[]): Set<string> {
    return new Set(sites.map((s) => `${s.id}:${s.connected_at ?? ''}`));
}

/** Cuerpo del diálogo: se monta al abrir, así cada apertura genera un código nuevo. */
function ConnectCodeBody({ workspaceId, onConnected }: { workspaceId: string; onConnected: () => void }) {
    const { t } = useTranslation();
    const qc = useQueryClient();
    const [session] = useState(() => Date.now());
    const [copied, setCopied] = useState(false);

    const code = useQuery({
        queryKey: ['connect-code', workspaceId, session],
        queryFn: () => api.post<ConnectCode>(`/api/w/${workspaceId}/sites/connect-codes`, {}),
        staleTime: Infinity,
        gcTime: 0,
        retry: false,
        refetchOnWindowFocus: false,
    });

    // Se vigila la lista de sitios para avisar en cuanto el plugin canjee el código.
    const sites = useQuery({
        queryKey: ['connect-watch', workspaceId, session],
        queryFn: async () => (await api.get<{ sites: Site[] }>(`/api/w/${workspaceId}/sites`)).sites,
        refetchInterval: 4000,
        gcTime: 0,
    });
    const baseline = useRef<Set<string> | null>(null);
    useEffect(() => {
        if (!sites.data) {
            return;
        }
        const current = siteSignature(sites.data);
        if (baseline.current === null) {
            baseline.current = current;
            return;
        }
        if ([...current].some((id) => !baseline.current?.has(id))) {
            // Se actualiza la línea base para avisar una sola vez aunque el efecto se repita.
            baseline.current = current;
            toast.success(t('sites.connectedToast'));
            void qc.invalidateQueries({ queryKey: keys.sites(workspaceId) });
            onConnected();
        }
    }, [sites.data, qc, workspaceId, onConnected, t]);

    const left = useSecondsLeft(code.data?.expires_at);
    const expired = code.data !== undefined && left === 0;
    const mm = String(Math.floor(left / 60)).padStart(2, '0');
    const ss = String(left % 60).padStart(2, '0');

    const copy = async () => {
        if (code.data) {
            await navigator.clipboard.writeText(code.data.code);
            setCopied(true);
        }
    };

    return (
        <div className="igr:rounded-lg igr:border igr:bg-muted/40 igr:p-4 igr:text-center">
            {code.isError ? (
                <p className="igr:text-[13px] igr:text-destructive">{t('errors.generic')}</p>
            ) : code.data && !expired ? (
                <>
                    <p className="igr:font-mono igr:text-2xl igr:font-semibold igr:tracking-widest" data-testid="connect-code">
                        {code.data.code}
                    </p>
                    <p className="igr:mt-1 igr:text-xs igr:text-muted-foreground igr:tabular-nums">{t('sites.manual.expiresIn', { time: `${mm}:${ss}` })}</p>
                    <Button variant="outline" size="sm" className="igr:mt-3" onClick={() => void copy()}>
                        {copied ? <Check /> : <Copy />}
                        {copied ? t('common.copied') : t('common.copy')}
                    </Button>
                </>
            ) : expired ? (
                <>
                    <p className="igr:text-[13px] igr:text-muted-foreground">{t('sites.manual.expired')}</p>
                    <Button
                        variant="outline"
                        size="sm"
                        className="igr:mt-3"
                        loading={code.isFetching}
                        onClick={() => {
                            setCopied(false);
                            void code.refetch();
                        }}
                    >
                        <RefreshCw />
                        {t('sites.manual.regenerate')}
                    </Button>
                </>
            ) : (
                <Skeleton className="igr:mx-auto igr:h-8 igr:w-64" />
            )}
        </div>
    );
}

/** Conexión manual (api-connector.md §3.2): código de un solo uso válido 10 minutos. */
export function ConnectManualDialog({ workspaceId, open, onOpenChange }: { workspaceId: string; open: boolean; onOpenChange: (open: boolean) => void }) {
    const { t } = useTranslation();
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{t('sites.manual.title')}</DialogTitle>
                    <DialogDescription>{t('sites.manual.description')}</DialogDescription>
                </DialogHeader>
                <ConnectCodeBody workspaceId={workspaceId} onConnected={() => onOpenChange(false)} />
                <ol className="igr:mt-4 igr:list-decimal igr:space-y-1.5 igr:pl-5 igr:text-[13px] igr:text-muted-foreground">
                    <li>
                        {t('sites.manual.step1')}{' '}
                        <a href={PLUGIN_DOWNLOAD_URL} download className="igr:font-medium igr:text-primary igr:underline-offset-2 igr:hover:underline">
                            {t('sites.downloadPlugin')}
                        </a>
                    </li>
                    <li>{t('sites.manual.step2')}</li>
                    <li>{t('sites.manual.step3')}</li>
                </ol>
                <DialogFooter>
                    <Button variant="outline" onClick={() => onOpenChange(false)}>
                        {t('common.close')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
