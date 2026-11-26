import { ArrowUpRight, CheckCircle2, CircleHelp, Eye, Receipt, Send, ShoppingBag, Trophy, UserPlus } from 'lucide-react';
import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { Badge } from '@/components/ui/badge';
import { ErrorState } from '@/components/ui/misc';
import { SegmentedControl } from '@/components/ui/segmented';
import { StatCard, StatCardSkeleton, StatGrid } from '@/components/ui/stat-card';
import { Table, TBody, TD, TH, THead, TR } from '@/components/ui/table';
import { TrendChart } from '@/features/analytics/components/charts';
import { Notice, Section } from '@/features/analytics/components/Section';
import { makeFormatters } from '@/features/analytics/format';
import { useWorkspace } from '@/hooks/useWorkspace';
import { formatDateTime } from '@/lib/format';
import { useWidgetCaptures, useWidgetStats } from './api';
import { useAudience } from './audience';
import type { WidgetDetail } from './types';

/** Estadísticas del widget (skill dataviz): cifras clave, envíos por día con su tabla, variantes A/B y últimas capturas. */
export function StatsPanel({ widget }: { widget: WidgetDetail }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const { store } = useAudience();
    const [days, setDays] = useState<'7' | '30' | '90'>('30');
    const stats = useWidgetStats(workspace.id, widget.id, Number(days));
    const fmt = useMemo(() => makeFormatters(workspace.locale, stats.data?.currency ?? workspace.currency), [workspace.locale, workspace.currency, stats.data?.currency]);
    const header = (
        <div className="igr:flex igr:flex-wrap igr:items-center igr:justify-between igr:gap-3">
            <p className="igr:min-w-0 igr:flex-1 igr:text-caption igr:text-muted-foreground">{store ? t('onsite.stats.attribution') : t('onsite.stats.attributionServices')}</p>
            <SegmentedControl
                size="sm"
                label={t('onsite.stats.range')}
                value={days}
                onChange={setDays}
                options={(['7', '30', '90'] as const).map((d) => ({ value: d, label: t('onsite.stats.days', { count: Number(d) }) }))}
            />
        </div>
    );

    if (stats.isError) return <ErrorState error={stats.error} onRetry={() => void stats.refetch()} />;
    if (stats.isPending) {
        return (
            <div className="igr:space-y-4">
                {header}
                <StatGrid className="igr:lg:grid-cols-3">
                    {Array.from({ length: store ? 6 : 3 }, (_, i) => (
                        <StatCardSkeleton key={i} />
                    ))}
                </StatGrid>
            </div>
        );
    }
    const s = stats.data;
    const tot = s.totals;
    const ab = s.ab;

    return (
        <div className="igr:space-y-4">
            {header}
            <StatGrid className="igr:lg:grid-cols-3">
                <StatCard label={t('onsite.stats.views')} value={fmt.number(tot.views)} hint={t('onsite.stats.visitors', { value: fmt.number(tot.visitors) })} icon={Eye} tone="sky" />
                <StatCard label={t('onsite.stats.submissions')} value={fmt.number(tot.submissions)} hint={t('onsite.stats.newContacts', { value: fmt.number(tot.new_contacts) })} icon={Send} tone="emerald" />
                <StatCard label={t('onsite.stats.submitRate')} value={fmt.percent(tot.submit_rate, 1)} help={t('onsite.stats.submitRateHelp')} icon={ArrowUpRight} tone="violet" />
                {store ? (
                    <>
                        <StatCard label={t('onsite.stats.orders')} value={fmt.number(tot.orders)} hint={t('onsite.stats.buyers', { value: fmt.number(tot.buyers) })} icon={Receipt} tone="indigo" />
                        <StatCard label={t('onsite.stats.conversion')} value={fmt.percent(tot.conversion_rate, 1)} help={t('onsite.stats.conversionHelp')} icon={UserPlus} tone="rose" />
                        <StatCard label={t('onsite.stats.revenue')} value={fmt.money(Number(tot.revenue))} help={t('onsite.stats.revenueHelp')} icon={ShoppingBag} tone="amber" />
                    </>
                ) : null}
            </StatGrid>

            <Section
                title={t('onsite.stats.daily')}
                description={t('onsite.stats.dailyHelp')}
                chart={<TrendChart data={s.daily} metric="submissions" label={t('onsite.stats.submissions')} unit="count" granularity="day" fmt={fmt} height={200} />}
                table={
                    <Table>
                        <THead>
                            <TR>
                                <TH>{t('onsite.stats.day')}</TH>
                                <TH className="igr:text-right">{t('onsite.stats.views')}</TH>
                                <TH className="igr:text-right">{t('onsite.stats.submissions')}</TH>
                            </TR>
                        </THead>
                        <TBody>
                            {s.daily.map((d) => (
                                <TR key={d.date}>
                                    <TD>{fmt.date(d.date)}</TD>
                                    <TD className="igr:text-right igr:tabular-nums">{fmt.number(d.views)}</TD>
                                    <TD className="igr:text-right igr:tabular-nums">{fmt.number(d.submissions)}</TD>
                                </TR>
                            ))}
                        </TBody>
                    </Table>
                }
            />

            <Section title={t('onsite.stats.variants')} description={s.variants.length > 1 ? t('onsite.stats.variantsHelp') : undefined} flush>
                <Table>
                    <THead>
                        <TR>
                            <TH>{t('onsite.stats.variant')}</TH>
                            <TH className="igr:text-right">{t('onsite.stats.views')}</TH>
                            <TH className="igr:text-right">{t('onsite.stats.submissions')}</TH>
                            <TH className="igr:text-right">{t('onsite.stats.submitRate')}</TH>
                            <TH className="igr:text-right">{t('onsite.stats.newShort')}</TH>
                            {store ? <TH className="igr:text-right">{t('onsite.stats.orders')}</TH> : null}
                            {store ? <TH className="igr:text-right">{t('onsite.stats.revenue')}</TH> : null}
                        </TR>
                    </THead>
                    <TBody>
                        {s.variants.map((v) => (
                            <TR key={v.key}>
                                <TD className="igr:font-medium igr:whitespace-nowrap">
                                    {t('onsite.editor.variants.name', { key: v.key.toUpperCase() })}
                                    {s.winner?.key === v.key ? (
                                        <Badge variant="success" className="igr:ml-2">
                                            <Trophy className="igr:size-3" aria-hidden />
                                            {t('onsite.stats.winner')}
                                        </Badge>
                                    ) : null}
                                </TD>
                                <TD className="igr:text-right igr:tabular-nums">{fmt.number(v.views)}</TD>
                                <TD className="igr:text-right igr:tabular-nums">{fmt.number(v.submissions)}</TD>
                                <TD className="igr:text-right igr:tabular-nums">{fmt.percent(v.submit_rate, 2)}</TD>
                                <TD className="igr:text-right igr:tabular-nums">{fmt.number(v.new_contacts)}</TD>
                                {store ? <TD className="igr:text-right igr:tabular-nums">{fmt.number(v.orders)}</TD> : null}
                                {store ? <TD className="igr:text-right igr:tabular-nums">{fmt.money(v.revenue)}</TD> : null}
                            </TR>
                        ))}
                    </TBody>
                </Table>
                {ab ? (
                    <div className="igr:border-t igr:p-3">
                        {ab.significant && ab.leader ? (
                            <Notice icon={CheckCircle2}>
                                {t('onsite.stats.ab.significant', {
                                    leader: ab.leader.toUpperCase(),
                                    other: (ab.runner_up ?? '').toUpperCase(),
                                    lift: fmt.percent(ab.lift ?? 0, 0),
                                    confidence: fmt.percent(ab.confidence ?? 0, 0),
                                })}
                            </Notice>
                        ) : (
                            <Notice icon={CircleHelp}>{ab.enough_data ? t('onsite.stats.ab.notYet', { confidence: fmt.percent(ab.confidence ?? 0, 0) }) : t('onsite.stats.ab.needData')}</Notice>
                        )}
                    </div>
                ) : null}
            </Section>

            <Captures widget={widget} />
        </div>
    );
}

