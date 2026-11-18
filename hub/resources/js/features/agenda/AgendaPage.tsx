import { CalendarDays, ExternalLink } from 'lucide-react';
import { useEffect } from 'react';
import { useTranslation } from 'react-i18next';
import { useSearchParams } from 'react-router';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { ErrorState, PageHeader } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
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
                <div className="igr:grid igr:gap-3 igr:md:grid-cols-4">
                    {[0, 1, 2, 3].map((i) => (
                        <Skeleton key={i} className="igr:h-20" />
                    ))}
                </div>
                <Skeleton className="igr:mt-4 igr:h-96" />
            </>
        );
    }
    const data = bookings.data;
    const m = metrics.data;
    const kpis = [
        { key: 'upcoming', value: m?.upcoming },
        { key: 'booked', value: m?.booked },
        { key: 'completed', value: m?.completed },
        { key: 'noShowRate', value: m ? (m.no_show_rate === null ? '—' : `${Math.round(m.no_show_rate * 100)} %`) : undefined },
    ];

    return (
        <>
            {header}
            {data.services.length === 0 ? (
                <Card className="igr:mb-4">
                    <CardContent className="igr:flex igr:items-center igr:gap-3 igr:p-4">
                        <CalendarDays className="igr:size-5 igr:text-muted-foreground" />
                        <div className="igr:flex-1 igr:text-[13px]">
                            <p className="igr:font-medium">{t('agenda.setup.title')}</p>
                            <p className="igr:text-muted-foreground">{t('agenda.setup.body')}</p>
                        </div>
                        {data.can_manage ? (
                            <Button size="sm" onClick={() => setTab('servicios')}>
                                {t('agenda.setup.cta')}
                            </Button>
                        ) : null}
                    </CardContent>
                </Card>
            ) : null}
            <div className="igr:mb-4 igr:grid igr:grid-cols-2 igr:gap-3 igr:md:grid-cols-4">
                {kpis.map((k) => (
                    <Card key={k.key}>
                        <CardContent className="igr:p-4">
                            <p className="igr:text-xs igr:text-muted-foreground">{t(`agenda.kpi.${k.key}`, { days: m?.days ?? 30 })}</p>
                            <p className="igr:mt-1 igr:text-xl igr:font-semibold igr:tabular-nums">{k.value ?? '…'}</p>
                        </CardContent>
                    </Card>
                ))}
            </div>
            <Tabs value={tab} onValueChange={setTab}>
                <TabsList className="igr:overflow-x-auto">
                    {TABS.map((key) => (
                        <TabsTrigger key={key} value={key} className="igr:shrink-0">
                            {t(`agenda.tabs.${key}`)}
                        </TabsTrigger>
                    ))}
                </TabsList>
                <TabsContent value="calendario">
                    <CalendarTab data={data} />
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
