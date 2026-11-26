import { FileText, Plus, Target, Wallet } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { EmptyState, ErrorState, PageHeader } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { StatCard, StatCardSkeleton, StatGrid } from '@/components/ui/stat-card';
import { Table, TBody, TD, TH, THead, TR } from '@/components/ui/table';
import { useWorkspace } from '@/hooks/useWorkspace';
import { formatNumber } from '@/lib/format';
import { useCalculators, useQuoteRequests } from './api';
import { formatRange } from './model';
import type { Calculator, CalculatorStatus } from './types';

export const statusVariant = (s: CalculatorStatus) => (s === 'active' ? 'success' : s === 'paused' ? 'warning' : 'outline');

/** Cotizador (M18): las calculadoras y las cotizaciones que piden en el sitio. */
export function QuotesPage() {
    const { t, i18n } = useTranslation();
    const { workspace } = useWorkspace();
    const list = useCalculators(workspace.id);
    const [page, setPage] = useState(1);
    const requests = useQuoteRequests(workspace.id, '', page);
    const wizard = `/w/${workspace.id}/en-el-sitio/cotizador/nuevo`;

    return (
        <>
            <PageHeader
                title={t('quotes.title')}
                description={t('quotes.description')}
                actions={
                    list.data?.can_manage ? (
                        <Button asChild>
                            <Link to={wizard}>
                                <Plus aria-hidden />
                                {t('quotes.new')}
                            </Link>
                        </Button>
                    ) : null
                }
            />
            {list.isError ? (
                <ErrorState error={list.error} onRetry={() => void list.refetch()} />
            ) : list.isPending ? (
                <>
                    <StatGrid className="igr:mb-6 igr:lg:grid-cols-3">
                        {[0, 1, 2].map((i) => (
                            <StatCardSkeleton key={i} />
                        ))}
                    </StatGrid>
                    <Skeleton className="igr:h-48 igr:rounded-xl" />
                </>
            ) : list.data.data.length === 0 ? (
                <EmptyState
                    illustration="results"
                    title={t('quotes.empty')}
                    description={t('quotes.emptyHelp')}
                    action={
                        list.data.can_manage ? (
                            <Button size="lg" asChild>
                                <Link to={wizard}>
                                    <Plus aria-hidden />
                                    {t('quotes.emptyAction')}
                                </Link>
                            </Button>
                        ) : undefined
                    }
                    steps={[t('quotes.emptyStep1'), t('quotes.emptyStep2'), t('quotes.emptyStep3')]}
                />
            ) : (
                <>
                    <Totals calculators={list.data.data} currency={list.data.currency} />
                    <Card className="igr:overflow-hidden">
                        <Table>
                            <THead>
                                <TR>
                                    <TH>{t('quotes.list.name')}</TH>
                                    <TH>{t('quotes.list.kind')}</TH>
                                    <TH>{t('quotes.list.status')}</TH>
                                    <TH className="igr:text-right">{t('quotes.list.quotes')}</TH>
                                    <TH className="igr:text-right">{t('quotes.list.leads')}</TH>
                                    <TH className="igr:text-right">{t('quotes.list.avg')}</TH>
                                </TR>
                            </THead>
                            <TBody>
                                {list.data.data.map((c) => (
                                    <TR key={c.id}>
                                        <TD>
                                            <Link to={`/w/${workspace.id}/en-el-sitio/cotizador/${c.id}`} className="igr:font-medium igr:hover:underline">
                                                {c.name}
                                            </Link>
                                        </TD>
                                        <TD>{t(`quotes.kind.${c.kind}`)}</TD>
                                        <TD>
                                            <Badge variant={statusVariant(c.status)}>{t(`quotes.status.${c.status}`)}</Badge>
                                        </TD>
                                        <TD className="igr:text-right igr:tabular-nums">{c.stats?.quotes_30d ?? 0}</TD>
                                        <TD className="igr:text-right igr:tabular-nums">{c.stats?.leads_30d ?? 0}</TD>
                                        <TD className="igr:text-right igr:tabular-nums">
                                            {c.stats?.avg_mid ? formatRange(c.stats.avg_mid, c.stats.avg_mid, c.definition.currency ?? list.data.currency, i18n.language) : '—'}
                                        </TD>
                                    </TR>
                                ))}
                            </TBody>
                        </Table>
                        <p className="igr:border-t igr:px-4 igr:py-2.5 igr:text-caption igr:text-muted-foreground">{t('quotes.list.last30')}</p>
                    </Card>
                </>
            )}

            <Card className="igr:mt-6">
                <CardHeader>
                    <CardTitle>{t('quotes.requests.title')}</CardTitle>
                </CardHeader>
                <CardContent>
                    {requests.isPending ? (
                        <Skeleton className="igr:h-32" />
                    ) : requests.isError ? (
                        <ErrorState error={requests.error} onRetry={() => void requests.refetch()} />
                    ) : requests.data.data.length === 0 ? (
                        <p className="igr:py-6 igr:text-center igr:text-support igr:text-muted-foreground">{t('quotes.requests.empty')}</p>
                    ) : (
                        <>
                            <Table>
                                <THead>
                                    <TR>
                                        <TH>{t('quotes.requests.when')}</TH>
                                        <TH>{t('quotes.requests.contact')}</TH>
                                        <TH>{t('quotes.requests.calculator')}</TH>
                                        <TH className="igr:text-right">{t('quotes.requests.estimate')}</TH>
                                    </TR>
                                </THead>
                                <TBody>
                                    {requests.data.data.map((r) => (
                                        <TR key={r.id}>
                                            <TD className="igr:whitespace-nowrap igr:tabular-nums">
                                                {new Intl.DateTimeFormat(i18n.language, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(r.created_at))}
                                            </TD>
                                            <TD>
                                                {r.contact ? (
                                                    <Link to={`/w/${workspace.id}/contactos/${r.contact.id}`} className="igr:hover:underline">
                                                        {r.contact.name ?? r.contact.phone ?? r.contact.email}
                                                    </Link>
                                                ) : (
                                                    <span className="igr:text-muted-foreground">{t('quotes.requests.anonymous')}</span>
                                                )}
                                                {r.lead_id ? (
                                                    <Link
                                                        to={`/w/${workspace.id}/leads?lead=${r.lead_id}`}
                                                        className="igr:ml-2 igr:rounded-full igr:focus-visible:ring-2 igr:focus-visible:ring-ring"
                                                    >
                                                        <Badge variant="brand">{t('quotes.requests.lead')}</Badge>
                                                    </Link>
                                                ) : null}
                                            </TD>
                                            <TD>{r.calculator}</TD>
                                            <TD className="igr:text-right igr:tabular-nums">
                                                {r.estimate_min && r.estimate_max ? formatRange(r.estimate_min, r.estimate_max, r.currency, i18n.language) : '—'}
                                            </TD>
                                        </TR>
                                    ))}
                                </TBody>
                            </Table>
                            {requests.data.meta.last_page > 1 ? (
                                <div className="igr:mt-3 igr:flex igr:items-center igr:justify-end igr:gap-2 igr:text-[13px]">
                                    <Button size="sm" variant="outline" disabled={page <= 1} onClick={() => setPage(page - 1)}>
                                        {t('quotes.requests.prev')}
                                    </Button>
                                    <span className="igr:tabular-nums">
                                        {page} / {requests.data.meta.last_page}
                                    </span>
                                    <Button size="sm" variant="outline" disabled={page >= requests.data.meta.last_page} onClick={() => setPage(page + 1)}>
                                        {t('quotes.requests.next')}
                                    </Button>
                                </div>
                            ) : null}
                        </>
                    )}
                </CardContent>
            </Card>
        </>
    );
}

