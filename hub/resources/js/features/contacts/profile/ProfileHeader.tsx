import { Check, Info, Lock, Minus, Plus, X } from 'lucide-react';
import { useState, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Tooltip } from '@/components/ui/tooltip';
import { useWorkspace } from '@/hooks/useWorkspace';
import { formatDate, formatMoney } from '@/lib/format';
import { toast } from '@/stores/toast';
import { useContactAbilities } from '../abilities';
import { type ContactProfile, useUpdateContact } from '../api';
import { errorMessage } from '../errors';
import { CONSENT_CHANNELS, addTag, initials, rfmTone } from '../labels';

export function ProfileHeader({ profile, actions }: { profile: ContactProfile; actions?: ReactNode }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const name = profile.name !== '' ? profile.name : t('contacts.noName');
    const place = [profile.city, profile.region_code].filter(Boolean).join(', ');
    const money = (v: string | null) => formatMoney(v, profile.currency, workspace.locale);

    return (
        <Card className="igr:mb-4">
            <div className="igr:flex igr:flex-wrap igr:items-start igr:gap-4 igr:p-4">
                <div
                    aria-hidden="true"
                    className="igr:flex igr:size-12 igr:shrink-0 igr:items-center igr:justify-center igr:rounded-full igr:bg-primary/10 igr:text-base igr:font-semibold igr:text-primary"
                >
                    {initials(name)}
                </div>
                <div className="igr:min-w-0 igr:flex-1">
                    <h1 className="igr:truncate igr:text-lg igr:font-semibold igr:tracking-tight">{name}</h1>
                    <p className="igr:mt-0.5 igr:flex igr:flex-wrap igr:items-center igr:gap-x-2 igr:text-[13px] igr:text-muted-foreground">
                        {[profile.email, profile.phone, place].filter(Boolean).map((part, i) => (
                            <span key={i} className="igr:tabular-nums">
                                {i > 0 && <span className="igr:mr-2">·</span>}
                                {part}
                            </span>
                        ))}
                        {!profile.pii_visible && (profile.email || profile.phone) && (
                            <Tooltip content={t('contacts.profile.piiHidden')}>
                                <span className="igr:inline-flex igr:items-center" tabIndex={0} aria-label={t('contacts.profile.piiHidden')}>
                                    <Lock className="igr:size-3.5" />
                                </span>
                            </Tooltip>
                        )}
                    </p>
                    <div className="igr:mt-2 igr:flex igr:flex-wrap igr:items-center igr:gap-1.5">
                        {profile.lifecycle_stage && <Badge variant="secondary">{t(`stages.${profile.lifecycle_stage}`, { defaultValue: profile.lifecycle_stage })}</Badge>}
                        {profile.rfm ? (
                            <Tooltip content={t('contacts.profile.rfmScores', { r: profile.rfm.r ?? '—', f: profile.rfm.f ?? '—', m: profile.rfm.m ?? '—' })}>
                                <span tabIndex={0}>
                                    <Badge variant={rfmTone(profile.rfm.segment)}>{t(`contacts.rfm.${profile.rfm.segment}`, { defaultValue: profile.rfm.segment })}</Badge>
                                </span>
                            </Tooltip>
                        ) : (
                            <Badge variant="outline">{t('contacts.profile.noRfm')}</Badge>
                        )}
                        <span className="igr:mx-1 igr:h-4 igr:w-px igr:bg-border" aria-hidden="true" />
                        {CONSENT_CHANNELS.map((channel) => {
                            const consent = profile.consents[`${channel}.marketing`];
                            const label = t(`contacts.consentChannels.${channel}`);
                            const status = consent?.status === 'granted' ? 'granted' : consent?.status === 'revoked' ? 'revoked' : 'none';
                            const Icon = status === 'granted' ? Check : status === 'revoked' ? X : Minus;
                            return (
                                <Tooltip
                                    key={channel}
                                    content={
                                        status === 'none'
                                            ? t('contacts.consent.none', { channel: label })
                                            : t(status === 'granted' ? 'contacts.consent.grantedOn' : 'contacts.consent.revokedOn', {
                                                  channel: label,
                                                  date: formatDate(consent?.at, workspace.locale, workspace.timezone),
                                              })
                                    }
                                >
                                    <span tabIndex={0}>
                                        <Badge variant={status === 'granted' ? 'success' : 'outline'} className={status === 'revoked' ? 'igr:line-through' : undefined}>
                                            <Icon className="igr:size-3" aria-hidden="true" />
                                            {label}
                                            <span className="igr:sr-only">{t(`contacts.consent.status.${status}`)}</span>
                                        </Badge>
                                    </span>
                                </Tooltip>
                            );
                        })}
                    </div>
                </div>
                {actions}
            </div>
            <dl className="igr:grid igr:grid-cols-2 igr:gap-px igr:border-t igr:bg-border igr:md:grid-cols-4">
                <Kpi label={t('contacts.profile.clvHistoric')} help={t('contacts.profile.clvHistoricHelp')}>
                    {money(profile.clv.historic)}
                </Kpi>
                <Kpi label={t('contacts.profile.clvPredicted')}>
                    {profile.clv.predicted_12m !== null ? money(profile.clv.predicted_12m) : <Insufficient reason={profile.clv.predicted_reason} />}
                    <ReliabilityDot level={profile.predictions?.reliability.clv ?? null} />
                </Kpi>
                <Kpi label={t('contacts.profile.churn')}>
                    {profile.churn.band !== null ? (
                        <Tooltip
                            content={
                                profile.predictions?.method.churn === 'heuristic'
                                    ? t('predictions.profile.heuristic')
                                    : profile.predictions?.p_alive != null
                                      ? t('predictions.profile.pAlive', { value: `${Math.round(profile.predictions.p_alive * 100)} %` })
                                      : t(`contacts.profile.churnBand.${profile.churn.band}`, { defaultValue: profile.churn.band })
                            }
                        >
                            <span tabIndex={0}>{t(`contacts.profile.churnBand.${profile.churn.band}`, { defaultValue: profile.churn.band })}</span>
                        </Tooltip>
                    ) : (
                        <Insufficient reason={profile.churn.reason ?? null} />
                    )}
                    <ReliabilityDot level={profile.predictions?.reliability.churn ?? null} />
                </Kpi>
                <Kpi label={t('contacts.profile.nextOrder')} help={t('contacts.profile.nextOrderHelp')}>
                    {profile.expected_next_order_at ? (
                        formatDate(profile.expected_next_order_at, workspace.locale, workspace.timezone)
                    ) : profile.predictions?.next_order_reason ? (
                        <Insufficient reason={profile.predictions.next_order_reason} />
                    ) : (
                        <span className="igr:text-muted-foreground">—</span>
                    )}
                    <ReliabilityDot level={profile.predictions?.reliability.edno ?? null} />
                </Kpi>
            </dl>
            <TagsEditor profile={profile} />
        </Card>
    );
}

