import { Globe, Plug, ShoppingCart } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Link, useSearchParams } from 'react-router';
import { useSites } from '@/api/queries';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { EmptyState, ErrorState, PageHeader } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TBody, TD, TH, THead, TR } from '@/components/ui/table';
import { useCan, useWorkspace } from '@/hooks/useWorkspace';
import { formatRelative } from '@/lib/format';
import { ConnectManualDialog } from './ConnectManualDialog';
import { PluginDownloadButton } from './PluginDownload';
import { BackfillBadge, SiteStatusBadge } from './SiteBits';

export function SitesPage() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const sites = useSites(workspace.id);
    const canManage = useCan('manageSites');
    const [params, setParams] = useSearchParams();
    const open = canManage && params.get('conectar') === '1';
    const setOpen = (next: boolean) => {
        const p = new URLSearchParams(params);
        if (next) {
            p.set('conectar', '1');
        } else {
            p.delete('conectar');
        }
        setParams(p, { replace: true });
    };

    return (
        <>
            <PageHeader
                title={t('sites.title')}
                description={t('sites.description')}
                actions={
                    <div className="igr:flex igr:gap-2">
                        <PluginDownloadButton />
                        {canManage && (
                            <Button onClick={() => setOpen(true)}>
                                <Plug />
                                {t('sites.connect')}
                            </Button>
                        )}
                    </div>
                }
            />
            {sites.isError ? (
                <ErrorState error={sites.error} onRetry={() => void sites.refetch()} />
            ) : sites.isPending ? (
                <Skeleton className="igr:h-40" />
            ) : sites.data.length === 0 ? (
                <EmptyState
                    icon={Plug}
                    title={t('sites.emptyTitle')}
                    description={t('sites.emptyDescription')}
                    action={
                        <div className="igr:flex igr:flex-wrap igr:justify-center igr:gap-2">
                            <PluginDownloadButton />
                            {canManage && (
                                <Button onClick={() => setOpen(true)}>
                                    <Plug />
                                    {t('sites.connect')}
                                </Button>
                            )}
                        </div>
                    }
                />
            ) : (
                <Card>
                    <Table>
                        <THead>
                            <TR className="igr:hover:bg-transparent">
                                <TH>{t('sites.columns.site')}</TH>
                                <TH>{t('sites.columns.status')}</TH>
                                <TH>{t('sites.columns.backfill')}</TH>
                                <TH>{t('sites.columns.plugin')}</TH>
                                <TH className="igr:text-right">{t('sites.columns.lastEvent')}</TH>
                            </TR>
                        </THead>
                        <TBody>
                            {sites.data.map((site) => (
                                <TR key={site.id}>
                                    <TD>
                                        <Link to={`/w/${workspace.id}/sitios/${site.id}`} className="igr:flex igr:items-center igr:gap-2.5 igr:font-medium igr:hover:underline">
                                            <span className="igr:flex igr:size-7 igr:items-center igr:justify-center igr:rounded-md igr:bg-muted">
                                                {site.platform === 'woocommerce' ? <ShoppingCart className="igr:size-3.5" /> : <Globe className="igr:size-3.5" />}
                                            </span>
                                            <span>
                                                <span className="igr:block">{site.name ?? site.host}</span>
                                                <span className="igr:block igr:text-xs igr:font-normal igr:text-muted-foreground">{site.host}</span>
                                            </span>
                                        </Link>
                                    </TD>
                                    <TD>
                                        <SiteStatusBadge status={site.status} />
                                    </TD>
                                    <TD>
                                        <div className="igr:flex igr:items-center igr:gap-2">
                                            <BackfillBadge backfill={site.backfill} />
                                            {site.backfill?.status === 'running' ? (
                                                <span className="igr:text-xs igr:tabular-nums igr:text-muted-foreground">{Math.round((site.backfill.progress ?? 0) * 100)} %</span>
                                            ) : null}
                                        </div>
                                    </TD>
                                    <TD className="igr:text-muted-foreground">{site.plugin_version ? `v${site.plugin_version}` : '—'}</TD>
                                    <TD className="igr:text-right igr:text-muted-foreground">{formatRelative(site.last_event_at, workspace.locale)}</TD>
                                </TR>
                            ))}
                        </TBody>
                    </Table>
                </Card>
            )}
            {canManage ? <ConnectManualDialog workspaceId={workspace.id} open={open} onOpenChange={setOpen} /> : null}
        </>
    );
}
