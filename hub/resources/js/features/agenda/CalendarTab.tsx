import { CalendarCheck, ChevronLeft, ChevronRight, MoreHorizontal, Plus, RefreshCw } from 'lucide-react';
import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Input, Select, Textarea } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { EmptyState, ErrorState } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { wsPath } from '@/api/queries';
import { useWorkspace } from '@/hooks/useWorkspace';
import { api } from '@/lib/api';
import { toast } from '@/stores/toast';
import { errorMessage } from '@/features/contacts/errors';
import { useAgendaMutations, useAppointments } from './api';
import { actionsFor, addDays, groupByDay, localDate, localTime, statusTone, viewRange } from './model';
import { SlotPicker } from './SlotPicker';
import type { Appointment, AppointmentAction, BookingOverview } from './types';

/** Calendario del día o la semana, con cambio de estado de cada cita. */
export function CalendarTab({ data }: { data: BookingOverview }) {
    const { t, i18n } = useTranslation();
    const { workspace } = useWorkspace();
    const tz = data.timezone || 'America/Bogota';
    const today = localDate(new Date().toISOString(), tz);
    const [view, setView] = useState<'day' | 'week'>('week');
    const [date, setDate] = useState(today);
    const [staff, setStaff] = useState('');
    const [booking, setBooking] = useState(false);
    const [moving, setMoving] = useState<Appointment | null>(null);
    const range = useMemo(() => viewRange(view, date, tz), [view, date, tz]);
    const list = useAppointments(workspace.id, range.from, range.to, staff);
    const { change } = useAgendaMutations(workspace.id);
    const grouped = groupByDay(list.data?.data ?? [], tz);
    const staffName = (id: string | null) => data.staff.find((s) => s.id === id)?.name ?? null;
    const fmtDay = (d: string, opts: Intl.DateTimeFormatOptions) =>
        new Intl.DateTimeFormat(i18n.language, {
            timeZone: 'UTC',
            ...opts,
        }).format(new Date(`${d}T12:00:00Z`));
    const title =
        view === 'day'
            ? fmtDay(date, { weekday: 'long', day: 'numeric', month: 'long' })
            : `${fmtDay(range.days[0] ?? date, { day: 'numeric', month: 'short' })} – ${fmtDay(range.days[6] ?? date, { day: 'numeric', month: 'short', year: 'numeric' })}`;

    const act = (a: Appointment, action: AppointmentAction) => {
        if (action === 'reschedule') {
            setMoving(a);
            return;
        }
        change.mutate(
            { id: a.id, action },
            {
                onSuccess: () => toast.success(t(`agenda.calendar.done.${action}`)),
                onError: (e) => toast.error(errorMessage(e, t)),
            },
        );
    };

    return (
        <div className="igr:space-y-4">
            <div className="igr:flex igr:flex-wrap igr:items-center igr:gap-2">
                <div className="igr:flex igr:items-center igr:gap-1">
                    <Button variant="outline" size="icon" aria-label={t('agenda.calendar.prev')} onClick={() => setDate(addDays(date, view === 'day' ? -1 : -7))}>
                        <ChevronLeft />
                    </Button>
                    <Button variant="outline" size="sm" onClick={() => setDate(today)}>
                        {t('agenda.calendar.today')}
                    </Button>
                    <Button variant="outline" size="icon" aria-label={t('agenda.calendar.next')} onClick={() => setDate(addDays(date, view === 'day' ? 1 : 7))}>
                        <ChevronRight />
                    </Button>
                </div>
                <h2 className="igr:text-sm igr:font-semibold igr:capitalize">{title}</h2>
                <div className="igr:ml-auto igr:flex igr:flex-wrap igr:items-center igr:gap-2">
                    {data.staff.length > 1 ? (
                        <Select value={staff} onChange={(e) => setStaff(e.target.value)} aria-label={t('agenda.calendar.staff')} className="igr:w-44">
                            <option value="">{t('agenda.calendar.allStaff')}</option>
                            {data.staff.map((s) => (
                                <option key={s.id} value={s.id}>
                                    {s.name}
                                </option>
                            ))}
                        </Select>
                    ) : null}
                    <div className="igr:inline-flex igr:rounded-md igr:border igr:p-0.5" role="group">
                        {(['day', 'week'] as const).map((v) => (
                            <Button key={v} size="sm" variant={view === v ? 'secondary' : 'ghost'} onClick={() => setView(v)} aria-pressed={view === v}>
                                {t(`agenda.calendar.${v}`)}
                            </Button>
                        ))}
                    </div>
                    <Button size="sm" onClick={() => setBooking(true)} disabled={data.services.length === 0}>
                        <Plus />
                        {t('agenda.calendar.new')}
                    </Button>
                </div>
            </div>

            {list.isError ? (
                <ErrorState error={list.error} onRetry={() => void list.refetch()} />
            ) : list.isPending ? (
                <Skeleton className="igr:h-80" />
            ) : list.data.data.length === 0 && view === 'day' ? (
                <EmptyState icon={CalendarCheck} title={t('agenda.calendar.emptyDay')} description={t('agenda.calendar.emptyHelp')} />
            ) : (
                <div className={view === 'week' ? 'igr:grid igr:gap-3 igr:md:grid-cols-2 igr:xl:grid-cols-7' : ''}>
                    {range.days.map((d) => (
                        <Card key={d} className={d === today ? 'igr:border-primary/50' : ''}>
                            <CardContent className="igr:p-3">
                                <button
                                    type="button"
                                    className="igr:mb-2 igr:block igr:text-left igr:text-xs igr:font-medium igr:capitalize igr:text-muted-foreground igr:hover:text-foreground"
                                    onClick={() => {
                                        setView('day');
                                        setDate(d);
                                    }}
                                >
                                    {fmtDay(d, {
                                        weekday: 'short',
                                        day: 'numeric',
                                        month: 'short',
                                    })}
                                </button>
                                {(grouped[d] ?? []).length === 0 ? (
                                    <p className="igr:text-xs igr:text-muted-foreground/70">{t('agenda.calendar.free')}</p>
                                ) : (
                                    <ul className="igr:space-y-2">
                                        {(grouped[d] ?? []).map((a) => (
                                            <li key={a.id} className={`igr:rounded-md igr:border igr:p-2 igr:text-[13px] ${a.status === 'cancelled' ? 'igr:opacity-60' : ''}`}>
                                                <div className="igr:flex igr:items-start igr:gap-2">
                                                    <div className="igr:min-w-0 igr:flex-1">
                                                        <p className="igr:font-medium igr:tabular-nums">
                                                            {localTime(a.starts_at, tz, i18n.language)}
                                                            <span className="igr:font-normal igr:text-muted-foreground"> – {localTime(a.ends_at, tz, i18n.language)}</span>
                                                        </p>
                                                        <p className="igr:truncate">{a.service?.name ?? '—'}</p>
                                                        {a.contact ? (
                                                            <Link to={`/w/${workspace.id}/contactos/${a.contact.id}`} className="igr:block igr:truncate igr:text-muted-foreground igr:hover:underline">
                                                                {a.contact.name ?? a.contact.phone ?? a.contact.email}
                                                            </Link>
                                                        ) : null}
                                                        {staffName(a.staff_id) ? (
                                                            <p className="igr:truncate igr:text-xs igr:text-muted-foreground">
                                                                {t('agenda.calendar.with', {
                                                                    name: staffName(a.staff_id),
                                                                })}
                                                            </p>
                                                        ) : null}
                                                        <Badge variant={statusTone(a.status)} className="igr:mt-1">
                                                            {t(`agenda.status.${a.status}`)}
                                                        </Badge>
                                                    </div>
                                                    {actionsFor(a).length > 0 ? (
                                                        <DropdownMenu>
                                                            <DropdownMenuTrigger asChild>
                                                                <Button variant="ghost" size="icon" aria-label={t('agenda.calendar.actions')}>
                                                                    <MoreHorizontal />
                                                                </Button>
                                                            </DropdownMenuTrigger>
                                                            <DropdownMenuContent align="end">
                                                                {actionsFor(a).map((action) => (
                                                                    <DropdownMenuItem key={action} onSelect={() => act(a, action)}>
                                                                        {t(`agenda.calendar.action.${action}`)}
                                                                    </DropdownMenuItem>
                                                                ))}
                                                            </DropdownMenuContent>
                                                        </DropdownMenu>
                                                    ) : null}
                                                </div>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </CardContent>
                        </Card>
                    ))}
                </div>
            )}
            {booking ? <BookDialog data={data} tz={tz} today={today} onClose={() => setBooking(false)} /> : null}
            {moving ? <RescheduleDialog data={data} tz={tz} today={today} appointment={moving} onClose={() => setMoving(null)} /> : null}
        </div>
    );
}

interface ContactHit {
    id: string;
    name: string;
    email: string | null;
    phone: string | null;
}

function BookDialog({ data, tz, today, onClose }: { data: BookingOverview; tz: string; today: string; onClose: () => void }) {
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
            <DialogContent className="igr:max-w-lg">
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
            <DialogContent className="igr:max-w-lg">
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
