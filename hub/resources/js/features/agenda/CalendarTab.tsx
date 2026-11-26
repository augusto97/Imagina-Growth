import { Check, ChevronLeft, ChevronRight, MoreHorizontal, Plus, RefreshCw, UserX } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Chip, ChipGroup } from '@/components/ui/chip';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Input, Select, Textarea } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { EmptyState, ErrorState } from '@/components/ui/misc';
import { SegmentedControl } from '@/components/ui/segmented';
import { Skeleton } from '@/components/ui/skeleton';
import { wsPath } from '@/api/queries';
import { PHONE_QUERY, useMedia } from '@/hooks/useMedia';
import { cn } from '@/lib/cn';
import { useWorkspace } from '@/hooks/useWorkspace';
import { api } from '@/lib/api';
import { formatNumber } from '@/lib/format';
import { toast } from '@/stores/toast';
import { errorMessage } from '@/features/contacts/errors';
import { useAgendaMutations, useAppointments } from './api';
import { actionsFor, addDays, groupByDay, localDate, localTime, statusTone, viewRange } from './model';
import { SlotPicker } from './SlotPicker';
import type { Appointment, AppointmentAction, AppointmentStatus, BookingOverview } from './types';

/** Estados de la cita en el filtro: pendientes (agendadas o reprogramadas sin confirmar), confirmadas, asistió, no asistió y canceladas. */
export const STATUS_FILTERS = ['pending', 'confirmed', 'completed', 'no_show', 'cancelled'] as const;
export type StatusFilter = (typeof STATUS_FILTERS)[number];

export function statusGroup(status: AppointmentStatus): StatusFilter {
    return status === 'booked' || status === 'rescheduled' ? 'pending' : status;
}

const BAR: Record<StatusFilter, string> = {
    pending: 'igr:border-l-warning',
    confirmed: 'igr:border-l-info',
    completed: 'igr:border-l-success',
    no_show: 'igr:border-l-destructive',
    cancelled: 'igr:border-l-muted-foreground/40',
};

/**
 * Calendario de la semana o del día (siempre se pide la semana: el día filtra, y la tira de días del celular muestra
 * cuántas citas hay en cada uno). Las citas llevan su estado en color y en palabras, y las acciones a la mano: confirmar,
 * marcar asistencia y reagendar.
 */
