import { Wallet } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Select } from '@/components/ui/input';
import { EmptyState, ErrorState } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TBody, TD, TH, THead, TR } from '@/components/ui/table';
import { useWorkspace } from '@/hooks/useWorkspace';
import { formatMoney, formatNumber } from '@/lib/format';
import { useSpend } from './api';
import { PlatformMark, PlatformName } from './bits';

export function SpendTab() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const [days, setDays] = useState(30);
    const spend = useSpend(workspace.id, days);

    if (spend.isPending) {
        return <Skeleton className="igr:h-48" />;
    }
    if (spend.isError) {
        return <ErrorState error={spend.error} onRetry={() => void spend.refetch()} />;
    }
    const data = spend.data;

    return (
        <div className="igr:space-y-3">
            <div className="igr:flex igr:items-center igr:justify-between">
                <p className="igr:text-xs igr:text-muted-foreground">{t('measurement.spend.help')}</p>
                <Select aria-label={t('measurement.filters.period')} className="igr:w-36" value={days} onChange={(e) => setDays(Number(e.target.value))}>
                    {[7, 30, 90].map((d) => (
                        <option key={d} value={d}>
                            {t('measurement.filters.lastDays', { count: d })}
                        </option>
                    ))}
                </Select>
            </div>
            {data.by_platform.length === 0 ? (
                <EmptyState icon={Wallet} title={t('measurement.spend.emptyTitle')} description={t('measurement.spend.emptyHelp')} />
            ) : (
                <>
                    <div className="igr:grid igr:gap-3 igr:sm:grid-cols-3">
                        {data.by_platform.map((row) => (
                            <Card key={`${row.platform}-${row.currency}`}>
                                <CardContent className="igr:flex igr:items-center igr:gap-3 igr:py-4">
                                    <PlatformMark platform={row.platform} />
                                    <div>
                                        <p className="igr:text-lg igr:font-semibold igr:tabular-nums">{formatMoney(row.spend, row.currency, workspace.locale)}</p>
                                        <p className="igr:text-xs igr:text-muted-foreground">
                                            <PlatformName platform={row.platform} /> · {t('measurement.spend.clicks', { count: row.clicks ?? 0, n: formatNumber(row.clicks ?? 0, workspace.locale) })}
                                        </p>
                                    </div>
                                </CardContent>
                            </Card>
                        ))}
                    </div>
                    <Card>
                        <CardHeader>
                            <CardTitle className="igr:text-sm">{t('measurement.spend.campaigns')}</CardTitle>
                        </CardHeader>
                        <Table>
                            <THead>
                                <TR>
                                    <TH>{t('measurement.spend.campaign')}</TH>
                                    <TH>{t('measurement.spend.platform')}</TH>
                                    <TH className="igr:text-right">{t('measurement.spend.impressions')}</TH>
                                    <TH className="igr:text-right">{t('measurement.spend.clicksHeader')}</TH>
                                    <TH className="igr:text-right">{t('measurement.spend.spend')}</TH>
                                </TR>
                            </THead>
                            <TBody>
                                {data.by_campaign.map((row) => (
                                    <TR key={`${row.platform}-${row.campaign_id ?? ''}`}>
                                        <TD className="igr:text-[13px]">{row.campaign_name ?? row.campaign_id}</TD>
                                        <TD className="igr:text-xs"><PlatformName platform={row.platform} /></TD>
                                        <TD className="igr:text-right igr:tabular-nums">{formatNumber(row.impressions ?? 0, workspace.locale)}</TD>
                                        <TD className="igr:text-right igr:tabular-nums">{formatNumber(row.clicks ?? 0, workspace.locale)}</TD>
                                        <TD className="igr:text-right igr:tabular-nums">{formatMoney(row.spend, row.currency, workspace.locale)}</TD>
                                    </TR>
                                ))}
                            </TBody>
                        </Table>
                    </Card>
                </>
            )}
        </div>
    );
}
