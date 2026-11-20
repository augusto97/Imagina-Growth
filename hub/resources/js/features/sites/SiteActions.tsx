import { useMutation, useQueryClient } from '@tanstack/react-query';
import { History, MoreHorizontal, PackageSearch, RotateCcwKey, Stethoscope, Unplug } from 'lucide-react';
import { useState, type SyntheticEvent } from 'react';
import { useTranslation } from 'react-i18next';
import { keys, wsPath } from '@/api/queries';
import type { SiteDetail } from '@/api/types';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useWorkspace } from '@/hooks/useWorkspace';
import { api, isApiError } from '@/lib/api';
import { toast } from '@/stores/toast';
import { DiagnosticsSummary } from './DiagnosticsSummary';

type Pending = null | 'diagnostics' | 'backfill' | 'rotate' | 'disconnect';

function errorText(error: unknown, fallback: string): string {
    return isApiError(error) && error.message !== '' ? error.message : fallback;
}

export function SiteActions({ workspaceId, site, canManage }: { workspaceId: string; site: SiteDetail; canManage: boolean }) {
    const { t } = useTranslation();
    const qc = useQueryClient();
    const [dialog, setDialog] = useState<Pending>(null);
    const [diagnostics, setDiagnostics] = useState<unknown>(null);
    const [from, setFrom] = useState('');
    const [restart, setRestart] = useState(false);
    const { workspace } = useWorkspace();
    const base = `${wsPath(workspaceId)}/sites/${site.id}`;
    const refresh = () => {
        void qc.invalidateQueries({ queryKey: keys.site(workspaceId, site.id) });
        void qc.invalidateQueries({ queryKey: keys.sites(workspaceId) });
    };

    const command = useMutation({
        mutationFn: (args: { command: string; body?: Record<string, unknown> }) => api.post<{ data: unknown }>(`${base}/commands/${args.command}`, args.body ?? {}),
    });
    const rotate = useMutation({ mutationFn: () => api.post(`${base}/rotate-secret`) });
    const disconnect = useMutation({ mutationFn: () => api.post(`${base}/disconnect`) });

    const runDiagnostics = () => {
        setDiagnostics(null);
        setDialog('diagnostics');
        command.mutate(
            { command: 'site.diagnostics' },
            {
                onSuccess: (res) => setDiagnostics(res.data),
                onError: (e) => setDiagnostics({ error: errorText(e, t('sites.actions.unreachable')) }),
            },
        );
    };

    const resync = () =>
        command.mutate(
            { command: 'catalog.resync' },
            { onSuccess: () => toast.success(t('sites.actions.resyncQueued')), onError: (e) => toast.error(t('sites.actions.failed'), errorText(e, t('sites.actions.unreachable'))) },
        );

    const submitBackfill = (event: SyntheticEvent) => {
        event.preventDefault();
        command.mutate(
            { command: 'backfill.start', body: { ...(from !== '' ? { from } : {}), restart } },
            {
                onSuccess: () => {
                    toast.success(t('sites.actions.backfillQueued'));
                    setDialog(null);
                    refresh();
                },
                onError: (e) => toast.error(t('sites.actions.failed'), errorText(e, t('sites.actions.unreachable'))),
            },
        );
    };

    return (
        <>
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <Button variant="outline">
                        <MoreHorizontal />
                        {t('sites.actions.title')}
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" className="igr:w-60">
                    <DropdownMenuItem onSelect={runDiagnostics}>
                        <Stethoscope />
                        {t('sites.actions.diagnostics')}
                    </DropdownMenuItem>
                    {canManage ? (
                        <>
                            <DropdownMenuItem onSelect={() => setDialog('backfill')}>
                                <History />
                                {t('sites.actions.backfill')}
                            </DropdownMenuItem>
                            <DropdownMenuItem onSelect={resync}>
                                <PackageSearch />
                                {t('sites.actions.resync')}
                            </DropdownMenuItem>
                            <DropdownMenuSeparator />
                            <DropdownMenuItem onSelect={() => setDialog('rotate')}>
                                <RotateCcwKey />
                                {t('sites.actions.rotate')}
                            </DropdownMenuItem>
                            <DropdownMenuItem onSelect={() => setDialog('disconnect')} className="igr:text-destructive igr:[&_svg]:text-destructive">
                                <Unplug />
                                {t('sites.actions.disconnect')}
                            </DropdownMenuItem>
                        </>
                    ) : null}
                </DropdownMenuContent>
            </DropdownMenu>

            <Dialog open={dialog === 'diagnostics'} onOpenChange={(o) => setDialog(o ? 'diagnostics' : null)}>
                <DialogContent className="igr:max-w-2xl">
                    <DialogHeader>
                        <DialogTitle>{t('sites.actions.diagnosticsTitle', { host: site.host })}</DialogTitle>
                        <DialogDescription>{t('sites.actions.diagnosticsDescription')}</DialogDescription>
                    </DialogHeader>
                    {diagnostics === null ? (
                        <p className="igr:text-[13px] igr:text-muted-foreground" aria-busy={command.isPending}>
                            {t('common.loading')}
                        </p>
                    ) : (
                        <DiagnosticsSummary data={diagnostics} locale={workspace.locale} />
                    )}
                </DialogContent>
            </Dialog>

            <Dialog open={dialog === 'backfill'} onOpenChange={(o) => setDialog(o ? 'backfill' : null)}>
                <DialogContent>
                    <form onSubmit={submitBackfill}>
                        <DialogHeader>
                            <DialogTitle>{t('sites.actions.backfillTitle')}</DialogTitle>
                            <DialogDescription>{t('sites.actions.backfillDescription')}</DialogDescription>
                        </DialogHeader>
                        <div className="igr:space-y-3">
                            <div className="igr:space-y-1.5">
                                <Label htmlFor="bf-from">{t('sites.actions.backfillFrom')}</Label>
                                <Input id="bf-from" type="date" value={from} onChange={(e) => setFrom(e.target.value)} />
                                <p className="igr:text-xs igr:text-muted-foreground">{t('sites.actions.backfillFromHelp')}</p>
                            </div>
                            <label className="igr:flex igr:items-center igr:gap-2 igr:text-[13px]">
                                <input type="checkbox" checked={restart} onChange={(e) => setRestart(e.target.checked)} className="igr:size-4 igr:accent-primary" />
                                {t('sites.actions.backfillRestart')}
                            </label>
                        </div>
                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setDialog(null)}>
                                {t('common.cancel')}
                            </Button>
                            <Button type="submit" loading={command.isPending}>
                                {t('sites.actions.backfillSubmit')}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <Dialog open={dialog === 'rotate'} onOpenChange={(o) => setDialog(o ? 'rotate' : null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{t('sites.actions.rotateTitle')}</DialogTitle>
                        <DialogDescription>{t('sites.actions.rotateDescription')}</DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setDialog(null)}>
                            {t('common.cancel')}
                        </Button>
                        <Button
                            loading={rotate.isPending}
                            onClick={() =>
                                rotate.mutate(undefined, {
                                    onSuccess: () => {
                                        toast.success(t('sites.actions.rotated'));
                                        setDialog(null);
                                        refresh();
                                    },
                                    onError: (e) => toast.error(t('sites.actions.failed'), errorText(e, t('sites.actions.unreachable'))),
                                })
                            }
                        >
                            {t('sites.actions.rotateSubmit')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog open={dialog === 'disconnect'} onOpenChange={(o) => setDialog(o ? 'disconnect' : null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{t('sites.actions.disconnectTitle', { host: site.host })}</DialogTitle>
                        <DialogDescription>{t('sites.actions.disconnectDescription')}</DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setDialog(null)}>
                            {t('common.cancel')}
                        </Button>
                        <Button
                            variant="destructive"
                            loading={disconnect.isPending}
                            onClick={() =>
                                disconnect.mutate(undefined, {
                                    onSuccess: () => {
                                        toast.info(t('sites.actions.disconnected'));
                                        setDialog(null);
                                        refresh();
                                    },
                                    onError: () => toast.error(t('errors.generic')),
                                })
                            }
                        >
                            {t('sites.actions.disconnectSubmit')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
