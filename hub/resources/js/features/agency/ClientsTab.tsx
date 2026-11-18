import { ArrowDownUp, Building2, LogIn, OctagonAlert, TriangleAlert } from 'lucide-react';
import { type ReactNode, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useNavigate } from 'react-router';
import { Badge, StatusDot } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input, Select } from '@/components/ui/input';
import { EmptyState, ErrorState } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TBody, TD, TH, THead, TR } from '@/components/ui/table';
import { useWorkspace } from '@/hooks/useWorkspace';
import { isApiError } from '@/lib/api';
import { cn } from '@/lib/cn';
import { formatMoney, formatNumber, formatPercent, formatRelative } from '@/lib/format';
import { toast } from '@/stores/toast';
import { type AgencyClient, useAgencyOverview, useEnterClient } from './api';
import { type ClientFilter, type ClientSort, billingNeedsAttention, filterClients, formatDelta, healthTone, isOverLimit, shareWidth, sortClients } from './model';

/*
 * Panel de clientes (M22). Cifras en tinta; la barra de «generado» es una sola
 * serie (un tono), relativa al cliente que más generó; los estados llevan ícono
 * y texto, nunca solo color.
 */
export function ClientsTab() {
    const { t, i18n } = useTranslation();
    const { workspace } = useWorkspace();
    const overview = useAgencyOverview(workspace.id);
    const [filter, setFilter] = useState<ClientFilter>('all');
    const [search, setSearch] = useState('');
    const [sort, setSort] = useState<ClientSort>('money');
    const [dir, setDir] = useState<'asc' | 'desc'>('desc');
    const [entering, setEntering] = useState<AgencyClient | null>(null);

    const rows = useMemo(() => sortClients(filterClients(overview.data?.clients ?? [], filter, search), sort, dir), [overview.data, filter, search, sort, dir]);
    const maxMoney = Math.max(0, ...rows.map((c) => Number(c.money.total)));

    if (overview.isError) {
        return <ErrorState error={overview.error} onRetry={() => void overview.refetch()} />;
    }
    if (overview.isPending) {
        return <Skeleton className="igr:h-64 igr:w-full" />;
    }
    const data = overview.data;
    const locale = i18n.language;
    const toggleSort = (key: ClientSort) => {
        if (sort === key) {
            setDir(dir === 'asc' ? 'desc' : 'asc');
        } else {
            setSort(key);
            setDir(key === 'name' ? 'asc' : 'desc');
        }
    };
    const SortHead = ({ k, label, className }: { k: ClientSort; label: string; className?: string }) => (
        <TH className={className} aria-sort={sort === k ? (dir === 'asc' ? 'ascending' : 'descending') : 'none'}>
            <button type="button" onClick={() => toggleSort(k)} className="igr:inline-flex igr:items-center igr:gap-1 igr:hover:text-foreground" title={t('agency.sort_by', { column: label })}>
                {label}
                <ArrowDownUp className={cn('igr:size-3', sort === k ? 'igr:opacity-100' : 'igr:opacity-40')} aria-hidden />
            </button>
        </TH>
    );

    return (
        <div className="igr:space-y-4">
            <div className="igr:grid igr:grid-cols-2 igr:gap-3 igr:lg:grid-cols-5">
                <Tile label={t('agency.tiles.generated')} hint={t('agency.tiles.generated_hint')}>
                    {Object.keys(data.totals.money_by_currency).length === 0
                        ? formatMoney(0, workspace.currency, locale)
                        : Object.entries(data.totals.money_by_currency).map(([cur, v]) => (
                              <div key={cur}>{formatMoney(v, cur, locale)}</div>
                          ))}
                </Tile>
                <Tile label={t('agency.tiles.clients')}>{formatNumber(data.totals.clients, locale)}</Tile>
                <Tile label={t('agency.tiles.critical')} icon={data.totals.critical_insights > 0 ? <OctagonAlert className="igr:size-4 igr:text-destructive" aria-hidden /> : null}>
                    {formatNumber(data.totals.critical_insights, locale)}
                </Tile>
                <Tile label={t('agency.tiles.disconnected')} icon={data.totals.disconnected > 0 ? <TriangleAlert className="igr:size-4 igr:text-warning" aria-hidden /> : null}>
                    {formatNumber(data.totals.disconnected, locale)}
                </Tile>
                <Tile label={t('agency.tiles.over_limit')}>{formatNumber(data.totals.over_limit, locale)}</Tile>
            </div>

            <div className="igr:flex igr:flex-wrap igr:items-center igr:gap-2">
                <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder={t('agency.filters.search')} className="igr:w-60" aria-label={t('agency.filters.search')} />
                <Select value={filter} onChange={(e) => setFilter(e.target.value as ClientFilter)} className="igr:w-56" aria-label={t('agency.filters.all')}>
                    {(['all', 'attention', 'disconnected', 'critical', 'over_limit', 'billing'] as const).map((f) => (
                        <option key={f} value={f}>
                            {t(`agency.filters.${f}`)}
                        </option>
                    ))}
                </Select>
            </div>

            {data.clients.length === 0 ? (
                <EmptyState icon={Building2} title={t('agency.empty')} />
            ) : rows.length === 0 ? (
                <EmptyState icon={Building2} title={t('agency.no_results')} />
            ) : (
                <div className="igr:overflow-x-auto igr:rounded-lg igr:border">
                    <Table>
                        <THead>
                            <TR>
                                <SortHead k="name" label={t('agency.columns.client')} />
                                <TH>{t('agency.columns.connection')}</TH>
                                <SortHead k="money" label={t('agency.columns.generated')} className="igr:min-w-56" />
                                <SortHead k="delta" label={t('agency.columns.change')} />
                                <TH>{t('agency.columns.top_module')}</TH>
                                <SortHead k="critical" label={t('agency.columns.critical')} />
                                <SortHead k="usage" label={t('agency.columns.usage')} />
                                <TH>{t('agency.columns.billing')}</TH>
                                <TH />
                            </TR>
                        </THead>
                        <TBody>
                            {rows.map((c) => (
                                <TR key={c.id}>
                                    <TD>
                                        <div className="igr:font-medium">{c.name}</div>
                                        {data.accounts.length > 1 && c.account ? <div className="igr:text-xs igr:text-muted-foreground">{c.account.name}</div> : null}
                                    </TD>
                                    <TD>
                                        <span className="igr:inline-flex igr:items-center igr:gap-1.5" title={c.health.last_event_at ? t('agency.health.last_event', { when: formatRelative(c.health.last_event_at, locale) }) : undefined}>
                                            <StatusDot tone={healthTone(c.health.status)} />
                                            {t(`agency.health.${c.health.status}`)}
                                        </span>
                                    </TD>
                                    <TD>
                                        <div className="igr:flex igr:items-center igr:gap-2">
                                            <span className="igr:w-28 igr:shrink-0 igr:text-right igr:font-medium igr:tabular-nums">{formatMoney(c.money.total, c.money.currency, locale)}</span>
                                            <div className="igr:h-2 igr:flex-1 igr:min-w-16" title={t('agency.of_store', { pct: formatPercent(c.money.share_pct / 100, locale, 1) })}>
                                                <div className="igr:h-2 igr:rounded-r igr:bg-primary" style={{ width: `${shareWidth(Number(c.money.total), maxMoney)}%` }} />
                                            </div>
                                        </div>
                                        <div className="igr:text-xs igr:text-muted-foreground">{t('agency.of_store', { pct: formatPercent(c.money.share_pct / 100, locale, 1) })}</div>
                                    </TD>
                                    <TD className="igr:tabular-nums">{formatDelta(c.money.delta_pct, locale)}</TD>
                                    <TD className="igr:text-muted-foreground">{c.money.top_module ? t(`agency.modules.${c.money.top_module}`) : '—'}</TD>
                                    <TD>
                                        {c.critical_insights > 0 ? (
                                            <Badge variant="destructive">
                                                <OctagonAlert className="igr:size-3" aria-hidden />
                                                {formatNumber(c.critical_insights, locale)}
                                            </Badge>
                                        ) : (
                                            <span className="igr:text-muted-foreground">0</span>
                                        )}
                                    </TD>
                                    <TD>
                                        <UsageCell client={c} locale={locale} />
                                    </TD>
                                    <TD>
                                        <Badge variant={billingNeedsAttention(c) ? 'warning' : 'outline'}>{t(`agency.billing_status.${c.billing.status ?? 'none'}`)}</Badge>
                                    </TD>
                                    <TD className="igr:text-right">
                                        <Button size="sm" variant="outline" onClick={() => setEntering(c)}>
                                            <LogIn aria-hidden />
                                            {t('agency.enter')}
                                        </Button>
                                    </TD>
                                </TR>
                            ))}
                        </TBody>
                    </Table>
                </div>
            )}
            <EnterDialog client={entering} onClose={() => setEntering(null)} />
        </div>
    );
}

