import { Search, Users } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input, Select } from '@/components/ui/input';
import { EmptyState, ErrorState } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TBody, TD, TH, THead, TR } from '@/components/ui/table';
import { useDebounced } from '@/hooks/useDebounced';
import { useWorkspace } from '@/hooks/useWorkspace';
import { formatDate, formatMoney, formatNumber } from '@/lib/format';
import { useLoyaltyMembers } from './api';
import type { LoyaltyOverview } from './types';

/** Miembros del programa con sus saldos; cada fila abre la pestaña Fidelización del perfil. */
export function MembersTab({ data }: { data: LoyaltyOverview }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const [q, setQ] = useState('');
    const [tier, setTier] = useState('');
    const [sort, setSort] = useState('points');
    const [page, setPage] = useState(1);
    const search = useDebounced(q.trim(), 300);
    const members = useLoyaltyMembers(workspace.id, { q: search, tier, sort, page });

    return (
        <div className="igr:space-y-3">
            <div className="igr:flex igr:flex-wrap igr:items-center igr:gap-2">
                <div className="igr:relative">
                    <Search className="igr:absolute igr:top-2 igr:left-2.5 igr:size-4 igr:text-muted-foreground" />
                    <Input aria-label={t('loyalty.members.search')} placeholder={t('loyalty.members.search')} className="igr:w-72 igr:pl-8" value={q} onChange={(e) => { setQ(e.target.value); setPage(1); }} />
                </div>
                <Select aria-label={t('loyalty.members.tier')} value={tier} onChange={(e) => { setTier(e.target.value); setPage(1); }}>
                    <option value="">{t('loyalty.members.allTiers')}</option>
                    {data.tiers.map((x) => (
                        <option key={x.id} value={x.id}>
                            {x.name}
                        </option>
                    ))}
                </Select>
                <Select aria-label={t('loyalty.members.sort')} value={sort} onChange={(e) => { setSort(e.target.value); setPage(1); }}>
                    {['points', 'wallet', 'recent'].map((s) => (
                        <option key={s} value={s}>
                            {t(`loyalty.members.sortBy.${s}`)}
                        </option>
                    ))}
                </Select>
            </div>
            {members.isError ? (
                <ErrorState error={members.error} onRetry={() => void members.refetch()} />
            ) : members.isPending ? (
                <Skeleton className="igr:h-64" />
            ) : members.data.data.length === 0 ? (
                <EmptyState icon={Users} title={t('loyalty.members.emptyTitle')} description={search !== '' || tier !== '' ? t('loyalty.members.emptyFiltered') : t('loyalty.members.emptyHelp')} />
            ) : (
                <Card className="igr:overflow-hidden">
                    <Table>
                        <THead>
                            <TR>
                                <TH>{t('loyalty.members.contact')}</TH>
                                <TH>{t('loyalty.members.tier')}</TH>
                                <TH className="igr:text-right">{data.program.currency_name}</TH>
                                <TH className="igr:text-right">{t('loyalty.members.wallet')}</TH>
                                <TH>{t('loyalty.members.expiring')}</TH>
                                <TH className="igr:text-right">{t('loyalty.members.orders')}</TH>
                            </TR>
                        </THead>
                        <TBody>
                            {members.data.data.map((m) => (
                                <TR key={m.id}>
                                    <TD>
                                        <Link to={`/w/${workspace.id}/contactos/${m.id}?tab=fidelizacion`} className="igr:font-medium igr:hover:underline">
                                            {m.name !== '' ? m.name : t('loyalty.members.noName')}
                                        </Link>
                                        {m.email ? <p className="igr:text-xs igr:text-muted-foreground">{m.email}</p> : null}
                                    </TD>
                                    <TD>{m.tier?.name ?? '—'}</TD>
                                    <TD className="igr:text-right igr:tabular-nums">{formatNumber(m.points, workspace.locale)}</TD>
                                    <TD className="igr:text-right igr:tabular-nums">{formatMoney(m.wallet, data.currency, workspace.locale)}</TD>
                                    <TD className="igr:text-xs igr:text-muted-foreground">
                                        {m.points_expiring > 0 && m.points_expiring_at ? t('loyalty.members.expiringOn', { points: formatNumber(m.points_expiring, workspace.locale), date: formatDate(m.points_expiring_at, workspace.locale) }) : '—'}
                                    </TD>
                                    <TD className="igr:text-right igr:tabular-nums">{formatNumber(m.orders_count, workspace.locale)}</TD>
                                </TR>
                            ))}
                        </TBody>
                    </Table>
                </Card>
            )}
            {members.data && members.data.meta.last_page > 1 ? (
                <div className="igr:flex igr:items-center igr:justify-between igr:text-xs igr:text-muted-foreground">
                    <span>{t('loyalty.members.total', { count: members.data.meta.total, n: formatNumber(members.data.meta.total, workspace.locale) })}</span>
                    <div className="igr:flex igr:gap-2">
                        <Button variant="outline" size="sm" disabled={page <= 1} onClick={() => setPage(page - 1)}>
                            {t('loyalty.members.prev')}
                        </Button>
                        <Button variant="outline" size="sm" disabled={page >= members.data.meta.last_page} onClick={() => setPage(page + 1)}>
                            {t('loyalty.members.next')}
                        </Button>
                    </div>
                </div>
            ) : null}
        </div>
    );
}