function Kpi({ label, help, children }: { label: string; help?: string; children: ReactNode }) {
    return (
        <div className="igr:bg-card igr:px-4 igr:py-3">
            <dt className="igr:flex igr:items-center igr:gap-1 igr:text-xs igr:text-muted-foreground">
                {label}
                {help && (
                    <Tooltip content={help}>
                        <button type="button" className="igr:rounded igr:outline-none igr:focus-visible:ring-2 igr:focus-visible:ring-ring" aria-label={help}>
                            <Info className="igr:size-3" />
                        </button>
                    </Tooltip>
                )}
            </dt>
            <dd className="igr:mt-1 igr:text-[15px] igr:font-semibold igr:tabular-nums">{children}</dd>
        </div>
    );
}

/** Confiabilidad del modelo (Inteligencia → Predicciones), junto a la cifra. */
function ReliabilityDot({ level }: { level: 'high' | 'medium' | 'low' | null }) {
    const { t } = useTranslation();
    if (level === null) return null;
    const label = t('predictions.profile.reliability', { level: t(`predictions.reliability.${level}`) });
    return (
        <Tooltip content={label}>
            <span tabIndex={0} className="igr:ml-1.5 igr:inline-flex igr:items-center igr:rounded igr:border igr:px-1 igr:text-[10px] igr:font-normal igr:text-muted-foreground">
                {t(`predictions.reliability.${level}`)}
                <span className="igr:sr-only">{label}</span>
            </span>
        </Tooltip>
    );
}