function Tile({ label, hint, icon, children }: { label: string; hint?: string; icon?: ReactNode; children: ReactNode }) {
    return (
        <div className="igr:rounded-lg igr:border igr:bg-card igr:px-4 igr:py-3" title={hint}>
            <div className="igr:flex igr:items-center igr:gap-1.5 igr:text-xs igr:text-muted-foreground">
                {icon}
                {label}
            </div>
            <div className="igr:mt-1 igr:text-xl igr:font-semibold igr:tracking-tight igr:tabular-nums">{children}</div>
        </div>
    );
}

function UsageCell({ client, locale }: { client: AgencyClient; locale: string }) {
    const { t } = useTranslation();
    const u = client.usage;
    const over = isOverLimit(client);
    const warn = !over && u.pct !== null && u.pct >= 80;
    return (
        <div className="igr:min-w-28">
            <div className="igr:flex igr:items-center igr:gap-1 igr:text-xs">
                {over ? <OctagonAlert className="igr:size-3 igr:text-destructive" aria-hidden /> : warn ? <TriangleAlert className="igr:size-3 igr:text-warning" aria-hidden /> : null}
                <span className="igr:tabular-nums">
                    {u.limit === null ? t('agency.usage_unlimited', { used: formatNumber(u.used, locale) }) : t('agency.usage_of', { used: formatNumber(u.used, locale), limit: formatNumber(u.limit, locale) })}
                </span>
            </div>
            {u.pct !== null ? (
                <div className="igr:mt-1 igr:h-1.5 igr:w-full igr:rounded-full igr:bg-muted" role="progressbar" aria-valuenow={Math.round(u.pct)} aria-valuemin={0} aria-valuemax={100}>
                    <div className={cn('igr:h-1.5 igr:rounded-full', over ? 'igr:bg-destructive' : warn ? 'igr:bg-warning' : 'igr:bg-primary')} style={{ width: `${Math.min(100, u.pct)}%` }} />
                </div>
            ) : null}
        </div>
    );
}

function EnterDialog({ client, onClose }: { client: AgencyClient | null; onClose: () => void }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const navigate = useNavigate();
    const enter = useEnterClient(workspace.id);
    const [reason, setReason] = useState('');
    return (
        <Dialog open={client !== null} onOpenChange={(open) => (open ? null : onClose())}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{t('agency.enter_title', { client: client?.name ?? '' })}</DialogTitle>
                    <DialogDescription>{t('agency.enter_hint')}</DialogDescription>
                </DialogHeader>
                <Input value={reason} onChange={(e) => setReason(e.target.value)} placeholder={t('agency.enter_reason')} aria-label={t('agency.enter_reason')} maxLength={255} />
                <DialogFooter>
                    <Button
                        loading={enter.isPending}
                        onClick={() =>
                            client &&
                            enter.mutate(
                                { client: client.id, reason },
                                {
                                    onSuccess: (res) => {
                                        onClose();
                                        setReason('');
                                        void navigate(`/w/${res.workspace_id}`);
                                    },
                                    onError: (e) => toast.error(t('errors.title'), isApiError(e) ? e.message : undefined),
                                },
                            )
                        }
                    >
                        <LogIn aria-hidden />
                        {t('agency.enter_go')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