/** Lo que trajeron las calculadoras en 30 días: cotizaciones, leads y el valor medio (ponderado por cotizaciones). */
function Totals({ calculators, currency }: { calculators: Calculator[]; currency: string }) {
    const { t, i18n } = useTranslation();
    const { workspace } = useWorkspace();
    const quotes = calculators.reduce((n, c) => n + (c.stats?.quotes_30d ?? 0), 0);
    const leads = calculators.reduce((n, c) => n + (c.stats?.leads_30d ?? 0), 0);
    const weighted = calculators.reduce((n, c) => n + Number(c.stats?.avg_mid ?? 0) * (c.stats?.quotes_30d ?? 0), 0);
    const avg = quotes > 0 ? String(Math.round(weighted / quotes)) : null;
    return (
        <StatGrid className="igr:mb-6 igr:lg:grid-cols-3">
            <StatCard label={t('quotes.totals.quotes')} value={formatNumber(quotes, workspace.locale)} hint={t('quotes.list.last30')} icon={FileText} tone="sky" />
            <StatCard
                label={t('quotes.totals.leads')}
                value={formatNumber(leads, workspace.locale)}
                help={t('quotes.totals.leadsHelp')}
                hint={t('quotes.list.last30')}
                icon={Target}
                tone="rose"
            />
            <StatCard
                label={t('quotes.totals.avg')}
                value={avg ? formatRange(avg, avg, currency, i18n.language) : '—'}
                help={t('quotes.totals.avgHelp')}
                icon={Wallet}
                tone="emerald"
            />
        </StatGrid>
    );
}
