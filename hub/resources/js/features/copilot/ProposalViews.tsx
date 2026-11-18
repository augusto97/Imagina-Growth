import { AlertTriangle, CalendarClock, Mail, MessageCircle, Smartphone, Tag, Users } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input, Select } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { FieldError } from '@/components/ui/misc';
import { useWorkspace } from '@/hooks/useWorkspace';
import { formatMoney, formatNumber } from '@/lib/format';
import { AiBadge } from './AiBadge';
import { useEditDraft } from './api';
import { copilotError } from './CopilotDialog';
import type { CampaignDraft, CampaignEdits, CopyField, Draft, FlowDraft } from './types';

const CHANNEL_ICON = { email: Mail, whatsapp: MessageCircle, sms: Smartphone } as const;
const COPY_FIELDS: CopyField[] = ['subject', 'preheader', 'headline', 'intro', 'cta'];

function Section({ icon: Icon, title, children, aside }: { icon: typeof Users; title: string; children: React.ReactNode; aside?: React.ReactNode }) {
    return (
        <section className="igr:rounded-lg igr:border igr:p-3">
            <div className="igr:mb-2 igr:flex igr:items-center igr:justify-between igr:gap-2">
                <h3 className="igr:flex igr:items-center igr:gap-1.5 igr:text-[13px] igr:font-semibold">
                    <Icon className="igr:size-4 igr:text-muted-foreground" aria-hidden="true" />
                    {title}
                </h3>
                {aside}
            </div>
            {children}
        </section>
    );
}

function Warnings({ items }: { items: string[] }) {
    const { t } = useTranslation();
    if (items.length === 0) {
        return null;
    }
    return (
        <div role="note" className="igr:rounded-md igr:border igr:border-warning/40 igr:bg-warning/10 igr:p-2 igr:text-xs">
            <p className="igr:mb-1 igr:flex igr:items-center igr:gap-1 igr:font-medium">
                <AlertTriangle className="igr:size-3.5" aria-hidden="true" />
                {t('copilot.review.warnings')}
            </p>
            <ul className="igr:list-disc igr:space-y-0.5 igr:pl-5">
                {items.map((w) => (
                    <li key={w}>{w}</li>
                ))}
            </ul>
        </div>
    );
}

