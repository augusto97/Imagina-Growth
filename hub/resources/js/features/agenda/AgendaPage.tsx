import { CalendarCheck, CalendarClock, CalendarDays, ExternalLink, UserX } from 'lucide-react';
import { useEffect } from 'react';
import { useTranslation } from 'react-i18next';
import { useSearchParams } from 'react-router';
import { Button } from '@/components/ui/button';
import { Callout } from '@/components/ui/callout';
import { ErrorState, PageHeader } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { StatCard, StatCardSkeleton, StatGrid } from '@/components/ui/stat-card';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { PHONE_QUERY, useMedia } from '@/hooks/useMedia';
import { useWorkspace } from '@/hooks/useWorkspace';
import { toast } from '@/stores/toast';
import { useBookingMetrics, useBookings } from './api';
import { CalendarTab } from './CalendarTab';
import { PageTab } from './PageTab';
import { ServicesTab } from './ServicesTab';
import { StaffTab } from './StaffTab';

const TABS = ['calendario', 'servicios', 'personas', 'pagina'] as const;
type Tab = (typeof TABS)[number];

/** Agenda de citas (modulos.md M18). */
export function AgendaPage() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const bookings = useBookings(workspace.id);
    // En el celular las métricas van en dos columnas: sin el ícono, el nombre cabe entero.
    const phone = useMedia(PHONE_QUERY);
    const metrics = useBookingMetrics(workspace.id);
    const [params, setParams] = useSearchParams();
    const raw = params.get('pestana');
    const tab: Tab = (TABS as readonly string[]).includes(raw ?? '') ? (raw as Tab) : 'calendario';
    const setTab = (next: string) => {
        const p = new URLSearchParams(params);
        if (next === 'calendario') p.delete('pestana');
        else p.set('pestana', next);
        p.delete('google');
        setParams(p, { replace: true });
    };

    // Vuelta del consentimiento de Google Calendar (?google=ok|error|cancelado).
    const google = params.get('google');
    useEffect(() => {
        if (google === null) return;
        if (google === 'ok') toast.success(t('agenda.staff.googleConnected'));
        else toast.error(t(google === 'cancelado' ? 'agenda.staff.googleCancelled' : 'agenda.staff.googleError'));
        const p = new URLSearchParams(params);
        p.delete('google');
        setParams(p, { replace: true });
    }, [google, params, setParams, t]);

    const header = (
        <PageHeader
            title={t('agenda.title')}
            description={t('agenda.description')}
            actions={
                bookings.data ? (
                    <Button variant="outline" asChild>
                        <a href={bookings.data.page_url} target="_blank" rel="noreferrer">
                            <ExternalLink />
                            {t('agenda.openPage')}
                        </a>
                    </Button>
                ) : null
            }
        />
    );
    if (bookings.isError) {
        return (
            <>
                {header}
                <ErrorState error={bookings.error} onRetry={() => void bookings.refetch()} />
            </>
        );
    }
    if (bookings.isPending) {
        return (
            <>
                {header}
                <StatGrid>
                    {[0, 1, 2, 3].map((i) => (
                        <StatCardSkeleton key={i} />
                    ))}
                </StatGrid>
                <Skeleton className="igr:mt-6 igr:h-96 igr:rounded-xl" />
            </>
        );
    }
    const data = bookings.data;
    const m = metrics.data;
    const kpis = [
        { key: 'upcoming', icon: CalendarClock, tone: 'teal' as const, value: m?.upcoming },
        { key: 'booked', icon: CalendarDays, tone: 'sky' as const, value: m?.booked },
        { key: 'completed', icon: CalendarCheck, tone: 'emerald' as const, value: m?.completed },
        {
            key: 'noShowRate',
            icon: UserX,
            tone: 'rose' as const,
            value: m ? (m.no_show_rate === null ? '—' : `${Math.round(m.no_show_rate * 100)} %`) : undefined,
        },
    ];
    const bookFor = params.get('nueva');

    return (
        <>
            {header}
            {data.services.length === 0 ? (
                <Callout
                    tone="brand"
                    icon={CalendarDays}
                    title={t('agenda.setup.title')}
                    className="igr:mb-4"
                    action={
                        data.can_manage ? (
                            <Button size="sm" onClick={() => setTab('servicios')}>
                                {t('agenda.setup.cta')}
                            </Button>
                        ) : undefined
                    }
                >
                    {t('agenda.setup.body')}
                </Callout>
            ) : null}
            <StatGrid className="igr:mb-6 igr:grid-cols-2">
                {kpis.map((k) => (
                    <StatCard
                        key={k.key}
                        icon={phone ? undefined : k.icon}
                        tone={k.tone}
                        label={t(`agenda.kpi.${k.key}`, { days: m?.days ?? 30 })}
                        help={t(`agenda.kpiHelp.${k.key}`, { days: m?.days ?? 30 })}
                        loading={m === undefined}
                        value={k.value ?? '—'}
                    />
                ))}
            </StatGrid>
            <Tabs value={tab} onValueChange={setTab}>
                <TabsList>
                    {TABS.map((key) => (
                        <TabsTrigger key={key} value={key}>
                            {t(`agenda.tabs.${key}`)}
                        </TabsTrigger>
                    ))}
                </TabsList>
                <TabsContent value="calendario">
                    <CalendarTab
                        data={data}
                        bookFor={bookFor}
                        onBookClosed={() => {
                            if (bookFor === null) return;
                            const p = new URLSearchParams(params);
                            p.delete('nueva');
                            setParams(p, { replace: true });
                        }}
                    />
                </TabsContent>
                <TabsContent value="servicios">
                    <ServicesTab data={data} />
                </TabsContent>
                <TabsContent value="personas">
                    <StaffTab data={data} />
                </TabsContent>
                <TabsContent value="pagina">
                    <PageTab data={data} />
                </TabsContent>
            </Tabs>
        </>
    );
}
