import { CalendarHeart, Megaphone } from 'lucide-react';
import { useMemo } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { Button } from '@/components/ui/button';
import { HeroCard } from '@/components/ui/hero-card';
import { EmptyState, ErrorState, PageHeader } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { useCan, useWorkspace } from '@/hooks/useWorkspace';
import { useCommercialCalendar } from './api';
import { MomentCard, momentDate, todayIn, useCreateMomentCampaign } from './MomentCard';
import type { CalendarMoment } from './types';

/** Los momentos agrupados por el mes en que ocurren («diciembre de 2026»). */
export function groupMoments(moments: CalendarMoment[]): { month: string; items: CalendarMoment[] }[] {
    const out: { month: string; items: CalendarMoment[] }[] = [];
    for (const m of moments) {
        const month = m.date.slice(0, 7);
        const last = out[out.length - 1];
        if (last && last.month === month) {
            last.items.push(m);
        } else {
            out.push({ month, items: [m] });
        }
    }
    return out;
}

/**
 * Calendario comercial (modulos.md M10, rediseño v2 §11.5): los próximos momentos del país del negocio que le sirven a
 * su tipo de negocio y vertical, cada uno con la campaña sugerida lista para crear en un clic.
 */
export function CalendarPage() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const canManage = useCan('manageMarketing');
    const calendar = useCommercialCalendar(workspace.id, 365);
    const { run, pending } = useCreateMomentCampaign();
    const months = useMemo(() => groupMoments(calendar.data?.data ?? []), [calendar.data]);
    const next = calendar.data?.data[0] ?? null;
    const monthName = (ym: string) => new Intl.DateTimeFormat(workspace.locale, { month: 'long', year: 'numeric', timeZone: 'UTC' }).format(new Date(`${ym}-15T12:00:00Z`));
    const country = calendar.data?.country ?? workspace.country;

    return (
        <>
            <PageHeader
                title={t('campaigns.moments.title')}
                description={t('campaigns.moments.description', { country: t(`campaigns.countries.${country}`, { defaultValue: country }) })}
                actions={
                    <Button asChild variant="outline">
                        <Link to={`/w/${workspace.id}/campanas`}>
                            <Megaphone />
                            {t('campaigns.moments.toCampaigns')}
                        </Link>
                    </Button>
                }
            />
            {calendar.isError ? (
                <ErrorState error={calendar.error} onRetry={() => void calendar.refetch()} />
            ) : calendar.isPending ? (
                <div className="igr:space-y-6" aria-busy="true">
                    <Skeleton className="igr:h-44 igr:rounded-2xl" />
                    <div className="igr:grid igr:gap-3 igr:md:grid-cols-2 igr:xl:grid-cols-3">
                        {Array.from({ length: 6 }, (_, i) => (
                            <Skeleton key={i} className="igr:h-60 igr:rounded-xl" />
                        ))}
                    </div>
                </div>
            ) : next === null ? (
                <EmptyState illustration="messages" title={t('campaigns.moments.empty')} description={t('campaigns.moments.emptyHelp')} />
            ) : (
                <div className="igr:space-y-8">
                    <HeroCard
                        icon={CalendarHeart}
                        eyebrow={t('campaigns.moments.next')}
                        title={next.name}
                        description={
                            next.days_left <= 0
                                ? t('campaigns.moments.heroNow', { date: momentDate(next.send_on, workspace.locale, true) })
                                : next.send_on < todayIn(workspace.timezone)
                                  ? t('campaigns.moments.heroLate', { count: next.days_left })
                                  : t('campaigns.moments.hero', { count: next.days_left, date: momentDate(next.send_on, workspace.locale, true) })
                        }
                        illustration="messages"
                        actions={
                            next.campaign_id ? (
                                <Button asChild>
                                    <Link to={`/w/${workspace.id}/campanas/${next.campaign_id}`}>{t('campaigns.moments.open')}</Link>
                                </Button>
                            ) : canManage ? (
                                <Button variant="brand" loading={pending} onClick={() => run(next)}>
                                    {t('campaigns.moments.createHero')}
                                </Button>
                            ) : null
                        }
                    >
                        {next.ideas[0] ? <p className="igr:text-support igr:text-muted-foreground">{t('campaigns.moments.idea', { idea: next.ideas[0] })}</p> : null}
                    </HeroCard>
                    {months.map(({ month, items }) => (
                        <section key={month} aria-labelledby={`m-${month}`} className="igr:space-y-3">
                            <h2 id={`m-${month}`} className="igr:text-section igr:font-semibold igr:first-letter:uppercase">
                                {monthName(month)}
                            </h2>
                            <div className="igr:grid igr:gap-3 igr:md:grid-cols-2 igr:xl:grid-cols-3">
                                {items.map((m) => (
                                    <MomentCard key={`${m.key}-${m.year}`} moment={m} />
                                ))}
                            </div>
                        </section>
                    ))}
                </div>
            )}
        </>
    );
}
