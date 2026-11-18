import { ChevronLeft, ChevronRight, Search, Users } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useContacts } from '@/api/queries';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { EmptyState, ErrorState, PageHeader } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TBody, TD, TH, THead, TR } from '@/components/ui/table';
import { useDebounced } from '@/hooks/useDebounced';
import { useWorkspace } from '@/hooks/useWorkspace';
import { formatDate, formatMoney, formatNumber } from '@/lib/format';

export function ContactsPage() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const [query, setQuery] = useState('');
    const [page, setPage] = useState(1);
    const q = useDebounced(query.trim(), 300);
    const contacts = useContacts(workspace.id, q, page);
    const meta = contacts.data?.meta;
    const pages = meta ? Math.max(1, Math.ceil(meta.total / meta.per_page)) : 1;

    return (
        <>
            <PageHeader
                title={t('contacts.title')}
                description={meta ? t('contacts.count', { count: meta.total, formatted: formatNumber(meta.total, workspace.locale) }) : t('contacts.description')}
            />
            <div className="igr:mb-3 igr:flex igr:items-center igr:gap-2">
                <div className="igr:relative igr:w-full igr:max-w-sm">
                    <Search className="igr:pointer-events-none igr:absolute igr:left-2.5 igr:top-2 igr:size-4 igr:text-muted-foreground" />
                    <Input
                        className="igr:pl-8"
                        placeholder={t('contacts.search')}
                        aria-label={t('contacts.search')}
                        value={query}
                        onChange={(e) => {
                            setQuery(e.target.value);
                            setPage(1);
                        }}
                    />
                </div>
                <p className="igr:hidden igr:text-xs igr:text-muted-foreground igr:md:block">{t('contacts.searchHelp')}</p>
            </div>
            {contacts.isError ? (
                <ErrorState error={contacts.error} onRetry={() => void contacts.refetch()} />
            ) : contacts.isPending ? (
                <Skeleton className="igr:h-72" />
            ) : contacts.data.data.length === 0 ? (
                <EmptyState icon={Users} title={q === '' ? t('contacts.emptyTitle') : t('contacts.noResults')} description={q === '' ? t('contacts.emptyDescription') : t('contacts.noResultsHelp')} />
            ) : (
                <Card>
                    <Table>
                        <THead>
                            <TR className="igr:hover:bg-transparent">
                                <TH>{t('contacts.columns.person')}</TH>
                                <TH>{t('contacts.columns.city')}</TH>
                                <TH>{t('contacts.columns.stage')}</TH>
                                <TH className="igr:text-right">{t('contacts.columns.orders')}</TH>
                                <TH className="igr:text-right">{t('contacts.columns.revenue')}</TH>
                                <TH className="igr:text-right">{t('contacts.columns.lastOrder')}</TH>
                            </TR>
                        </THead>
                        <TBody>
                            {contacts.data.data.map((c) => (
                                <TR key={c.id}>
                                    <TD>
                                        <p className="igr:font-medium">{c.name !== '' ? c.name : t('contacts.noName')}</p>
                                        <p className="igr:text-xs igr:text-muted-foreground">{[c.email, c.phone].filter(Boolean).join(' · ') || '—'}</p>
                                    </TD>
                                    <TD className="igr:text-muted-foreground">{c.city ?? '—'}</TD>
                                    <TD>{c.lifecycle_stage ? <Badge variant="secondary">{t(`stages.${c.lifecycle_stage}`, { defaultValue: c.lifecycle_stage })}</Badge> : '—'}</TD>
                                    <TD className="igr:text-right igr:tabular-nums">{formatNumber(c.orders_count, workspace.locale)}</TD>
                                    <TD className="igr:text-right igr:tabular-nums">{formatMoney(c.revenue_total_ws, workspace.currency, workspace.locale)}</TD>
                                    <TD className="igr:text-right igr:text-muted-foreground">{formatDate(c.last_order_at, workspace.locale)}</TD>
                                </TR>
                            ))}
                        </TBody>
                    </Table>
                    <div className="igr:flex igr:items-center igr:justify-between igr:border-t igr:px-3 igr:py-2 igr:text-xs igr:text-muted-foreground">
                        <span>{t('contacts.page', { page, pages })}</span>
                        <div className="igr:flex igr:gap-1">
                            <Button variant="outline" size="sm" disabled={page <= 1} onClick={() => setPage(page - 1)} aria-label={t('common.previous')}>
                                <ChevronLeft />
                            </Button>
                            <Button variant="outline" size="sm" disabled={page >= pages} onClick={() => setPage(page + 1)} aria-label={t('common.next')}>
                                <ChevronRight />
                            </Button>
                        </div>
                    </div>
                </Card>
            )}
        </>
    );
}
