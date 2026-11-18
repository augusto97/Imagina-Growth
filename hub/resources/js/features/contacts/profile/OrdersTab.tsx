import { ChevronDown, ChevronLeft, ChevronRight, Package } from 'lucide-react';
import { Fragment, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { EmptyState, ErrorState } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TBody, TD, TH, THead, TR } from '@/components/ui/table';
import { useWorkspace } from '@/hooks/useWorkspace';
import { formatDate, formatMoney, formatNumber } from '@/lib/format';
import { cn } from '@/lib/cn';
import { useContactOrders } from '../api';

export function OrdersTab({ contactId, enabled }: { contactId: string; enabled: boolean }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const [page, setPage] = useState(1);
    const [open, setOpen] = useState<string | null>(null);
    const orders = useContactOrders(workspace.id, contactId, page, enabled);

    if (!enabled) {
        return <EmptyState icon={Package} title={t('contacts.orders.forbidden')} />;
    }
    if (orders.isError) {
        return <ErrorState error={orders.error} onRetry={() => void orders.refetch()} />;
    }
    if (orders.isPending) {
        return <Skeleton className="igr:h-60" />;
    }
    if (orders.data.data.length === 0) {
        return <EmptyState icon={Package} title={t('contacts.orders.emptyTitle')} description={t('contacts.orders.emptyDescription')} />;
    }
    const meta = orders.data.meta;
    const pages = Math.max(1, Math.ceil(meta.total / meta.per_page));

    return (
        <Card>
            <Table>
                <THead>
                    <TR className="igr:hover:bg-transparent">
                        <TH className="igr:w-8">
                            <span className="igr:sr-only">{t('contacts.orders.expand')}</span>
                        </TH>
                        <TH>{t('contacts.orders.number')}</TH>
                        <TH>{t('contacts.orders.date')}</TH>
                        <TH>{t('contacts.orders.status')}</TH>
                        <TH className="igr:hidden igr:md:table-cell">{t('contacts.orders.payment')}</TH>
                        <TH className="igr:hidden igr:lg:table-cell">{t('contacts.orders.channel')}</TH>
                        <TH className="igr:text-right">{t('contacts.orders.total')}</TH>
                    </TR>
                </THead>
                <TBody>
                    {orders.data.data.map((o) => {
                        const expanded = open === o.id;
                        const currency = o.currency ?? workspace.currency;
                        const refunded = o.refunded !== null && Number(o.refunded) > 0;
                        return (
                            <Fragment key={o.id}>
                                <TR className="igr:cursor-pointer" onClick={() => setOpen(expanded ? null : o.id)}>
                                    <TD>
                                        <button
                                            type="button"
                                            className="igr:rounded igr:p-0.5 igr:outline-none igr:focus-visible:ring-2 igr:focus-visible:ring-ring"
                                            aria-expanded={expanded}
                                            aria-label={t('contacts.orders.toggleItems', { number: o.number ?? o.id })}
                                            onClick={(e) => {
                                                e.stopPropagation();
                                                setOpen(expanded ? null : o.id);
                                            }}
                                        >
                                            <ChevronDown className={cn('igr:size-4 igr:text-muted-foreground igr:transition-transform', expanded && 'igr:rotate-180')} />
                                        </button>
                                    </TD>
                                    <TD>
                                        <span className="igr:font-medium igr:tabular-nums">#{o.number ?? '—'}</span>
                                        {o.order_index === 1 && (
                                            <Badge variant="default" className="igr:ml-2">
                                                {t('contacts.orders.first')}
                                            </Badge>
                                        )}
                                    </TD>
                                    <TD className="igr:whitespace-nowrap igr:text-muted-foreground">{formatDate(o.created_at, workspace.locale, workspace.timezone)}</TD>
                                    <TD>
                                        <div className="igr:flex igr:flex-wrap igr:gap-1">
                                            <Badge variant={o.status === 'completed' ? 'success' : ['cancelled', 'failed', 'refunded'].includes(o.status) ? 'outline' : 'secondary'}>
                                                {t(`orderStatus.${o.status}`, { defaultValue: o.status })}
                                            </Badge>
                                            {o.is_cod && <Badge variant="outline">{t('contacts.orders.cod')}</Badge>}
                                            {!o.is_paid && !['cancelled', 'failed'].includes(o.status) && <Badge variant="warning">{t('contacts.orders.unpaid')}</Badge>}
                                        </div>
                                    </TD>
                                    <TD className="igr:hidden igr:text-muted-foreground igr:md:table-cell">{o.payment_method ?? '—'}</TD>
                                    <TD className="igr:hidden igr:text-muted-foreground igr:lg:table-cell">
                                        {o.channel_last ? t(`contacts.channels.${o.channel_last}`, { defaultValue: o.channel_last }) : '—'}
                                    </TD>
                                    <TD className="igr:whitespace-nowrap igr:text-right igr:tabular-nums">
                                        {formatMoney(o.total, currency, workspace.locale)}
                                        {refunded && (
                                            <p className="igr:text-[11px] igr:text-destructive">
                                                {t('contacts.orders.refunded', { amount: formatMoney(o.refunded, currency, workspace.locale) })}
                                            </p>
                                        )}
                                    </TD>
                                </TR>
                                {expanded && (
                                    <TR className="igr:bg-muted/30 igr:hover:bg-muted/30">
                                        <TD />
                                        <TD colSpan={6} className="igr:py-3">
                                            <ul className="igr:space-y-1 igr:text-[13px]">
                                                {o.items.map((item, i) => (
                                                    <li key={i} className="igr:flex igr:justify-between igr:gap-4">
                                                        <span>
                                                            <span className="igr:tabular-nums igr:text-muted-foreground">{formatNumber(Number(item.qty), workspace.locale)} ×</span> {item.name}
                                                            {item.sku && <span className="igr:ml-1 igr:text-xs igr:text-muted-foreground">({item.sku})</span>}
                                                        </span>
                                                        <span className="igr:tabular-nums">{formatMoney(item.line_total, currency, workspace.locale)}</span>
                                                    </li>
                                                ))}
                                            </ul>
                                            {(o.coupons.length > 0 || o.city) && (
                                                <p className="igr:mt-2 igr:text-xs igr:text-muted-foreground">
                                                    {[o.coupons.length > 0 ? t('contacts.orders.coupons', { codes: o.coupons.join(', ') }) : null, o.city ? t('contacts.orders.shipTo', { city: o.city }) : null]
                                                        .filter(Boolean)
                                                        .join(' · ')}
                                                </p>
                                            )}
                                        </TD>
                                    </TR>
                                )}
                            </Fragment>
                        );
                    })}
                </TBody>
            </Table>
            {pages > 1 && (
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
            )}
        </Card>
    );
}
