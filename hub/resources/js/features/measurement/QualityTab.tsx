import { Gauge } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Select } from '@/components/ui/input';
import { EmptyState, ErrorState } from '@/components/ui/misc';
import { Progress } from '@/components/ui/progress';
import { Skeleton } from '@/components/ui/skeleton';
import { useWorkspace } from '@/hooks/useWorkspace';
import { formatNumber, formatPercent, formatRelative } from '@/lib/format';
import { type QualityStats, useDestinations, useQuality } from './api';
import { PlatformMark } from './bits';

const RATES = ['email_rate', 'phone_rate', 'click_id_rate', 'browser_id_rate', 'external_id_rate'] as const;

export function QualityTab() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const [days, setDays] = useState(7);
    const quality = useQuality(workspace.id, days);
    const destinations = useDestinations(workspace.id);

    if (quality.isPending || destinations.isPending) {
        return <Skeleton className="igr:h-48" />;
    }
    if (quality.isError) {
        return <ErrorState error={quality.error} onRetry={() => void quality.refetch()} />;
    }
    const list = destinations.data?.destinations ?? [];

    return (
        <div className="igr:space-y-3">
            <div className="igr:flex igr:items-center igr:justify-between igr:gap-2">
                <p className="igr:text-xs igr:text-muted-foreground">{t('measurement.quality.help')}</p>
                <Select aria-label={t('measurement.filters.period')} className="igr:w-36" value={days} onChange={(e) => setDays(Number(e.target.value))}>
                    {[7, 30, 90].map((d) => (
                        <option key={d} value={d}>
                            {t('measurement.filters.lastDays', { count: d })}
                        </option>
                    ))}
                </Select>
            </div>
            {list.length === 0 ? (
                <EmptyState icon={Gauge} title={t('measurement.empty.title')} description={t('measurement.empty.description')} />
            ) : (
                <div className="igr:grid igr:gap-3 igr:lg:grid-cols-2">
                    {list.map((d) => {
                        const stats: QualityStats | undefined = quality.data.destinations[d.id];
                        return (
                            <Card key={d.id}>
                                <CardHeader className="igr:flex igr:flex-row igr:items-center igr:gap-2">
                                    <PlatformMark platform={d.platform} />
                                    <CardTitle className="igr:text-sm">{d.name ?? t(`measurement.platform.${d.platform}`)}</CardTitle>
                                </CardHeader>
                                <CardContent className="igr:space-y-3">
                                    {stats === undefined || stats.total + stats.retrying === 0 ? (
                                        <p className="igr:text-xs igr:text-muted-foreground">{t('measurement.quality.noData')}</p>
                                    ) : (
                                        <>
                                            <div className="igr:grid igr:grid-cols-3 igr:gap-2 igr:text-center">
                                                <Stat label={t('measurement.status.delivered')} value={formatNumber(stats.delivered, workspace.locale)} tone="success" />
                                                <Stat label={t('measurement.status.failed')} value={formatNumber(stats.failed, workspace.locale)} tone={stats.failed > 0 ? 'destructive' : undefined} />
                                                <Stat label={t('measurement.status.retrying')} value={formatNumber(stats.retrying, workspace.locale)} />
                                            </div>
                                            <div className="igr:space-y-2">
                                                {RATES.map((rate) => (
                                                    <div key={rate} className="igr:space-y-1">
                                                        <div className="igr:flex igr:justify-between igr:text-xs">
                                                            <span>{t(`measurement.quality.${rate}`)}</span>
                                                            <span className="igr:tabular-nums igr:text-muted-foreground">{stats[rate] === null ? '—' : formatPercent(stats[rate], workspace.locale)}</span>
                                                        </div>
                                                        <Progress value={stats[rate]} label={t(`measurement.quality.${rate}`)} />
                                                    </div>
                                                ))}
                                            </div>
                                            {stats.last_errors.length > 0 ? (
                                                <div className="igr:space-y-1.5">
                                                    <p className="igr:text-xs igr:font-medium">{t('measurement.quality.lastErrors')}</p>
                                                    {stats.last_errors.map((err) => (
                                                        <p key={`${err.at}-${err.code}`} className="igr:text-xs igr:text-destructive">
                                                            <span className="igr:text-muted-foreground">{formatRelative(err.at, workspace.locale)} · {err.conversion}: </span>
                                                            {err.message}
                                                        </p>
                                                    ))}
                                                </div>
                                            ) : null}
                                        </>
                                    )}
                                </CardContent>
                            </Card>
                        );
                    })}
                </div>
            )}
        </div>
    );
}

function Stat({ label, value, tone }: { label: string; value: string; tone?: 'success' | 'destructive' | undefined }) {
    return (
        <div className="igr:rounded-md igr:border igr:p-2">
            <p className={`igr:text-lg igr:font-semibold igr:tabular-nums ${tone === 'success' ? 'igr:text-success' : tone === 'destructive' ? 'igr:text-destructive' : ''}`}>{value}</p>
            <p className="igr:text-[11px] igr:text-muted-foreground">{label}</p>
        </div>
    );
}
