import { CalendarPlus, CircleCheck, CircleX, ExternalLink, Send, UserRound } from 'lucide-react';
import { useState, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { Avatar } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input, Select, Textarea } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { DescriptionList, ErrorState } from '@/components/ui/misc';
import { Sheet, SheetBody, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { Skeleton } from '@/components/ui/skeleton';
import { useCan, useWorkspace } from '@/hooks/useWorkspace';
import { formatDateTime, formatMoney } from '@/lib/format';
import { toast } from '@/stores/toast';
import { useContactServices } from '@/features/contacts/api';
import { errorMessage } from '@/features/contacts/errors';
import { APPOINTMENT_TONE } from '@/features/contacts/profile/ServicesTab';
import { useAddNote, useLead, useMoveLead, useTeam, useUpdateLead } from './api';
import { useLeadNotices } from '@/features/notices/api';
import { NoticeLogList } from '@/features/notices/NoticeLogList';
import { BriefCard } from './BriefCard';
import { QualificationCard } from './QualificationCard';
import { TasksCard } from './TasksCard';
import { moveNeeds, temperatureTone } from './logic';
import { MoveDialog } from './MoveDialog';
import type { Activity, LeadDetail, ScoreReason, Stage } from './types';

/**
 * La ficha de un lead en un cajón lateral (a pantalla completa en el celular): quién es y qué pide, las acciones
 * (ganado, perdido, agendar, ver su perfil), etapa y vendedor, el siguiente paso, el resumen automático de la
 * conversación, las preguntas de calificación, sus citas y cotizaciones, tareas, de dónde vino, el puntaje con sus
 * razones, la historia y las notas.
 */
export function LeadDrawer({ id, onClose }: { id: string | null; onClose: () => void }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const lead = useLead(workspace.id, id);
    return (
        <Sheet open={id !== null} onOpenChange={(open) => (!open ? onClose() : undefined)}>
            <SheetContent side="right" className="igr:max-w-xl" aria-describedby={undefined}>
                {lead.isError ? (
                    <SheetBody>
                        <SheetTitle className="igr:sr-only">{t('leads.drawer.loading')}</SheetTitle>
                        <ErrorState error={lead.error} onRetry={() => void lead.refetch()} />
                    </SheetBody>
                ) : lead.isPending ? (
                    <>
                        <SheetHeader>
                            <SheetTitle>{t('leads.drawer.loading')}</SheetTitle>
                        </SheetHeader>
                        <SheetBody>
                            <Skeleton className="igr:h-96" />
                        </SheetBody>
                    </>
                ) : (
                    <Detail lead={lead.data} />
                )}
            </SheetContent>
        </Sheet>
    );
}

function Section({ title, children, action }: { title: string; children: ReactNode; action?: ReactNode }) {
    return (
        <section className="igr:space-y-2">
            <div className="igr:flex igr:items-center igr:justify-between igr:gap-2">
                <h3 className="igr:text-[13px] igr:font-semibold">{title}</h3>
                {action}
            </div>
            {children}
        </section>
    );
}

function Detail({ lead }: { lead: LeadDetail }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const seeAll = useCan('viewAllContacts');
    const team = useTeam(workspace.id);
    const move = useMoveLead(workspace.id);
    const update = useUpdateLead(workspace.id);
    const [pending, setPending] = useState<Stage | null>(null);
    const stages = useStages(lead);
    const money = (v: string | null) => (v === null ? '—' : formatMoney(v, lead.currency, workspace.locale));
    const fail = (e: unknown) => toast.error(errorMessage(e, t));

    const changeStage = (key: string, extra: { value?: string | null; lost_reason?: string | null } = {}) =>
        move.mutate({ id: lead.id, stage: key, ...extra }, { onSuccess: () => toast.success(t('leads.move.done', { stage: stages.find((s) => s.key === key)?.label ?? key })), onError: fail });

    const open = lead.won_at === null && lead.lost_at === null;
    const won = stages.find((s) => s.kind === 'won');
    const lost = stages.find((s) => s.kind === 'lost');
    const bookings = (workspace.modules ?? null) === null || (workspace.modules ?? []).includes('bookings');
    const ask = (stage: Stage | undefined) => {
        if (!stage) return;
        if (moveNeeds(stage) !== null) setPending(stage);
        else changeStage(stage.key);
    };

    return (
        <>
            <SheetHeader className="igr:gap-2">
                <div className="igr:flex igr:items-center igr:gap-3">
                    <Avatar name={lead.contact?.name ?? null} seed={lead.contact?.id} size="lg" />
                    <div className="igr:min-w-0">
                        <SheetTitle className="igr:truncate">{lead.contact?.name ?? t('leads.noName')}</SheetTitle>
                        <SheetDescription>{[lead.service ?? t('leads.noService'), lead.contact?.city].filter(Boolean).join(' · ')}</SheetDescription>
                    </div>
                </div>
                <div className="igr:flex igr:flex-wrap igr:items-center igr:gap-1.5">
                    <Badge variant="outline">{stages.find((s) => s.key === lead.stage)?.label ?? lead.stage}</Badge>
                    {open ? <Badge variant={temperatureTone(lead.temperature)}>{t('leads.drawer.temperature', { temperature: t(`leads.temperature.${lead.temperature}`), score: lead.score })}</Badge> : null}
                    <Badge variant="secondary">{t(`leads.origins.${lead.origin}`)}</Badge>
                    {lead.value !== null ? <Badge variant="secondary">{money(lead.value)}</Badge> : null}
                </div>
            </SheetHeader>
            <SheetBody className="igr:space-y-6">
                <div className="igr:flex igr:flex-wrap igr:gap-2">
                    {open && won ? (
                        <Button size="sm" onClick={() => ask(won)} disabled={move.isPending}>
                            <CircleCheck />
                            {t('leads.drawer.markWon')}
                        </Button>
                    ) : null}
                    {bookings && lead.contact ? (
                        <Button size="sm" variant="outline" asChild>
                            <Link to={`/w/${workspace.id}/agenda?nueva=${lead.contact.id}`}>
                                <CalendarPlus />
                                {t('leads.drawer.book')}
                            </Link>
                        </Button>
                    ) : null}
                    {lead.contact ? (
                        <Button size="sm" variant="outline" asChild>
                            <Link to={`/w/${workspace.id}/contactos/${lead.contact.id}`}>
                                <UserRound />
                                {t('leads.drawer.profileShort')}
                            </Link>
                        </Button>
                    ) : null}
                    {open && lost ? (
                        <Button size="sm" variant="ghost" onClick={() => ask(lost)} disabled={move.isPending}>
                            <CircleX />
                            {t('leads.drawer.markLost')}
                        </Button>
                    ) : null}
                </div>

                <section className="igr:grid igr:gap-3 igr:sm:grid-cols-2">
                    <div className="igr:space-y-1">
                        <Label htmlFor="lead-stage">{t('leads.drawer.stage')}</Label>
                        <Select
                            id="lead-stage"
                            value={lead.stage}
                            disabled={move.isPending}
                            onChange={(e) => {
                                const stage = stages.find((s) => s.key === e.target.value);
                                if (!stage) return;
                                if (moveNeeds(stage) !== null) setPending(stage);
                                else changeStage(stage.key);
                            }}
                        >
                            {stages.map((s) => (
                                <option key={s.key} value={s.key}>
                                    {s.label}
                                </option>
                            ))}
                        </Select>
                    </div>
                    <div className="igr:space-y-1">
                        <Label htmlFor="lead-owner">{t('leads.drawer.owner')}</Label>
                        <Select
                            id="lead-owner"
                            value={lead.owner?.id ?? ''}
                            disabled={!seeAll || update.isPending}
                            onChange={(e) => update.mutate({ id: lead.id, owner_id: e.target.value || null }, { onError: fail })}
                        >
                            <option value="">{t('leads.unassigned')}</option>
                            {(team.data?.data ?? []).map((m) => (
                                <option key={m.id} value={m.id}>
                                    {m.name}
                                </option>
                            ))}
                            {lead.owner && !(team.data?.data ?? []).some((m) => m.id === lead.owner?.id) ? <option value={lead.owner.id}>{lead.owner.name}</option> : null}
                        </Select>
                    </div>
                </section>

                <EditableFields lead={lead} />

                <BriefCard key={lead.brief_updated_at ?? 'empty'} leadId={lead.id} brief={lead.brief} editable />

                <QualificationCard qualification={lead.qualification} />

                {lead.contact ? <ServicesBlock contactId={lead.contact.id} /> : null}

                <TasksCard leadId={lead.id} tasks={lead.tasks} />

                <section>
                    <h3 className="igr:mb-2 igr:text-[13px] igr:font-medium">{t('leads.drawer.contact')}</h3>
                    <DescriptionList
                        items={[
                            { label: t('leads.drawer.email'), value: lead.email ?? '—' },
                            { label: t('leads.drawer.phone'), value: lead.phone ?? '—' },
                            { label: t('leads.drawer.firstResponse'), value: lead.first_response_at ? formatDateTime(lead.first_response_at, workspace.locale) : t('leads.noResponse') },
                            { label: t('leads.drawer.value'), value: money(lead.value) },
                        ]}
                    />
                    {lead.contact ? (
                        <Link to={`/w/${workspace.id}/contactos/${lead.contact.id}`} className="igr:mt-2 igr:inline-flex igr:items-center igr:gap-1 igr:text-[13px] igr:text-primary igr:hover:underline">
                            {t('leads.drawer.profile')}
                            <ExternalLink className="igr:size-3.5" aria-hidden />
                        </Link>
                    ) : null}
                </section>

                <section>
                    <h3 className="igr:mb-2 igr:text-[13px] igr:font-medium">{t('leads.drawer.source')}</h3>
                    <DescriptionList
                        items={[
                            { label: t('leads.drawer.channel'), value: lead.channel ? t(`leads.channels.${lead.channel}`, { defaultValue: lead.channel_label ?? lead.channel }) : '—' },
                            { label: t('leads.drawer.campaign'), value: lead.source.utm_campaign ?? '—' },
                            { label: t('leads.drawer.page'), value: lead.source.page ?? '—' },
                            { label: t('leads.drawer.form'), value: lead.source.form_name ?? '—' },
                            {
                                label: t('leads.drawer.adClick'),
                                value: lead.source.has_gclid || lead.source.has_fbclid ? [lead.source.has_gclid ? 'Google' : null, lead.source.has_fbclid ? 'Meta' : null].filter(Boolean).join(', ') : t('leads.drawer.none'),
                            },
                            {
                                label: t('leads.drawer.offline'),
                                value: lead.offline_conversion_at ? t('leads.drawer.offlineSent', { at: formatDateTime(lead.offline_conversion_at, workspace.locale) }) : t('leads.drawer.offlinePending'),
                            },
                        ]}
                    />
                </section>

                <section>
                    <h3 className="igr:mb-2 igr:text-[13px] igr:font-medium">{t('leads.drawer.score', { score: lead.score })}</h3>
                    <ul className="igr:space-y-1.5">
                        {lead.score_reasons.map((r, i) => (
                            <ReasonRow key={`${r.signal}-${i}`} reason={r} />
                        ))}
                        {lead.score_reasons.length === 0 ? <li className="igr:text-[13px] igr:text-muted-foreground">{t('leads.drawer.noReasons')}</li> : null}
                    </ul>
                </section>

                <NoticesBlock leadId={lead.id} />
                <Timeline activities={lead.activities} stages={stages} currency={lead.currency} />
                <NoteForm id={lead.id} />
            </SheetBody>

            {pending ? (
                <MoveDialog
                    stage={pending}
                    initialValue={lead.value}
                    onCancel={() => setPending(null)}
                    onConfirm={(extra) => {
                        changeStage(pending.key, extra);
                        setPending(null);
                    }}
                />
            ) : null}
        </>
    );
}

/** Qué se le avisó al equipo por este lead y al cliente por sus citas (lote 0.4.0, docs/avisos.md §4). */
function NoticesBlock({ leadId }: { leadId: string }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const notices = useLeadNotices(workspace.id, leadId);
    return (
        <Section title={t('notices.title')}>
            <NoticeLogList rows={notices.data?.data} loading={notices.isPending} error={notices.isError} withCustomerCheck={false} />
        </Section>
    );
}

/** Las citas y cotizaciones de la persona del lead (lo mismo que ve su perfil, sin pedir dos veces). */
function ServicesBlock({ contactId }: { contactId: string }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const services = useContactServices(workspace.id, contactId);
    if (services.isError) return null;
    const data = services.data;
    return (
        <Section title={t('leads.drawer.appointments')}>
            {data === undefined ? (
                <Skeleton className="igr:h-16" />
            ) : data.appointments.length === 0 && data.quotes.length === 0 ? (
                <p className="igr:text-[13px] igr:text-muted-foreground">{t('leads.drawer.noAppointments')}</p>
            ) : (
                <ul className="igr:divide-y igr:rounded-lg igr:border">
                    {data.appointments.slice(0, 5).map((a) => (
                        <li key={a.id} className="igr:flex igr:items-center igr:justify-between igr:gap-2 igr:px-3 igr:py-2 igr:text-[13px]">
                            <span className="igr:min-w-0">
                                <span className="igr:block igr:font-medium igr:tabular-nums">{formatDateTime(a.starts_at, workspace.locale, workspace.timezone)}</span>
                                <span className="igr:block igr:truncate igr:text-caption igr:text-muted-foreground">{a.service ?? '—'}</span>
                            </span>
                            <Badge variant={APPOINTMENT_TONE[a.status]}>{t(`contacts.appointmentStatus.${a.status}`)}</Badge>
                        </li>
                    ))}
                    {data.quotes.slice(0, 3).map((q) => (
                        <li key={q.id} className="igr:flex igr:items-center igr:justify-between igr:gap-2 igr:px-3 igr:py-2 igr:text-[13px]">
                            <span className="igr:min-w-0">
                                <span className="igr:block igr:truncate igr:font-medium">{q.calculator ?? t('leads.drawer.quote')}</span>
                                <span className="igr:block igr:text-caption igr:text-muted-foreground">{formatDateTime(q.created_at, workspace.locale, workspace.timezone)}</span>
                            </span>
                            <span className="igr:shrink-0 igr:font-medium igr:tabular-nums">{formatMoney(q.estimate_max ?? q.estimate_min, q.currency, workspace.locale)}</span>
                        </li>
                    ))}
                </ul>
            )}
        </Section>
    );
}

function useStages(lead: LeadDetail): Stage[] {
    return lead.pipeline_stages;
}

function EditableFields({ lead }: { lead: LeadDetail }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const update = useUpdateLead(workspace.id);
    const [value, setValue] = useState((lead.value ?? '').replace(/\.00$/, ''));
    const [service, setService] = useState(lead.service ?? '');
    const [nextStep, setNextStep] = useState(lead.next_step ?? '');
    const [nextAt, setNextAt] = useState(lead.next_step_at ? lead.next_step_at.slice(0, 10) : '');
    const dirty = value !== (lead.value ?? '').replace(/\.00$/, '') || service !== (lead.service ?? '') || nextStep !== (lead.next_step ?? '') || nextAt !== (lead.next_step_at ? lead.next_step_at.slice(0, 10) : '');
    return (
        <form
            className="igr:grid igr:gap-3 igr:sm:grid-cols-2"
            onSubmit={(e) => {
                e.preventDefault();
                update.mutate(
                    { id: lead.id, value: value.trim() === '' ? null : value.replace(/[^\d.]/g, ''), service: service.trim() || null, next_step: nextStep.trim() || null, next_step_at: nextAt || null },
                    { onSuccess: () => toast.success(t('leads.drawer.saved')), onError: (err) => toast.error(errorMessage(err, t)) },
                );
            }}
        >
            <div className="igr:space-y-1">
                <Label htmlFor="lead-value">{t('leads.drawer.value')}</Label>
                <Input id="lead-value" inputMode="decimal" value={value} onChange={(e) => setValue(e.target.value)} />
            </div>
            <div className="igr:space-y-1">
                <Label htmlFor="lead-service">{t('leads.drawer.service')}</Label>
                <Input id="lead-service" maxLength={160} value={service} onChange={(e) => setService(e.target.value)} />
            </div>
            <div className="igr:space-y-1">
                <Label htmlFor="lead-next">{t('leads.drawer.nextStep')}</Label>
                <Input id="lead-next" maxLength={255} value={nextStep} onChange={(e) => setNextStep(e.target.value)} placeholder={t('leads.drawer.nextStepPlaceholder')} />
            </div>
            <div className="igr:space-y-1">
                <Label htmlFor="lead-next-at">{t('leads.drawer.nextStepAt')}</Label>
                <Input id="lead-next-at" type="date" value={nextAt} onChange={(e) => setNextAt(e.target.value)} />
            </div>
            {dirty ? (
                <div className="igr:sm:col-span-2">
                    <Button type="submit" size="sm" disabled={update.isPending}>
                        {t('common.save')}
                    </Button>
                </div>
            ) : null}
        </form>
    );
}

function ReasonRow({ reason }: { reason: ScoreReason }) {
    const { t } = useTranslation();
    const detail = reason.detail ?? {};
    const extra =
        reason.signal === 'pricing_page'
            ? t('leads.reasons.views', { count: Number(detail.views ?? 1) })
            : reason.signal === 'decay'
              ? t('leads.reasons.weeks', { count: Number(detail.weeks ?? 1) })
              : reason.signal === 'service_area' && typeof detail.city === 'string'
                ? detail.city
                : reason.signal === 'ai'
                  ? reason.reason
                  : reason.signal === 'qualification'
                    ? t('leads.reasons.answered', { count: Number(detail.answered ?? 0) })
                    : null;
    return (
        <li className="igr:flex igr:items-start igr:justify-between igr:gap-3 igr:text-[13px]">
            <span>
                {t(`leads.reasons.${reason.signal}`, { defaultValue: reason.signal })}
                {extra ? <span className="igr:block igr:text-xs igr:text-muted-foreground">{extra}</span> : null}
            </span>
            <span className="igr:shrink-0 igr:font-medium igr:tabular-nums">
                {reason.points > 0 ? '+' : ''}
                {reason.points}
            </span>
        </li>
    );
}

function Timeline({ activities, stages, currency }: { activities: Activity[]; stages: Stage[]; currency: string }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const str = (v: unknown, fallback = ''): string => (typeof v === 'string' || typeof v === 'number' ? String(v) : fallback);
    const label = (k: unknown) => stages.find((s) => s.key === k)?.label ?? str(k);
    const text = (a: Activity): string => {
        const d = a.data;
        switch (a.kind) {
            case 'created':
                return t('leads.timeline.created', { origin: t(`leads.origins.${str(d.origin, 'form')}`), score: Number(d.score ?? 0) });
            case 'intake':
                return t('leads.timeline.intake', { origin: t(`leads.origins.${str(d.origin, 'form')}`) });
            case 'stage':
                return d.reopened ? t('leads.timeline.reopened') : t('leads.timeline.stage', { from: label(d.from), to: label(d.to) }) + (d.reason ? ` · ${str(d.reason)}` : '');
            case 'score':
                return d.signal === 'ai' ? t('leads.timeline.ai', { points: Number(d.points ?? 0), reason: str(d.reason) }) : t('leads.timeline.score', { from: Number(d.from ?? 0), to: Number(d.to ?? 0) });
            case 'alert':
                return t(`leads.timeline.alert_${str(d.channel, 'none')}`, { defaultValue: t('leads.timeline.alert_none') });
            case 'owner':
                return t('leads.timeline.owner', { name: str(d.to_name, t('leads.unassigned')) });
            case 'value':
                return t('leads.timeline.value', { value: d.value ? formatMoney(str(d.value), currency, workspace.locale) : '—' });
            case 'message':
                return d.from === 'today' ? t('leads.timeline.fromToday', { channel: t(`today.channels.${str(d.channel, 'none')}`) }) : t('leads.timeline.firstResponse');
            case 'task':
                return t(d.kind === 'call' ? 'leads.timeline.call' : 'leads.timeline.task');
            case 'followup':
                return d.denied ? t('leads.timeline.followupDenied', { step: Number(d.step ?? 1) }) : t('leads.timeline.followup', { step: Number(d.step ?? 1), channel: t(`today.channels.${str(d.channel, 'none')}`) });
            case 'conversion':
                return t(`leads.timeline.conversion_${str(d.status, 'queued')}`);
            case 'brief': {
                const fields = (Array.isArray(d.fields) ? (d.fields as string[]) : []).map((f) => t(`leads.brief.fields.${f}`, { defaultValue: f })).join(', ');
                return a.actor === 'ai' ? t('leads.timeline.briefAi', { fields }) : t('leads.timeline.briefUser', { fields });
            }
            case 'qualification':
                return d.disqualified ? t('leads.timeline.qualificationOut') : t('leads.timeline.qualification');
            case 'routed':
                return t(`leads.timeline.routed_${str(d.action, 'agent')}`, { defaultValue: t('leads.timeline.routed_agent') });
            case 'note':
                return str(d.body);
            default:
                return a.kind;
        }
    };
    return (
        <section>
            <h3 className="igr:mb-2 igr:text-[13px] igr:font-medium">{t('leads.drawer.timeline')}</h3>
            <ol className="igr:space-y-2 igr:border-l igr:pl-3">
                {activities.map((a) => (
                    <li key={a.id} className="igr:text-[13px]">
                        <p className={a.kind === 'note' ? 'igr:whitespace-pre-wrap igr:rounded-md igr:bg-muted/50 igr:p-2' : undefined}>{text(a)}</p>
                        <p className="igr:text-xs igr:text-muted-foreground">
                            {formatDateTime(a.at, workspace.locale)} · {a.actor === 'user' ? (a.actor_name ?? t('leads.timeline.someone')) : t(`leads.timeline.actors.${a.actor}`)}
                        </p>
                    </li>
                ))}
            </ol>
        </section>
    );
}

function NoteForm({ id }: { id: string }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const add = useAddNote(workspace.id);
    const [body, setBody] = useState('');
    return (
        <form
            className="igr:space-y-2"
            onSubmit={(e) => {
                e.preventDefault();
                if (body.trim() === '') return;
                add.mutate({ id, body }, { onSuccess: () => setBody(''), onError: (err) => toast.error(errorMessage(err, t)) });
            }}
        >
            <Label htmlFor="lead-note">{t('leads.drawer.note')}</Label>
            <Textarea id="lead-note" rows={3} maxLength={5000} value={body} onChange={(e) => setBody(e.target.value)} placeholder={t('leads.drawer.notePlaceholder')} />
            <Button type="submit" size="sm" variant="outline" disabled={add.isPending || body.trim() === ''}>
                <Send />
                {t('leads.drawer.addNote')}
            </Button>
        </form>
    );
}
