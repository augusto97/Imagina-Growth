import { Calculator as CalcIcon, ChevronDown, Plus } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useNavigate } from 'react-router';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { EmptyState, ErrorState, PageHeader } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TBody, TD, TH, THead, TR } from '@/components/ui/table';
import { useWorkspace } from '@/hooks/useWorkspace';
import { toast } from '@/stores/toast';
import { errorMessage } from '@/features/contacts/errors';
import { useCalculators, useQuoteMutations, useQuoteRequests } from './api';
import { formatRange, preset } from './model';
import type { CalculatorKind, CalculatorStatus } from './types';

const KINDS: CalculatorKind[] = ['service', 'area', 'package'];

export const statusVariant = (s: CalculatorStatus) => (s === 'active' ? 'success' : s === 'paused' ? 'warning' : 'outline');

/** Cotizador (M18): las calculadoras y las cotizaciones que piden en el sitio. */
export function QuotesPage() {
    const { t, i18n } = useTranslation();
    const { workspace } = useWorkspace();
    const navigate = useNavigate();
    const list = useCalculators(workspace.id);
    const { save } = useQuoteMutations(workspace.id);
    const [page, setPage] = useState(1);
    const requests = useQuoteRequests(workspace.id, '', page);

    const create = (kind: CalculatorKind) => {
        const p = preset(kind, (k, o) => t(k, o ?? {}));
        save.mutate(
            { id: null, data: { name: p.name, kind, definition: p.definition } },
            { onSuccess: (c) => void navigate(`/w/${workspace.id}/en-el-sitio/cotizador/${c.id}`), onError: (e) => toast.error(errorMessage(e, t)) },
        );
    };

    return (
        <>
            <PageHeader
                title={t('quotes.title')}
                description={t('quotes.description')}
                actions={
                    list.data?.can_manage ? (
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <Button loading={save.isPending}>
                                    <Plus />
                                    {t('quotes.new')}
                                    <ChevronDown />
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end">
                                {KINDS.map((k) => (
                                    <DropdownMenuItem key={k} onSelect={() => create(k)}>
                                        <div>
                                            <p className="igr:font-medium">{t(`quotes.kind.${k}`)}</p>
                                            <p className="igr:text-xs igr:text-muted-foreground">{t(`quotes.kindHelp.${k}`)}</p>
                                        </div>
                                    </DropdownMenuItem>
                                ))}
                            </DropdownMenuContent>
                        </DropdownMenu>
                    ) : null
                }
            />
            {list.isError ? (
                <ErrorState error={list.error} onRetry={() => void list.refetch()} />
            ) : list.isPending ? (
                <Skeleton className="igr:h-48" />
            ) : list.data.data.length === 0 ? (
                <EmptyState icon={CalcIcon} title={t('quotes.empty')} description={t('quotes.emptyHelp')} />
            ) : (
                <Card>
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
                    <p className="igr:px-4 igr:py-2 igr:text-xs igr:text-muted-foreground">{t('quotes.list.last30')}</p>
                </Card>
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
                        <p className="igr:text-[13px] igr:text-muted-foreground">{t('quotes.requests.empty')}</p>
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
                                            <TD className="igr:whitespace-nowrap igr:tabular-nums">{new Intl.DateTimeFormat(i18n.language, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(r.created_at))}</TD>
                                            <TD>
                                                {r.contact ? (
                                                    <Link to={`/w/${workspace.id}/contactos/${r.contact.id}`} className="igr:hover:underline">
                                                        {r.contact.name ?? r.contact.phone ?? r.contact.email}
                                                    </Link>
                                                ) : (
                                                    <span className="igr:text-muted-foreground">{t('quotes.requests.anonymous')}</span>
                                                )}
                                                {r.lead_id ? (
                                                    <Badge variant="secondary" className="igr:ml-2">
                                                        {t('quotes.requests.lead')}
                                                    </Badge>
                                                ) : null}
                                            </TD>
                                            <TD>{r.calculator}</TD>
                                            <TD className="igr:text-right igr:tabular-nums">{r.estimate_min && r.estimate_max ? formatRange(r.estimate_min, r.estimate_max, r.currency, i18n.language) : '—'}</TD>
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
