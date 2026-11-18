import { Send } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { Badge } from '@/components/ui/badge';
import { Select } from '@/components/ui/input';
import { EmptyState, ErrorState } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TBody, TD, TH, THead, TR } from '@/components/ui/table';
import { useWorkspace } from '@/hooks/useWorkspace';
import { formatDateTime } from '@/lib/format';
import { useReviewRequests } from './api';
import { Pager } from './ReviewsPage';
import type { RequestStatus } from './types';

const STATUSES: RequestStatus[] = ['scheduled', 'sent', 'completed', 'skipped', 'expired'];

/** Solicitudes de reseña por pedido: cuándo salen, por qué canal y si se completaron. */
export function RequestsTab() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const [status, setStatus] = useState('');
    const [page, setPage] = useState(1);
    const list = useReviewRequests(workspace.id, status, page);
    const when = (iso: string | null) => (iso ? formatDateTime(iso, workspace.locale, workspace.timezone) : '—');

    return (
        <div className="igr:space-y-3">
            <div className="igr:flex igr:items-center igr:gap-2">
                <Select
                    className="igr:w-auto"
                    aria-label={t('reviews.requests.status')}
                    value={status}
                    onChange={(e) => {
                        setStatus(e.target.value);
                        setPage(1);
                    }}
                >
                    <option value="">{t('reviews.requests.any')}</option>
                    {STATUSES.map((s) => (
                        <option key={s} value={s}>
                            {t(`reviews.requests.statuses.${s}`)}
                        </option>
                    ))}
                </Select>
                <p className="igr:text-[13px] igr:text-muted-foreground">{t('reviews.requests.hint')}</p>
            </div>
            {list.isError ? (
                <ErrorState error={list.error} onRetry={() => void list.refetch()} />
            ) : list.isPending ? (
                <Skeleton className="igr:h-64" />
            ) : list.data.data.length === 0 ? (
                <EmptyState icon={Send} title={t('reviews.requests.emptyTitle')} description={t('reviews.requests.emptyDescription')} />
            ) : (
                <>
                    <Table>
                        <THead>
                            <TR>
                                <TH>{t('reviews.requests.order')}</TH>
                                <TH>{t('reviews.requests.status')}</TH>
                                <TH>{t('reviews.requests.channel')}</TH>
                                <TH>{t('reviews.requests.scheduled')}</TH>
                                <TH>{t('reviews.requests.sent')}</TH>
                                <TH>{t('reviews.requests.completed')}</TH>
                            </TR>
                        </THead>
                        <TBody>
                            {list.data.data.map((r) => (
                                <TR key={r.id}>
                                    <TD>
                                        <Link className="igr:font-medium igr:hover:underline" to={`/w/${workspace.id}/contactos/${r.contact_id}`}>
                                            #{r.order.number}
                                        </Link>
                                        <span className="igr:ml-1 igr:text-xs igr:text-muted-foreground">{t('reviews.requests.products', { count: r.products })}</span>
                                    </TD>
                                    <TD>
                                        <Badge variant={r.status === 'completed' ? 'success' : r.status === 'skipped' || r.status === 'expired' ? 'outline' : 'secondary'}>
                                            {t(`reviews.requests.statuses.${r.status}`)}
                                        </Badge>
                                        {r.skip_reason ? <span className="igr:ml-1 igr:text-xs igr:text-muted-foreground">{t(`reviews.requests.reasons.${r.skip_reason}`, { defaultValue: r.skip_reason })}</span> : null}
                                    </TD>
                                    <TD>{r.channel ? t(`reviews.channels.${r.channel}`) : '—'}</TD>
                                    <TD className="igr:tabular-nums">{when(r.scheduled_for)}</TD>
                                    <TD className="igr:tabular-nums">{when(r.sent_at)}</TD>
                                    <TD className="igr:tabular-nums">{when(r.completed_at)}</TD>
                                </TR>
                            ))}
                        </TBody>
                    </Table>
                    <Pager page={page} total={list.data.meta.total} perPage={list.data.meta.per_page} onPage={setPage} />
                </>
            )}
        </div>
    );
}