export function CampaignProposalView({ draft, onChange, editable }: { draft: CampaignDraft; onChange: (d: Draft) => void; editable: boolean }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const edit = useEditDraft(workspace.id, draft.id);
    const p = draft.proposal;
    const [form, setForm] = useState<CampaignEdits>({});
    const dirty = Object.keys(form).length > 0;
    const locale = workspace.locale;
    const copyOf = (i: number, f: CopyField): string => {
        const edited = form.channels?.[i]?.[f];
        return edited ?? p.channels[i]?.template.copy[f] ?? '';
    };
    const setCopy = (i: number, f: CopyField, value: string) => {
        const channels = [...(form.channels ?? p.channels.map(() => ({})))];
        channels[i] = { ...channels[i], [f]: value };
        setForm({ ...form, channels });
    };

    return (
        <div className="igr:space-y-3">
            <div className="igr:space-y-1">
                <div className="igr:flex igr:items-center igr:justify-between igr:gap-2">
                    <Label htmlFor="cp-name">{t('copilot.review.name')}</Label>
                    <AiBadge source={p.summary_source} />
                </div>
                <Input id="cp-name" value={form.name ?? p.name} disabled={!editable} maxLength={150} onChange={(e) => setForm({ ...form, name: e.target.value })} />
                <p className="igr:text-[13px] igr:text-muted-foreground">{p.summary}</p>
            </div>

            <Section icon={Users} title={t('copilot.review.segment')} aside={p.segment.mode === 'new' ? <Badge variant="secondary">{t('copilot.review.newSegment')}</Badge> : null}>
                <p className="igr:text-[13px]">
                    <strong>{p.segment.name}</strong> · {t('copilot.review.members', { count: p.segment.members ?? 0, n: formatNumber(p.segment.members ?? 0, locale) })}
                </p>
                <p className="igr:text-xs igr:text-muted-foreground">
                    {Object.entries(p.segment.reach ?? {})
                        .map(([c, n]) => `${t(`copilot.channel.${c}`, { defaultValue: c })}: ${formatNumber(n, locale)}`)
                        .join(' · ')}
                </p>
            </Section>

            {p.channels.map((c, i) => {
                const Icon = CHANNEL_ICON[c.channel];
                const r = c.review;
                return (
                    <Section key={c.channel} icon={Icon} title={t(`copilot.channel.${c.channel}`)} aside={<span className="igr:text-xs igr:text-muted-foreground">{c.template.name}</span>}>
                        {c.template.mode === 'library' ? (
                            <div className="igr:grid igr:gap-2 igr:sm:grid-cols-2">
                                {COPY_FIELDS.filter((f) => f in c.template.copy || form.channels?.[i]?.[f] !== undefined).map((f) => (
                                    <div key={f} className={f === 'intro' ? 'igr:sm:col-span-2' : ''}>
                                        <Label htmlFor={`cp-${i}-${f}`} className="igr:text-xs">
                                            {t(`copilot.copy.${f}`)}
                                        </Label>
                                        <Input id={`cp-${i}-${f}`} value={copyOf(i, f)} disabled={!editable} onChange={(e) => setCopy(i, f, e.target.value)} />
                                    </div>
                                ))}
                            </div>
                        ) : (
                            <p className="igr:text-xs igr:text-muted-foreground">{t('copilot.review.existingTemplate')}</p>
                        )}
                        {r ? (
                            <dl className="igr:mt-2 igr:grid igr:grid-cols-3 igr:gap-2 igr:text-xs">
                                <div>
                                    <dt className="igr:text-muted-foreground">{t('copilot.review.willSend')}</dt>
                                    <dd className="igr:font-medium igr:tabular-nums">{formatNumber(r.will_send, locale)}</dd>
                                </div>
                                <div>
                                    <dt className="igr:text-muted-foreground">{t('copilot.review.excluded')}</dt>
                                    <dd className="igr:font-medium igr:tabular-nums" title={r.excluded_reasons.map((x) => `${x.reason}: ${x.count}`).join('\n')}>
                                        {formatNumber(r.excluded, locale)}
                                    </dd>
                                </div>
                                <div>
                                    <dt className="igr:text-muted-foreground">{t('copilot.review.cost')}</dt>
                                    <dd className="igr:font-medium igr:tabular-nums">
                                        {r.cost_local !== null && r.currency ? formatMoney(r.cost_local, r.currency, locale) : formatMoney(r.cost_usd, 'USD', locale)}
                                    </dd>
                                </div>
                            </dl>
                        ) : null}
                        {r && !r.channel_available ? <p className="igr:mt-1 igr:text-xs igr:text-warning">{t('copilot.review.channelUnavailable')}</p> : null}
                    </Section>
                );
            })}

            <Section icon={CalendarClock} title={t('copilot.review.schedule')}>
                <div className="igr:grid igr:gap-2 igr:sm:grid-cols-3">
                    <div>
                        <Label htmlFor="cp-date" className="igr:text-xs">
                            {t('copilot.review.date')}
                        </Label>
                        <Input id="cp-date" type="date" disabled={!editable} value={form.schedule?.date ?? p.schedule.date} onChange={(e) => setForm({ ...form, schedule: { ...form.schedule, date: e.target.value } })} />
                    </div>
                    <div>
                        <Label htmlFor="cp-hour" className="igr:text-xs">
                            {t('copilot.review.hour')}
                        </Label>
                        <Input id="cp-hour" type="number" min={0} max={23} disabled={!editable} value={form.schedule?.hour ?? p.schedule.hour} onChange={(e) => setForm({ ...form, schedule: { ...form.schedule, hour: Number(e.target.value) } })} />
                    </div>
                    <div>
                        <Label htmlFor="cp-mode" className="igr:text-xs">
                            {t('copilot.review.mode')}
                        </Label>
                        <Select
                            id="cp-mode"
                            disabled={!editable}
                            value={form.schedule?.send_mode ?? p.schedule.send_mode}
                            onChange={(e) => setForm({ ...form, schedule: { ...form.schedule, send_mode: e.target.value as 'scheduled' | 'contact_timezone' | 'best_time' } })}
                        >
                            {(['scheduled', 'contact_timezone', 'best_time'] as const).map((m) => (
                                <option key={m} value={m}>
                                    {t(`copilot.sendMode.${m}`)}
                                </option>
                            ))}
                        </Select>
                    </div>
                </div>
                <p className="igr:mt-1 igr:text-xs igr:text-muted-foreground">{t('copilot.review.localTime', { when: p.schedule.local, tz: p.schedule.timezone })}</p>
                {p.schedule.note ? <p className="igr:mt-1 igr:text-xs igr:text-warning">{p.schedule.note}</p> : null}
            </Section>

            {p.coupon.enabled ? (
                <Section icon={Tag} title={t('copilot.review.coupon')}>
                    <p className="igr:text-[13px]">
                        {t(`copilot.couponKind.${p.coupon.kind}`, {
                            amount: p.coupon.kind === 'percent' ? formatNumber(p.coupon.amount, locale) : formatMoney(p.coupon.amount, workspace.currency, locale),
                        })}{' '}
                        · {t('copilot.review.couponDays', { count: p.coupon.expires_in_days })}
                        {p.coupon.minimum_amount !== null ? ` · ${t('copilot.review.couponMin', { amount: formatMoney(p.coupon.minimum_amount, workspace.currency, locale) })}` : ''}
                    </p>
                </Section>
            ) : null}

            <Warnings items={p.warnings} />
            {editable && dirty ? (
                <div className="igr:flex igr:items-center igr:justify-end igr:gap-2">
                    <FieldError message={edit.isError ? copilotError(edit.error, t('copilot.errors.generic')) : undefined} />
                    <Button type="button" variant="outline" size="sm" onClick={() => setForm({})}>
                        {t('common.cancel')}
                    </Button>
                    <Button
                        type="button"
                        size="sm"
                        loading={edit.isPending}
                        onClick={() =>
                            edit.mutate(form, {
                                onSuccess: (d) => {
                                    setForm({});
                                    onChange(d);
                                },
                            })
                        }
                    >
                        {t('copilot.review.saveEdits')}
                    </Button>
                </div>
            ) : null}
        </div>
    );
}