export function CalendarTab({ data, bookFor, onBookClosed }: { data: BookingOverview; bookFor: string | null; onBookClosed: () => void }) {
    const { t, i18n } = useTranslation();
    const { workspace } = useWorkspace();
    const phone = useMedia(PHONE_QUERY);
    const tz = data.timezone || 'America/Bogota';
    const today = localDate(new Date().toISOString(), tz);
    const [view, setView] = useState<'day' | 'week'>(phone ? 'day' : 'week');
    const [date, setDate] = useState(today);
    const [staff, setStaff] = useState('');
    const [status, setStatus] = useState<StatusFilter | 'all'>('all');
    const [booking, setBooking] = useState(false);
    const [moving, setMoving] = useState<Appointment | null>(null);
    const week = useMemo(() => viewRange('week', date, tz), [date, tz]);
    const list = useAppointments(workspace.id, week.from, week.to, staff);
    const { change } = useAgendaMutations(workspace.id);
    const all = list.data?.data ?? [];
    const visible = all.filter((a) => status === 'all' || statusGroup(a.status) === status);
    const grouped = groupByDay(visible, tz);
    const days = view === 'day' ? [date] : week.days;
    const staffName = (id: string | null) => data.staff.find((s) => s.id === id)?.name ?? null;
    const fmtDay = (d: string, opts: Intl.DateTimeFormatOptions) => new Intl.DateTimeFormat(i18n.language, { timeZone: 'UTC', ...opts }).format(new Date(`${d}T12:00:00Z`));
    const title =
        view === 'day'
            ? fmtDay(date, { weekday: 'long', day: 'numeric', month: 'long' })
            : `${fmtDay(week.days[0] ?? date, { day: 'numeric', month: 'short' })} – ${fmtDay(week.days[6] ?? date, { day: 'numeric', month: 'short', year: 'numeric' })}`;
    const counts = Object.fromEntries(STATUS_FILTERS.map((s) => [s, all.filter((a) => statusGroup(a.status) === s).length])) as Record<StatusFilter, number>;
    const showBooking = booking || bookFor !== null;

    const act = (a: Appointment, action: AppointmentAction) => {
        if (action === 'reschedule') {
            setMoving(a);
            return;
        }
        change.mutate({ id: a.id, action }, { onSuccess: () => toast.success(t(`agenda.calendar.done.${action}`)), onError: (e) => toast.error(errorMessage(e, t)) });
    };

    return (
        <div className="igr:space-y-4">
            <div className="igr:flex igr:flex-wrap igr:items-center igr:gap-2">
                <div className="igr:flex igr:items-center igr:gap-1">
                    <Button variant="outline" size="icon" aria-label={t('agenda.calendar.prev')} onClick={() => setDate(addDays(date, view === 'day' ? -1 : -7))}>
                        <ChevronLeft />
                    </Button>
                    <Button variant="outline" onClick={() => setDate(today)}>
                        {t('agenda.calendar.today')}
                    </Button>
                    <Button variant="outline" size="icon" aria-label={t('agenda.calendar.next')} onClick={() => setDate(addDays(date, view === 'day' ? 1 : 7))}>
                        <ChevronRight />
                    </Button>
                </div>
                <h2 className="igr:min-w-0 igr:text-body igr:font-semibold igr:first-letter:uppercase" aria-live="polite">
                    {title}
                </h2>
                <div className="igr:flex igr:w-full igr:flex-wrap igr:items-center igr:gap-2 igr:sm:ml-auto igr:sm:w-auto">
                    <SegmentedControl
                        label={t('agenda.calendar.view')}
                        value={view}
                        onChange={setView}
                        options={[
                            { value: 'day', label: t('agenda.calendar.day') },
                            { value: 'week', label: t('agenda.calendar.week') },
                        ]}
                    />
                    {data.staff.length > 1 ? (
                        <Select value={staff} onChange={(e) => setStaff(e.target.value)} aria-label={t('agenda.calendar.staff')} className="igr:w-auto igr:min-w-0 igr:flex-1 igr:sm:w-44 igr:sm:flex-none">
                            <option value="">{t('agenda.calendar.allStaff')}</option>
                            {data.staff.map((s) => (
                                <option key={s.id} value={s.id}>
                                    {s.name}
                                </option>
                            ))}
                        </Select>
                    ) : null}
                    <Button onClick={() => setBooking(true)} disabled={data.services.length === 0} className="igr:max-sm:w-full">
                        <Plus />
                        {t('agenda.calendar.new')}
                    </Button>
                </div>
            </div>

            {view === 'day' ? (
                <div className="igr:grid igr:grid-cols-7 igr:gap-1" role="group" aria-label={t('agenda.calendar.weekDays')}>
                    {week.days.map((d) => {
                        const n = (groupByDay(all, tz)[d] ?? []).length;
                        return (
                            <button
                                key={d}
                                type="button"
                                aria-pressed={d === date}
                                aria-label={`${fmtDay(d, { weekday: 'long', day: 'numeric' })}: ${n > 0 ? t('agenda.calendar.count', { count: n }) : t('agenda.calendar.free')}`}
                                onClick={() => setDate(d)}
                                className={cn(
                                    'igr:flex igr:flex-col igr:items-center igr:rounded-lg igr:border igr:py-1.5 igr:text-caption igr:transition-colors igr:focus-visible:outline-none igr:focus-visible:ring-3 igr:focus-visible:ring-ring/30',
                                    d === date ? 'igr:border-primary/50 igr:bg-brand-soft igr:text-brand-soft-foreground' : 'igr:bg-card igr:text-muted-foreground igr:hover:bg-accent',
                                )}
                            >
                                <span className="igr:first-letter:uppercase">{fmtDay(d, { weekday: 'short' }).replace('.', '')}</span>
                                <span className={cn('igr:text-body igr:font-semibold igr:tabular-nums', d === today && d !== date && 'igr:text-primary')}>{fmtDay(d, { day: 'numeric' })}</span>
                                <span className="igr:tabular-nums" aria-hidden>
                                    {n > 0 ? formatNumber(n, workspace.locale) : '·'}
                                </span>
                            </button>
                        );
                    })}
                </div>
            ) : null}

            <ChipGroup label={t('agenda.calendar.filterStatus')} scroll>
                <Chip pressed={status === 'all'} onClick={() => setStatus('all')} count={all.length}>
                    {t('agenda.calendar.allStatuses')}
                </Chip>
                {STATUS_FILTERS.map((s) => (
                    <Chip key={s} pressed={status === s} onClick={() => setStatus(status === s ? 'all' : s)} count={counts[s]}>
                        <span aria-hidden className={cn('igr:size-2 igr:rounded-full', DOT[s])} />
                        {t(`agenda.statusGroup.${s}`)}
                    </Chip>
                ))}
            </ChipGroup>

            {list.isError ? (
                <ErrorState error={list.error} onRetry={() => void list.refetch()} />
            ) : list.isPending ? (
                <Skeleton className="igr:h-80 igr:rounded-xl" />
            ) : view === 'day' ? (
                (grouped[date] ?? []).length === 0 ? (
                    <EmptyState
                        illustration="customers"
                        size="compact"
                        title={t('agenda.calendar.emptyDay')}
                        description={t('agenda.calendar.emptyHelp')}
                        action={
                            data.services.length > 0 ? (
                                <Button variant="outline" onClick={() => setBooking(true)}>
                                    <Plus />
                                    {t('agenda.calendar.new')}
                                </Button>
                            ) : undefined
                        }
                    />
                ) : (
                    <ul className="igr:space-y-2">
                        {(grouped[date] ?? []).map((a) => (
                            <li key={a.id}>
                                <AppointmentCard appointment={a} tz={tz} staff={staffName(a.staff_id)} onAction={(action) => act(a, action)} wide />
                            </li>
                        ))}
                    </ul>
                )
            ) : (
                <div className="igr:grid igr:gap-2 igr:sm:grid-cols-2 igr:lg:grid-cols-4 igr:xl:grid-cols-7">
                    {days.map((d) => (
                        <Card key={d} className={cn('igr:p-2.5', d === today && 'igr:border-primary/50 igr:ring-1 igr:ring-primary/20')}>
                            <button
                                type="button"
                                className="igr:mb-2 igr:flex igr:w-full igr:items-baseline igr:justify-between igr:rounded igr:px-0.5 igr:text-left igr:text-caption igr:font-medium igr:text-muted-foreground igr:hover:text-foreground igr:focus-visible:outline-none igr:focus-visible:ring-2 igr:focus-visible:ring-ring/40"
                                onClick={() => {
                                    setView('day');
                                    setDate(d);
                                }}
                                aria-label={t('agenda.calendar.openDay', { day: fmtDay(d, { weekday: 'long', day: 'numeric', month: 'long' }) })}
                            >
                                <span className="igr:first-letter:uppercase">{fmtDay(d, { weekday: 'short', day: 'numeric', month: 'short' })}</span>
                                {(grouped[d] ?? []).length > 0 ? <span className="igr:tabular-nums">{(grouped[d] ?? []).length}</span> : null}
                            </button>
                            {(grouped[d] ?? []).length === 0 ? (
                                <p className="igr:px-0.5 igr:text-caption igr:text-muted-foreground/80">{t('agenda.calendar.free')}</p>
                            ) : (
                                <ul className="igr:space-y-2">
                                    {(grouped[d] ?? []).map((a) => (
                                        <li key={a.id}>
                                            <AppointmentCard appointment={a} tz={tz} staff={staffName(a.staff_id)} onAction={(action) => act(a, action)} />
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </Card>
                    ))}
                </div>
            )}
            {showBooking ? (
                <BookDialog
                    data={data}
                    tz={tz}
                    today={today}
                    initialContactId={bookFor}
                    onClose={() => {
                        setBooking(false);
                        onBookClosed();
                    }}
                />
            ) : null}
            {moving ? <RescheduleDialog data={data} tz={tz} today={today} appointment={moving} onClose={() => setMoving(null)} /> : null}
        </div>
    );
}

const DOT: Record<StatusFilter, string> = {
    pending: 'igr:bg-warning',
    confirmed: 'igr:bg-info',
    completed: 'igr:bg-success',
    no_show: 'igr:bg-destructive',
    cancelled: 'igr:bg-muted-foreground/50',
};

/** Una cita: hora, servicio, persona y quién atiende, su estado en palabras y las acciones que aplican a la mano. */
function AppointmentCard({ appointment: a, tz, staff, onAction, wide = false }: { appointment: Appointment; tz: string; staff: string | null; onAction: (action: AppointmentAction) => void; wide?: boolean }) {
    const { t, i18n } = useTranslation();
    const { workspace } = useWorkspace();
    const actions = actionsFor(a);
    // A la mano lo de todos los días (confirmar, asistió, no asistió); reagendar y cancelar en el menú.
    const isQuick = (x: AppointmentAction) => x === 'confirm' || x === 'complete' || x === 'no-show';
    const quick = actions.filter(isQuick);
    const rest = actions.filter((x) => !isQuick(x));
    const group = statusGroup(a.status);
    return (
        <div className={cn('igr:rounded-lg igr:border igr:border-l-4 igr:bg-card igr:p-2.5 igr:text-[13px]', BAR[group], a.status === 'cancelled' && 'igr:opacity-70')}>
            <div className={cn('igr:flex igr:gap-2', wide ? 'igr:items-center' : 'igr:items-start')}>
                <div className={cn('igr:min-w-0 igr:flex-1', wide && 'igr:sm:flex igr:sm:items-center igr:sm:gap-4')}>
                    <p className="igr:font-semibold igr:whitespace-nowrap igr:tabular-nums">
                        {localTime(a.starts_at, tz, i18n.language)}
                        {wide ? <span className="igr:font-normal igr:text-muted-foreground"> – {localTime(a.ends_at, tz, i18n.language)}</span> : null}
                    </p>
                    <div className="igr:min-w-0">
                        <p className="igr:truncate">{a.service?.name ?? '—'}</p>
                        {a.contact ? (
                            <Link to={`/w/${workspace.id}/contactos/${a.contact.id}`} className="igr:block igr:truncate igr:font-medium igr:text-primary igr:hover:underline">
                                {a.contact.name ?? a.contact.phone ?? a.contact.email}
                            </Link>
                        ) : null}
                        {staff ? <p className="igr:truncate igr:text-caption igr:text-muted-foreground">{t('agenda.calendar.with', { name: staff })}</p> : null}
                    </div>
                </div>
                {rest.length > 0 ? (
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <Button variant="ghost" size="icon" className="igr:-mt-1 igr:-mr-1 igr:size-7 igr:shrink-0" aria-label={t('agenda.calendar.actions')}>
                                <MoreHorizontal />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                            {rest.map((action) => (
                                <DropdownMenuItem key={action} onSelect={() => onAction(action)}>
                                    {t(`agenda.calendar.action.${action}`)}
                                </DropdownMenuItem>
                            ))}
                        </DropdownMenuContent>
                    </DropdownMenu>
                ) : null}
            </div>
            <div className="igr:mt-2 igr:flex igr:flex-wrap igr:items-center igr:gap-1.5">
                <Badge variant={statusTone(a.status)}>{t(`agenda.status.${a.status}`)}</Badge>
                {quick.map((action) => (
                    <Button key={action} size="sm" variant={action === 'no-show' ? 'ghost' : 'outline'} className="igr:h-7 igr:px-2 igr:text-xs" onClick={() => onAction(action)}>
                        {action === 'no-show' ? <UserX /> : <Check />}
                        {t(`agenda.calendar.quick.${action}`)}
                    </Button>
                ))}
            </div>
        </div>
    );
}

interface ContactHit {
    id: string;
    name: string;
    email: string | null;
    phone: string | null;
}

function BookDialog({ data, tz, today, initialContactId, onClose }: { data: BookingOverview; tz: string; today: string; initialContactId: string | null; onClose: () => void }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const { book } = useAgendaMutations(workspace.id);
    const active = data.services.filter((s) => s.active);
    const [service, setService] = useState(active[0]?.id ?? '');
    const [staff, setStaff] = useState('');
    const [start, setStart] = useState('');
    const [chosenStaff, setChosenStaff] = useState<string | null>(null);
    const [q, setQ] = useState('');
    const [hits, setHits] = useState<ContactHit[]>([]);
    const [contact, setContact] = useState<ContactHit | null>(null);
    const [notes, setNotes] = useState('');
    // Desde el perfil o la ficha del lead («Agendar cita»): la persona ya viene elegida.
    useEffect(() => {
        if (initialContactId === null) return;
        let alive = true;
        api.get<{ id: string; name: string; email: string | null; phone: string | null }>(`${wsPath(workspace.id)}/contacts/${encodeURIComponent(initialContactId)}`)
            .then((c) => {
                if (alive) setContact({ id: c.id, name: c.name, email: c.email, phone: c.phone });
            })
            .catch(() => undefined);
        return () => {
            alive = false;
        };
    }, [initialContactId, workspace.id]);
    const search = async (value: string) => {
        setQ(value);
        if (value.trim().length < 2) {
            setHits([]);
            return;
        }
        try {
            const res = await api.get<{ data: ContactHit[] }>(`${wsPath(workspace.id)}/contacts?q=${encodeURIComponent(value.trim())}&per_page=6`);
            setHits(res.data);
        } catch {
            setHits([]);
        }
    };
    const submit = () => {
        if (contact === null) return;
        book.mutate(
            {
                contact_id: contact.id,
                service_id: service,
                start,
                staff_id: chosenStaff,
                notes: notes.trim() === '' ? undefined : notes.trim(),
            },
            {
                onSuccess: () => {
                    toast.success(t('agenda.book.done'));
                    onClose();
                },
                onError: (e) => toast.error(errorMessage(e, t)),
            },
        );
    };

    return (
        <Dialog open onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="igr:top-[6dvh] igr:max-h-[88dvh] igr:max-w-lg igr:overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>{t('agenda.book.title')}</DialogTitle>
                    <DialogDescription>{t('agenda.book.description')}</DialogDescription>
                </DialogHeader>
                <div className="igr:space-y-3">
                    <div>
                        <Label htmlFor="book-contact">{t('agenda.book.contact')}</Label>
                        {contact ? (
                            <div className="igr:mt-1 igr:flex igr:items-center igr:justify-between igr:rounded-md igr:border igr:px-3 igr:py-2 igr:text-[13px]">
                                <span>{contact.name || contact.phone || contact.email}</span>
                                <Button variant="link" size="sm" onClick={() => setContact(null)}>
                                    {t('agenda.book.change')}
                                </Button>
                            </div>
                        ) : (
                            <>
                                <Input
                                    id="book-contact"
                                    className="igr:mt-1"
                                    value={q}
                                    placeholder={t('agenda.book.searchPlaceholder')}
                                    onChange={(e) => void search(e.target.value)}
                                    autoComplete="off"
                                />
                                {hits.length > 0 ? (
                                    <ul className="igr:mt-1 igr:max-h-40 igr:overflow-y-auto igr:rounded-md igr:border">
                                        {hits.map((h) => (
                                            <li key={h.id}>
                                                <button type="button" className="igr:w-full igr:px-3 igr:py-1.5 igr:text-left igr:text-[13px] igr:hover:bg-accent" onClick={() => setContact(h)}>
                                                    {h.name || '—'} <span className="igr:text-muted-foreground">{h.phone ?? h.email}</span>
                                                </button>
                                            </li>
                                        ))}
                                    </ul>
                                ) : null}
                            </>
                        )}
                    </div>
                    <div className="igr:grid igr:gap-3 igr:sm:grid-cols-2">
                        <div>
                            <Label htmlFor="book-service">{t('agenda.book.service')}</Label>
                            <Select
                                id="book-service"
                                className="igr:mt-1"
                                value={service}
                                onChange={(e) => {
                                    setService(e.target.value);
                                    setStart('');
                                }}
                            >
                                {active.map((s) => (
                                    <option key={s.id} value={s.id}>
                                        {s.name}
                                    </option>
                                ))}
                            </Select>
                        </div>
                        {data.staff.length > 1 ? (
                            <div>
                                <Label htmlFor="book-staff">{t('agenda.book.staff')}</Label>
                                <Select
                                    id="book-staff"
                                    className="igr:mt-1"
                                    value={staff}
                                    onChange={(e) => {
                                        setStaff(e.target.value);
                                        setStart('');
                                    }}
                                >
                                    <option value="">{t('agenda.calendar.anyStaff')}</option>
                                    {data.staff
                                        .filter((s) => s.active)
                                        .map((s) => (
                                            <option key={s.id} value={s.id}>
                                                {s.name}
                                            </option>
                                        ))}
                                </Select>
                            </div>
                        ) : null}
                    </div>
                    <SlotPicker
                        ws={workspace.id}
                        serviceId={service}
                        staffId={staff}
                        tz={tz}
                        today={today}
                        value={start}
                        onChange={(s, st) => {
                            setStart(s);
                            setChosenStaff(st);
                        }}
                    />
                    <div>
                        <Label htmlFor="book-notes">{t('agenda.book.notes')}</Label>
                        <Textarea id="book-notes" className="igr:mt-1" rows={2} value={notes} maxLength={2000} onChange={(e) => setNotes(e.target.value)} />
                    </div>
                </div>
                <DialogFooter>
                    <Button variant="outline" onClick={onClose}>
                        {t('common.cancel')}
                    </Button>
                    <Button onClick={submit} loading={book.isPending} disabled={contact === null || start === ''}>
                        {t('agenda.book.submit')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function RescheduleDialog({ data, tz, today, appointment, onClose }: { data: BookingOverview; tz: string; today: string; appointment: Appointment; onClose: () => void }) {
    const { t, i18n } = useTranslation();
    const { workspace } = useWorkspace();
    const { change } = useAgendaMutations(workspace.id);
    const [start, setStart] = useState('');
    const service = data.services.find((s) => s.id === appointment.service?.id);
    const submit = () =>
        change.mutate(
            { id: appointment.id, action: 'reschedule', start },
            {
                onSuccess: () => {
                    toast.success(t('agenda.calendar.done.reschedule'));
                    onClose();
                },
                onError: (e) => toast.error(errorMessage(e, t)),
            },
        );

    return (
        <Dialog open onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="igr:top-[6dvh] igr:max-h-[88dvh] igr:max-w-lg igr:overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>{t('agenda.reschedule.title')}</DialogTitle>
                    <DialogDescription>
                        {t('agenda.reschedule.current', {
                            service: appointment.service?.name ?? '—',
                            time: `${localDate(appointment.starts_at, tz)} ${localTime(appointment.starts_at, tz, i18n.language)}`,
                        })}
                    </DialogDescription>
                </DialogHeader>
                {service ? (
                    <SlotPicker ws={workspace.id} serviceId={service.id} staffId={appointment.staff_id ?? ''} tz={tz} today={today} value={start} onChange={(s) => setStart(s)} />
                ) : (
                    <p className="igr:text-[13px] igr:text-muted-foreground">{t('agenda.reschedule.noService')}</p>
                )}
                <p className="igr:text-xs igr:text-muted-foreground">
                    <RefreshCw className="igr:mr-1 igr:inline igr:size-3" />
                    {t('agenda.reschedule.notice')}
                </p>
                <DialogFooter>
                    <Button variant="outline" onClick={onClose}>
                        {t('common.cancel')}
                    </Button>
                    <Button onClick={submit} loading={change.isPending} disabled={start === ''}>
                        {t('agenda.reschedule.submit')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