function Captures({ widget }: { widget: WidgetDetail }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const captures = useWidgetCaptures(workspace.id, widget.id);
    if (captures.isPending || captures.isError) return null;
    return (
        <Section title={t('onsite.stats.captures')} description={t('onsite.stats.capturesHelp')} flush>
            {captures.data.length === 0 ? (
                <p className="igr:px-6 igr:pb-6 igr:text-[13px] igr:text-muted-foreground">{t('onsite.stats.noCaptures')}</p>
            ) : (
                <Table>
                    <THead>
                        <TR>
                            <TH>{t('onsite.stats.contact')}</TH>
                            <TH>{t('onsite.stats.variant')}</TH>
                            <TH>{t('onsite.stats.result')}</TH>
                            <TH>{t('onsite.stats.when')}</TH>
                        </TR>
                    </THead>
                    <TBody>
                        {captures.data.map((c, i) => (
                            <TR key={i}>
                                <TD>
                                    {c.contact ? (
                                        <Link to={`/w/${workspace.id}/contactos/${c.contact.id}`} className="igr:hover:underline">
                                            {c.contact.name ?? c.contact.email ?? t('onsite.stats.unnamed')}
                                        </Link>
                                    ) : (
                                        <span className="igr:text-muted-foreground">{t('onsite.stats.hidden')}</span>
                                    )}
                                    {c.was_known ? (
                                        <Badge variant="outline" className="igr:ml-2">
                                            {t('onsite.stats.known')}
                                        </Badge>
                                    ) : null}
                                </TD>
                                <TD>{(c.variant ?? 'a').toUpperCase()}</TD>
                                <TD className="igr:font-mono igr:text-xs">{[c.coupon, c.prize].filter(Boolean).join(' · ') || '—'}</TD>
                                <TD className="igr:whitespace-nowrap igr:text-muted-foreground">{formatDateTime(c.captured_at, workspace.locale, workspace.timezone)}</TD>
                            </TR>
                        ))}
                    </TBody>
                </Table>
            )}
        </Section>
    );
}