/** Clave de i18n y parámetros para describir el disparador de un flujo propuesto. */
export function triggerLabel(trigger: Record<string, unknown>): [string, Record<string, string | undefined>] {
    const str = (v: unknown): string | undefined => (typeof v === 'string' ? v : undefined);
    switch (trigger.type) {
        case 'event':
            return ['copilot.trigger.event', { event: str(trigger.event) }];
        case 'segment':
            return ['copilot.trigger.segment', { segment: str(trigger.segment_id) ?? str(trigger.system_key), on: str(trigger.on) ?? 'entered' }];
        case 'date':
            return ['copilot.trigger.date', { attribute: str(trigger.attribute) }];
        default:
            return ['copilot.trigger.other', { type: str(trigger.type) }];
    }
}

export function FlowProposalView({ draft }: { draft: FlowDraft }) {
    const { t } = useTranslation();
    const p = draft.proposal;
    const [key, params] = triggerLabel(p.trigger);
    const triggerText = t(key, { ...params, on: params.on !== undefined ? t(`copilot.trigger.on.${params.on}`) : undefined });

    return (
        <div className="igr:space-y-3">
            <div className="igr:space-y-1">
                <div className="igr:flex igr:items-center igr:justify-between igr:gap-2">
                    <h3 className="igr:text-sm igr:font-semibold">{p.name}</h3>
                    <AiBadge source={p.summary_source} />
                </div>
                <p className="igr:text-[13px] igr:text-muted-foreground">{p.summary}</p>
            </div>
            <Section icon={CalendarClock} title={t('copilot.review.trigger')}>
                <p className="igr:text-[13px]">{triggerText}</p>
                {p.goal ? <p className="igr:text-xs igr:text-muted-foreground">{t('copilot.review.goal', { event: p.goal.event })}</p> : null}
            </Section>
            <Section icon={Users} title={t('copilot.review.steps')}>
                <ol className="igr:list-decimal igr:space-y-0.5 igr:pl-5 igr:text-[13px]">
                    {p.steps.map((s) => (
                        <li key={s.id} className={p.pending_whatsapp.includes(s.id) ? 'igr:text-warning' : ''}>
                            {s.label}
                        </li>
                    ))}
                </ol>
            </Section>
            <Warnings items={p.warnings} />
        </div>
    );
}
