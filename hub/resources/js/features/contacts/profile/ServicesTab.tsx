import { CalendarDays, Calculator, ChevronRight, Target } from 'lucide-react';
import { useState, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { IconTile } from '@/components/ui/icon-tile';
import { ErrorState } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { useWorkspace } from '@/hooks/useWorkspace';
import { cn } from '@/lib/cn';
import { formatDate, formatDateTime, formatMoney } from '@/lib/format';
import type { ContactAppointmentStatus, ContactServices } from '../api';
import { TEMPERATURE_TONE } from '../list/ContactRows';

export const APPOINTMENT_TONE: Record<ContactAppointmentStatus, 'success' | 'warning' | 'destructive' | 'outline' | 'info'> = {
    booked: 'warning',
    rescheduled: 'warning',
    confirmed: 'info',
    completed: 'success',
    no_show: 'destructive',
    cancelled: 'outline',
};

/**
 * Pestaña «Citas y cotizaciones» del perfil (negocios de servicios): sus procesos (leads) con la etapa en palabras, sus
 * citas (las próximas primero) y sus cotizaciones. Cada proceso abre su ficha en Leads.
 */
export function ServicesTab({ data, error, onRetry }: { data: ContactServices | undefined; error: unknown; onRetry: () => void }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const [now] = useState(() => Date.now());
    if (error) {
        return <ErrorState error={error} onRetry={onRetry} />;
    }
    if (data === undefined) {
        return (
            <div className="igr:space-y-4" aria-busy>
                <Skeleton className="igr:h-40 igr:rounded-xl" />
                <Skeleton className="igr:h-40 igr:rounded-xl" />
            </div>
        );
    }
    const money = (v: string | null) => formatMoney(v, data.currency, workspace.locale);

    return (
        <div className="igr:space-y-4">
            <Section icon={<IconTile icon={Target} tone="rose" size="sm" />} title={t('contacts.services.leads')} empty={data.leads.length === 0 ? t('contacts.services.noLeads') : null}>
                <ul className="igr:divide-y">
                    {data.leads.map((lead) => (
                        <li key={lead.id}>
                            <Link
                                to={`/w/${workspace.id}/leads?lead=${lead.id}`}
                                className="igr:flex igr:items-center igr:gap-3 igr:px-5 igr:py-3 igr:transition-colors igr:hover:bg-muted/50 igr:focus-visible:bg-muted/50 igr:focus-visible:outline-none"
                            >
                                <div className="igr:min-w-0 igr:flex-1">
                                    <div className="igr:flex igr:flex-wrap igr:items-center igr:gap-1.5">
                                        <Badge variant={lead.stage_kind === 'won' ? 'success' : lead.stage_kind === 'lost' ? 'outline' : 'brand'}>{lead.stage_label}</Badge>
                                        {lead.stage_kind === 'open' ? <Badge variant={TEMPERATURE_TONE[lead.temperature]}>{t(`contacts.temperature.${lead.temperature}`)}</Badge> : null}
                                        <span className="igr:truncate igr:font-medium">{lead.service ?? t('contacts.header.noService')}</span>
                                    </div>
                                    <p className="igr:mt-1 igr:text-support igr:text-muted-foreground">
                                        {[
                                            t(`contacts.services.origin.${lead.origin}`, { defaultValue: lead.origin }),
                                            lead.created_at ? t('contacts.services.since', { date: formatDate(lead.created_at, workspace.locale, workspace.timezone) }) : null,
                                            lead.lost_reason ? t('contacts.services.lostReason', { reason: lead.lost_reason }) : null,
                                        ]
                                            .filter(Boolean)
                                            .join(' · ')}
                                    </p>
                                </div>
                                {lead.value ? <span className="igr:shrink-0 igr:font-semibold igr:tabular-nums">{money(lead.value)}</span> : null}
                                <ChevronRight className="igr:size-4 igr:shrink-0 igr:text-muted-foreground" aria-hidden />
                            </Link>
                        </li>
                    ))}
                </ul>
            </Section>

            <Section icon={<IconTile icon={CalendarDays} tone="teal" size="sm" />} title={t('contacts.services.appointments')} empty={data.appointments.length === 0 ? t('contacts.services.noAppointments') : null}>
                <ul className="igr:divide-y">
                    {data.appointments.map((a) => {
                        const past = Date.parse(a.starts_at) < now;
                        return (
                            <li key={a.id} className={cn('igr:flex igr:items-center igr:gap-3 igr:px-5 igr:py-3', past && a.status !== 'completed' && 'igr:text-muted-foreground')}>
                                <div className="igr:min-w-0 igr:flex-1">
                                    <p className="igr:font-medium igr:text-foreground igr:tabular-nums">{formatDateTime(a.starts_at, workspace.locale, workspace.timezone)}</p>
                                    <p className="igr:text-support igr:text-muted-foreground">{[a.service, a.staff ? t('contacts.services.with', { name: a.staff }) : null].filter(Boolean).join(' · ') || '—'}</p>
                                </div>
                                <Badge variant={APPOINTMENT_TONE[a.status]}>{t(`contacts.appointmentStatus.${a.status}`)}</Badge>
                            </li>
                        );
                    })}
                </ul>
            </Section>

            <Section icon={<IconTile icon={Calculator} tone="emerald" size="sm" />} title={t('contacts.services.quotes')} empty={data.quotes.length === 0 ? t('contacts.services.noQuotes') : null}>
                <ul className="igr:divide-y">
                    {data.quotes.map((q) => (
                        <li key={q.id} className="igr:flex igr:items-center igr:gap-3 igr:px-5 igr:py-3">
                            <div className="igr:min-w-0 igr:flex-1">
                                <p className="igr:truncate igr:font-medium">{q.calculator ?? t('contacts.services.quote')}</p>
                                <p className="igr:text-support igr:text-muted-foreground">{formatDate(q.created_at, workspace.locale, workspace.timezone)}</p>
                            </div>
                            <span className="igr:shrink-0 igr:font-semibold igr:tabular-nums">
                                {q.estimate_min && q.estimate_max && q.estimate_min !== q.estimate_max
                                    ? `${formatMoney(q.estimate_min, q.currency, workspace.locale)} – ${formatMoney(q.estimate_max, q.currency, workspace.locale)}`
                                    : formatMoney(q.estimate_max ?? q.estimate_min, q.currency, workspace.locale)}
                            </span>
                        </li>
                    ))}
                </ul>
            </Section>
        </div>
    );
}

function Section({ icon, title, empty, children }: { icon: ReactNode; title: string; empty: string | null; children: ReactNode }) {
    return (
        <Card className="igr:overflow-hidden">
            <CardHeader className="igr:flex-row igr:items-center igr:gap-2.5 igr:pb-3">
                {icon}
                <CardTitle>{title}</CardTitle>
            </CardHeader>
            {empty !== null ? <CardContent className="igr:text-support igr:text-muted-foreground">{empty}</CardContent> : <div className="igr:border-t">{children}</div>}
        </Card>
    );
}
