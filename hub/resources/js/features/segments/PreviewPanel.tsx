import { Clock, Mail, MessageCircle, TriangleAlert, Users } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TBody, TD, TH, THead, TR } from '@/components/ui/table';
import { useWorkspace } from '@/hooks/useWorkspace';
import { isApiError } from '@/lib/api';
import { cn } from '@/lib/cn';
import { formatMoney, formatNumber, formatRelative } from '@/lib/format';
import type { Preview, SegmentContact } from './types';

/** Conteo en vivo, alcance por canal, valor y muestra de 20 contactos. */
export function PreviewPanel({ data, error, loading, stale, empty }: { data: Preview | undefined; error: unknown; loading: boolean; stale: boolean; empty: boolean }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();

    if (empty) {
        return (
            <Card>
                <CardContent className="igr:py-8 igr:text-center igr:text-[13px] igr:text-muted-foreground">
                    <Users className="igr:mx-auto igr:mb-2 igr:size-5" />
                    {t('segments.preview.empty')}
                </CardContent>
            </Card>
        );
    }

    const timeout = isApiError(error, 'segment_timeout');
    const invalid = isApiError(error, 'validation');

    return (
        <Card aria-live="polite" aria-busy={loading || stale}>
            <CardHeader className="igr:flex-row igr:items-start igr:justify-between">
                <div>
                    <CardTitle>{t('segments.preview.title')}</CardTitle>
                    <p className="igr:text-xs igr:text-muted-foreground">{t('segments.preview.subtitle')}</p>
                </div>
                {data ? (
                    <span className="igr:flex igr:items-center igr:gap-1 igr:text-xs igr:text-muted-foreground">
                        <Clock className="igr:size-3" />
                        {t('segments.preview.took', { ms: formatNumber(data.took_ms, workspace.locale) })}
                    </span>
                ) : null}
            </CardHeader>
            <CardContent className="igr:space-y-4">
                {timeout || (error && !invalid) ? (
                    <div role="alert" className="igr:flex igr:items-start igr:gap-2 igr:rounded-md igr:bg-warning/10 igr:p-3 igr:text-[13px]">
                        <TriangleAlert className="igr:mt-0.5 igr:size-4 igr:shrink-0 igr:text-warning" />
                        <span>{timeout ? t('segments.preview.timeout') : isApiError(error) && error.message !== '' ? error.message : t('errors.generic')}</span>
                    </div>
                ) : null}
                {invalid && isApiError(error) ? (
                    <div role="alert" className="igr:rounded-md igr:bg-destructive/8 igr:p-3 igr:text-[13px] igr:text-destructive">
                        <p className="igr:font-medium">{t('segments.preview.invalid')}</p>
                        <ul className="igr:mt-1 igr:list-disc igr:pl-4 igr:text-xs">
                            {[...new Set(Object.values(error.fieldErrors))].map((m) => (
                                <li key={m}>{m}</li>
                            ))}
                        </ul>
                    </div>
                ) : null}

                {data === undefined && loading ? (
                    <div className="igr:space-y-2">
                        <Skeleton className="igr:h-10 igr:w-32" />
                        <Skeleton className="igr:h-4 igr:w-48" />
                        <Skeleton className="igr:h-40" />
                    </div>
                ) : null}

                {data ? (
                    <div className={cn('igr:space-y-4 igr:transition-opacity', (loading || stale) && 'igr:opacity-60')}>
                        <div>
                            <p className="igr:text-3xl igr:font-semibold igr:tabular-nums igr:tracking-tight">{formatNumber(data.count, workspace.locale)}</p>
                            <p className="igr:text-xs igr:text-muted-foreground">{t('segments.preview.contacts', { count: data.count })}</p>
                        </div>
                        <div className="igr:grid igr:grid-cols-2 igr:gap-2 igr:text-[13px]">
                            <Stat icon={MessageCircle} label={t('segments.preview.whatsapp')} value={formatNumber(data.channels.whatsapp, workspace.locale)} />
                            <Stat icon={Mail} label={t('segments.preview.email')} value={formatNumber(data.channels.email, workspace.locale)} />
                            <Stat label={t('segments.preview.revenue')} value={formatMoney(data.value.revenue_ws, workspace.currency, workspace.locale)} />
                            <Stat label={t('segments.preview.clv')} value={data.value.predicted_clv_ws === null ? t('segments.preview.clvLater') : formatMoney(data.value.predicted_clv_ws, workspace.currency, workspace.locale)} />
                        </div>
                        <SampleTable rows={data.sample} />
                    </div>
                ) : null}
            </CardContent>
        </Card>
    );
}

function Stat({ icon: Icon, label, value }: { icon?: typeof Mail; label: string; value: string }) {
    return (
        <div className="igr:rounded-md igr:border igr:px-2.5 igr:py-2">
            <p className="igr:flex igr:items-center igr:gap-1 igr:text-xs igr:text-muted-foreground">
                {Icon ? <Icon className="igr:size-3" /> : null}
                {label}
            </p>
            <p className="igr:mt-0.5 igr:font-medium igr:tabular-nums">{value}</p>
        </div>
    );
}

export function SampleTable({ rows, dense = true }: { rows: SegmentContact[]; dense?: boolean }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    if (rows.length === 0) {
        return <p className="igr:py-4 igr:text-center igr:text-xs igr:text-muted-foreground">{t('segments.preview.noSample')}</p>;
    }
    return (
        <Table>
            <THead>
                <TR>
                    <TH>{t('segments.members.contact')}</TH>
                    {dense ? null : <TH>{t('segments.members.city')}</TH>}
                    <TH className="igr:text-right">{t('segments.members.orders')}</TH>
                    <TH className="igr:text-right">{t('segments.members.revenue')}</TH>
                    {dense ? null : <TH>{t('segments.members.lastSeen')}</TH>}
                </TR>
            </THead>
            <TBody>
                {rows.map((c) => (
                    <TR key={c.id}>
                        <TD className="igr:max-w-56">
                            <Link to={`/w/${workspace.id}/contactos/${c.id}`} className="igr:block igr:truncate igr:font-medium igr:hover:underline">
                                {c.name}
                            </Link>
                            <span className="igr:block igr:truncate igr:text-xs igr:text-muted-foreground">{[c.email, c.phone].filter(Boolean).join(' · ') || '—'}</span>
                        </TD>
                        {dense ? null : <TD>{c.city ?? '—'}</TD>}
                        <TD className="igr:text-right igr:tabular-nums">{formatNumber(c.orders_count, workspace.locale)}</TD>
                        <TD className="igr:text-right igr:tabular-nums">{formatMoney(c.revenue_total_ws, workspace.currency, workspace.locale)}</TD>
                        {dense ? null : <TD className="igr:text-xs igr:text-muted-foreground">{formatRelative(c.last_seen_at, workspace.locale)}</TD>}
                    </TR>
                ))}
            </TBody>
        </Table>
    );
}