function Insufficient({ reason }: { reason: string | null }) {
    const { t } = useTranslation();
    return (
        <Tooltip content={reason ?? t('contacts.profile.insufficientHelp')}>
            <span tabIndex={0} className="igr:inline-flex igr:items-center igr:gap-1 igr:text-[13px] igr:font-normal igr:text-muted-foreground">
                {t('contacts.profile.insufficient')}
                <Info className="igr:size-3" aria-hidden="true" />
                <span className="igr:sr-only">{reason}</span>
            </span>
        </Tooltip>
    );
}

function TagsEditor({ profile }: { profile: ContactProfile }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const can = useContactAbilities();
    const update = useUpdateContact(workspace.id, profile.id);
    const [adding, setAdding] = useState(false);
    const [value, setValue] = useState('');

    const save = (tags: string[]) =>
        update.mutate(
            { tags },
            {
                onError: (error) => toast.error(errorMessage(error, t)),
            },
        );

    const commit = () => {
        const next = addTag(profile.tags, value);
        setValue('');
        setAdding(false);
        if (next.length !== profile.tags.length) {
            save(next);
        }
    };

    if (!can.edit && profile.tags.length === 0) {
        return null;
    }

    return (
        <div className="igr:flex igr:flex-wrap igr:items-center igr:gap-1.5 igr:border-t igr:px-4 igr:py-2.5">
            <span className="igr:mr-1 igr:text-xs igr:text-muted-foreground">{t('contacts.profile.tags')}</span>
            {profile.tags.map((tag) => (
                <Badge key={tag} variant="outline" className="igr:gap-0.5 igr:pr-1 igr:text-foreground">
                    {tag}
                    {can.edit && (
                        <button
                            type="button"
                            className="igr:ml-0.5 igr:rounded-full igr:p-0.5 igr:text-muted-foreground igr:outline-none igr:hover:bg-accent igr:hover:text-foreground igr:focus-visible:ring-2 igr:focus-visible:ring-ring"
                            aria-label={t('contacts.profile.removeTag', { tag })}
                            disabled={update.isPending}
                            onClick={() => save(profile.tags.filter((x) => x !== tag))}
                        >
                            <X className="igr:size-3" />
                        </button>
                    )}
                </Badge>
            ))}
            {profile.tags.length === 0 && !adding && <span className="igr:text-xs igr:text-muted-foreground">{t('contacts.profile.noTags')}</span>}
            {can.edit &&
                (adding ? (
                    <Input
                        autoFocus
                        className="igr:h-6 igr:w-40 igr:text-xs"
                        maxLength={40}
                        aria-label={t('contacts.profile.newTag')}
                        placeholder={t('contacts.profile.newTag')}
                        value={value}
                        onChange={(e) => setValue(e.target.value)}
                        onBlur={commit}
                        onKeyDown={(e) => {
                            if (e.key === 'Enter') {
                                e.preventDefault();
                                commit();
                            } else if (e.key === 'Escape') {
                                setValue('');
                                setAdding(false);
                            }
                        }}
                    />
                ) : (
                    <Button variant="ghost" size="sm" className="igr:h-6 igr:px-1.5 igr:text-xs" onClick={() => setAdding(true)}>
                        <Plus />
                        {t('contacts.profile.addTag')}
                    </Button>
                ))}
        </div>
    );
}
