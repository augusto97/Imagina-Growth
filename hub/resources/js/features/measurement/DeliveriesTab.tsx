import { ListChecks } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Select } from '@/components/ui/input';
import { EmptyState, ErrorState } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TBody, TD, TH, THead, TR } from '@/components/ui/table';
import { Tooltip } from '@/components/ui/tooltip';
import { useWorkspace } from '@/hooks/useWorkspace';
import { formatDateTime } from '@/lib/format';
import { type DeliveryFilters, type DeliveryStatus, type Platform, useDeliveries, useDestinations } from './api';
import { DeliveryBadge, PlatformName } from './bits';

export function DeliveriesTab() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const [filters, setFilters] = useState<DeliveryFilters>({ platform: '', status: '', destination: '', days: 7, page: 1 });
    const deliveries = useDeliveries(workspace.id, filters);
    const destinations = useDestinations(workspace.id);
    const names = new Map((destinations.data?.destinations ?? []).map((d) => [d.id, d.name ?? d.platform]));
    const set = (patch: Partial<DeliveryFilters>) => setFilters((f) => ({ ...f, page: 1, ...patch }));

    return (
        <div className="igr:space-y-3">
            <div className="igr:flex igr:flex-wrap igr:gap-2">
                <Select aria-label={t('measurement.filters.platform')} className="igr:w-auto" value={filters.platform} onChange={(e) => set({ platform: e.target.value as Platform | '' })}>
                    <option value="">{t('measurement.filters.allPlatforms')}</option>
                    {(['meta', 'google', 'tiktok'] as const).map((p) => (
                        <option key={p} value={p}>
                            {t(`measurement.platform.${p}`)}
                        </option>
                    ))}
                </Select>
                <Select aria-label={t('measurement.filters.status')} className="igr:w-auto" value={filters.status} onChange={(e) => set({ status: e.target.value as DeliveryStatus | '' })}>
                    <option value="">{t('measurement.filters.allStatuses')}</option>
                    {(['delivered', 'retrying', 'failed', 'skipped'] as const).map((s) => (
                        <option key={s} value={s}>
                            {t(`measurement.status.${s}`)}
                        </option>
                    ))}
                </Select>
                <Select aria-label={t('measurement.filters.destination')} className="igr:w-auto igr:max-w-64" value={filters.destination} onChange={(e) => set({ destination: e.target.value })}>
                    <option value="">{t('measurement.filters.allDestinations')}</option>
                    {(destinations.data?.destinations ?? []).map((d) => (
                        <option key={d.id} value={d.id}>
                            {d.name ?? d.platform}
                        </option>
                    ))}
                </Select>
                <Select aria-label={t('measurement.filters.period')} className="igr:w-auto" value={filters.days} onChange={(e) => set({ days: Number(e.target.value) })}>
                    {[1, 7, 30, 90].map((d) => (
                        <option key={d} value={d}>
                            {t('measurement.filters.lastDays', { count: d })}
                        </option>
                    ))}
                </Select>
            </div>

            {deliveries.isPending ? (
                <Skeleton className="igr:h-64" />
            ) : deliveries.isError ? (
                <ErrorState error={deliveries.error} onRetry={() => void deliveries.refetch()} />
            ) : deliveries.data.data.length === 0 ? (
                <EmptyState icon={ListChecks} title={t('measurement.deliveries.emptyTitle')} description={t('measurement.deliveries.emptyHelp')} />
            ) : (
                <Card>
                    <Table>
                        <THead>
                            <TR>
                                <TH>{t('measurement.deliveries.when')}</TH>
                                <TH>{t('measurement.deliveries.destination')}</TH>
                                <TH>{t('measurement.deliveries.conversion')}</TH>
                                <TH>{t('measurement.deliveries.status')}</TH>
                                <TH>{t('measurement.deliveries.signal')}</TH>
                                <TH>{t('measurement.deliveries.detail')}</TH>
                            </TR>
                        </THead>
                        <TBody>
                            {deliveries.data.data.map((row, i) => (
                                <TR key={`${row.event_id}-${row.target}-${String(row.attempt)}-${String(i)}`}>
                                    <TD className="igr:whitespace-nowrap igr:text-xs igr:text-muted-foreground">{formatDateTime(row.at, workspace.locale, workspace.timezone)}</TD>
                                    <TD className="igr:text-xs">
                                        {(row.destination_id !== null ? names.get(row.destination_id) : undefined) ?? <PlatformName platform={row.platform} />}
                                        <span className="igr:block igr:text-muted-foreground">{t(`measurement.endpoint.${row.target}`)}</span>
                                    </TD>
                                    <TD className="igr:text-xs">
                                        <span className="igr:font-medium">{row.conversion}</span>
                                        <span className="igr:block igr:text-muted-foreground">{row.source_event}</span>
                                    </TD>
                                    <TD>
                                        <div className="igr:flex igr:flex-wrap igr:items-center igr:gap-1">
                                            <DeliveryBadge status={row.status} />
                                            {row.attempt > 1 ? <Badge variant="outline">{t('measurement.deliveries.attempt', { n: row.attempt })}</Badge> : null}
                                            {row.test ? <Badge variant="warning">{t('measurement.destinations.testMode')}</Badge> : null}
                                        </div>
                                    </TD>
                                    <TD className="igr:text-xs igr:text-muted-foreground">{row.match_keys.length > 0 ? row.match_keys.map((k) => t(`measurement.matchKey.${k}`)).join(', ') : '—'}</TD>
                                    <TD className="igr:max-w-80 igr:text-xs">
                                        {row.error ? (
                                            <Tooltip content={row.error_code ?? ''}>
                                                <span className={row.status === 'failed' ? 'igr:text-destructive' : 'igr:text-muted-foreground'}>{row.error}</span>
                                            </Tooltip>
                                        ) : (
                                            <span className="igr:text-muted-foreground">{row.http_status > 0 ? `HTTP ${String(row.http_status)}` : '—'}</span>
                                        )}
                                    </TD>
                                </TR>
                            ))}
                        </TBody>
                    </Table>
                </Card>
            )}
            {deliveries.data && (deliveries.data.page > 1 || deliveries.data.has_more) ? (
                <div className="igr:flex igr:justify-end igr:gap-2">
                    <Button variant="outline" size="sm" disabled={filters.page <= 1} onClick={() => setFilters((f) => ({ ...f, page: f.page - 1 }))}>
                        {t('common.previous')}
                    </Button>
                    <Button variant="outline" size="sm" disabled={!deliveries.data.has_more} onClick={() => setFilters((f) => ({ ...f, page: f.page + 1 }))}>
                        {t('common.next')}
                    </Button>
                </div>
            ) : null}
        </div>
    );
}
