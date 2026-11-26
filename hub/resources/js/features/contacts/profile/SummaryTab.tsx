import { lazy, Suspense, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { DescriptionList } from '@/components/ui/misc';
import { useWorkspace } from '@/hooks/useWorkspace';
import { formatDate, formatMoney, formatNumber, formatPercent, formatRelative } from '@/lib/format';
import { answerLabel, useHdyhauSurvey } from '@/features/survey/api';
import { useContactAbilities } from '../abilities';
import type { ContactProfile, ContactServices, Touch } from '../api';
import { touchDetail } from '../labels';
import { Skeleton } from '@/components/ui/skeleton';
import { StatCard, StatCardSkeleton, StatGrid } from '@/components/ui/stat-card';

/** La gráfica mensual (Recharts) llega en su propio chunk; mientras, un esqueleto de su alto (docs/rendimiento.md §7.1, 3). */
const MonthlyChart = lazy(async () => ({ default: (await import('./MonthlyChart')).MonthlyChart }));

export function SummaryTab({ profile, servicesOnly = false, services }: { profile: ContactProfile; servicesOnly?: boolean; services?: ContactServices | undefined }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const can = useContactAbilities();
    const survey = useHdyhauSurvey(workspace.id, can.viewDashboards && profile.hdyhau !== null);
    const s = profile.stats;
    const locale = workspace.locale;
    const avgDays = s.avg_days_between_orders === null ? null : Number(s.avg_days_between_orders);
    const couponRatio = s.coupon_orders_ratio === null ? null : Number(s.coupon_orders_ratio);
    const origin = (
        <Card>
            <CardHeader>
                <CardTitle>{t('contacts.profile.origin.title')}</CardTitle>
            </CardHeader>
            <CardContent>
                <DescriptionList
                    items={[
                        { label: t('contacts.profile.origin.first'), value: <TouchValue touch={profile.first_source} /> },
                        ...(servicesOnly ? [] : [{ label: t('contacts.profile.origin.firstOrder'), value: <TouchValue touch={profile.first_order_source} /> }]),
                        {
                            label: t('contacts.profile.origin.hdyhau'),
                            value: answerLabel(survey.data, profile.hdyhau) ?? <span className="igr:text-muted-foreground">{t('contacts.profile.origin.noAnswer')}</span>,
                        },
                        { label: t('contacts.profile.origin.firstSeen'), value: formatDate(profile.first_seen_at, locale, workspace.timezone) },
                        { label: t('contacts.profile.origin.lastSeen'), value: profile.last_seen_at ? formatRelative(profile.last_seen_at, locale) : '—' },
                        ...(profile.merged_count > 0
                            ? [{ label: t('contacts.profile.origin.merged'), value: t('contacts.profile.origin.mergedCount', { count: profile.merged_count }) }]
                            : []),
                    ]}
                />
            </CardContent>
        </Card>
    );

    if (servicesOnly) {
        const totals = services?.totals;
        const money = (v: string | null | undefined) => formatMoney(v ?? '0', services?.currency ?? workspace.currency, locale);
        return (
            <div className="igr:space-y-4">
                <StatGrid className="igr:lg:grid-cols-2 igr:xl:grid-cols-4">
                    {totals === undefined ? (
                        [0, 1, 2, 3].map((i) => <StatCardSkeleton key={i} />)
                    ) : (
                        <>
                            <StatCard label={t('contacts.services.kpi.won')} value={money(totals.won_value)} help={t('contacts.services.kpi.wonHelp')} />
                            <StatCard label={t('contacts.services.kpi.attended')} value={formatNumber(totals.appointments_completed, locale)} />
                            <StatCard label={t('contacts.services.kpi.noShow')} value={formatNumber(totals.appointments_no_show, locale)} better="down" />
                            <StatCard label={t('contacts.services.kpi.quotes')} value={formatNumber(totals.quotes, locale)} />
                        </>
                    )}
                </StatGrid>
                {origin}
            </div>
        );
    }

    return (
        <div className="igr:space-y-4">
            <dl className="igr:grid igr:grid-cols-2 igr:gap-px igr:overflow-hidden igr:rounded-lg igr:border igr:bg-border igr:sm:grid-cols-4">
                <Stat label={t('contacts.profile.stats.orders')}>{formatNumber(s.orders_count, locale)}</Stat>
                <Stat label={t('contacts.profile.stats.revenue')}>{formatMoney(s.revenue_total_ws, profile.currency, locale)}</Stat>
                <Stat label={t('contacts.profile.stats.aov')}>{s.orders_count > 0 ? formatMoney(s.aov_ws, profile.currency, locale) : '—'}</Stat>
                <Stat label={t('contacts.profile.stats.avgDays')}>
                    {avgDays !== null && Number.isFinite(avgDays) ? t('contacts.profile.stats.days', { count: Math.round(avgDays) }) : '—'}
                </Stat>
                <Stat label={t('contacts.profile.stats.firstOrder')}>{formatDate(s.first_order_at, locale, workspace.timezone)}</Stat>
                <Stat label={t('contacts.profile.stats.lastOrder')} hint={s.last_order_at ? formatRelative(s.last_order_at, locale) : undefined}>
                    {formatDate(s.last_order_at, locale, workspace.timezone)}
                </Stat>
                <Stat label={t('contacts.profile.stats.refunds')}>{formatNumber(s.refunds_count, locale)}</Stat>
                <Stat label={t('contacts.profile.stats.coupons')}>{couponRatio !== null && Number.isFinite(couponRatio) ? formatPercent(couponRatio, locale) : '—'}</Stat>
            </dl>

            <Suspense fallback={<Skeleton className="igr:h-[290px] igr:rounded-xl" />}>
                <MonthlyChart monthly={profile.monthly} currency={profile.currency} locale={locale} />
            </Suspense>

            {origin}
        </div>
    );
}

function Stat({ label, hint, children }: { label: string; hint?: string | undefined; children: ReactNode }) {
    return (
        <div className="igr:bg-card igr:px-3 igr:py-2.5">
            <dt className="igr:text-xs igr:text-muted-foreground">{label}</dt>
            <dd className="igr:mt-0.5 igr:text-sm igr:font-semibold igr:tabular-nums">
                {children}
                {hint && <span className="igr:ml-1.5 igr:text-xs igr:font-normal igr:text-muted-foreground">{hint}</span>}
            </dd>
        </div>
    );
}

function TouchValue({ touch }: { touch: Touch | null }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    if (!touch?.channel) {
        return <span className="igr:text-muted-foreground">{t('contacts.profile.origin.unknown')}</span>;
    }
    const detail = touchDetail(touch);
    return (
        <span>
            {t(`contacts.channels.${touch.channel}`, { defaultValue: touch.channel })}
            {detail && <span className="igr:text-muted-foreground"> · {detail}</span>}
            {touch.at && <span className="igr:text-muted-foreground"> · {formatDate(touch.at, workspace.locale, workspace.timezone)}</span>}
        </span>
    );
}
