import { ExternalLink, Send } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input, Select, Textarea } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { DescriptionList, ErrorState } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { useCan, useWorkspace } from '@/hooks/useWorkspace';
import { formatDateTime, formatMoney } from '@/lib/format';
import { toast } from '@/stores/toast';
import { errorMessage } from '@/features/contacts/errors';
import { useAddNote, useLead, useMoveLead, useTeam, useUpdateLead } from './api';
import { moveNeeds, temperatureTone } from './logic';
import { MoveDialog } from './MoveDialog';
import type { Activity, LeadDetail, ScoreReason, Stage } from './types';

/** Detalle de un lead en un panel lateral: contacto, fuente, puntaje con razones, etapa, vendedor, valor, próximo paso, historia y notas. */
export function LeadDrawer({ id, onClose }: { id: string | null; onClose: () => void }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const lead = useLead(workspace.id, id);
    return (
        <Dialog open={id !== null} onOpenChange={(open) => (!open ? onClose() : undefined)}>
            <DialogContent className="igr:left-auto igr:right-0 igr:top-0 igr:h-dvh igr:max-w-xl igr:translate-x-0 igr:overflow-y-auto igr:rounded-none igr:border-y-0 igr:border-r-0">
                {lead.isError ? (
                    <ErrorState error={lead.error} onRetry={() => void lead.refetch()} />
                ) : lead.isPending ? (
                    <>
                        <DialogHeader>
                            <DialogTitle>{t('leads.drawer.loading')}</DialogTitle>
                        </DialogHeader>
                        <Skeleton className="igr:h-96" />
                    </>
                ) : (
                    <Detail lead={lead.data} />
                )}
            </DialogContent>
        </Dialog>
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

    return (
        <div className="igr:space-y-5">
            <DialogHeader>
                <DialogTitle className="igr:flex igr:items-center igr:gap-2">
                    <span className="igr:truncate">{lead.contact?.name ?? t('leads.noName')}</span>
                    <Badge variant={temperatureTone(lead.temperature)}>
                        {lead.score} · {t(`leads.temperature.${lead.temperature}`)}
                    </Badge>
                </DialogTitle>
                <DialogDescription>
                    {[lead.service ?? t('leads.noService'), lead.contact?.city, t(`leads.origins.${lead.origin}`)].filter(Boolean).join(' · ')}
                </DialogDescription>
            </DialogHeader>

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

            <Timeline activities={lead.activities} stages={stages} currency={lead.currency} />
            <NoteForm id={lead.id} />

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
        </div>
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
                return t('leads.timeline.firstResponse');
            case 'conversion':
                return t(`leads.timeline.conversion_${str(d.status, 'queued')}`);
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
