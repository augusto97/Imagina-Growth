import { CalendarClock, Check, Compass, Lock, Mail, MapPin, Minus, Phone, Plus, Sparkles, Target, Wallet, X, type LucideIcon } from 'lucide-react';
import { useState, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { Avatar } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { HelpTip } from '@/components/ui/help-tip';
import { IconTile, type IconTone } from '@/components/ui/icon-tile';
import { Input } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';
import { Tooltip } from '@/components/ui/tooltip';
import { useWorkspace } from '@/hooks/useWorkspace';
import { formatDate, formatDateTime, formatMoney, formatNumber } from '@/lib/format';
import { regionName } from '@/lib/regions';
import { toast } from '@/stores/toast';
import { useContactAbilities } from '../abilities';
import { type ContactProfile, type ContactServices, useUpdateContact } from '../api';
import type { Business } from '../business';
import { errorMessage } from '../errors';
import { CONSENT_CHANNELS, addTag, rfmTone } from '../labels';
import { TEMPERATURE_TONE } from '../list/ContactRows';
import { currentLead, nextAppointment, purchaseOutlook } from './insights';

/**
 * Encabezado del perfil 360 (rediseño v2, Clientes): quién es, su valor, en qué grupo está y qué tan probable es que
 * vuelva (en palabras), cómo llegó y por qué canales se le puede escribir; las acciones a la derecha. En un negocio de
 * servicios, en lugar de compras: en qué va su proceso, su valor y su próxima cita.
 */
export function ProfileHeader({
    profile,
    business,
    services,
    servicesLoading,
    actions,
}: {
    profile: ContactProfile;
    business: Business;
    services: ContactServices | undefined;
    servicesLoading: boolean;
    actions?: ReactNode;
}) {
    const { t } = useTranslation();
    const name = profile.name !== '' ? profile.name : t('contacts.noName');
    // El departamento por su nombre, no por su código (CO-DC → Bogotá D.C.), y sin repetir la ciudad.
    const region = regionName(t, profile.region_code);
    const place = [profile.city, region !== profile.city ? region : null].filter(Boolean).join(', ');
    const lead = currentLead(services);
    const showServices = business.services && (services !== undefined || servicesLoading);

    return (
        <Card className="igr:mb-5 igr:overflow-hidden">
            <div className="igr:flex igr:flex-col igr:gap-4 igr:p-4 igr:sm:p-5 igr:lg:flex-row igr:lg:items-start">
                <div className="igr:flex igr:min-w-0 igr:flex-1 igr:items-start igr:gap-4">
                    <Avatar name={name} seed={profile.id} size="xl" className="igr:max-sm:size-12 igr:max-sm:text-base" />
                    <div className="igr:min-w-0 igr:flex-1">
                        <h1 className="igr:text-[22px] igr:leading-7 igr:font-semibold igr:tracking-tight igr:break-words igr:sm:text-title">{name}</h1>
                        <p className="igr:mt-1 igr:flex igr:flex-wrap igr:items-center igr:gap-x-3.5 igr:gap-y-1 igr:text-support igr:text-muted-foreground">
                            {(
                                [
                                    [Mail, profile.email],
                                    [Phone, profile.phone],
                                    [MapPin, place],
                                ] as const
                            )
                                .filter(([, part]) => part)
                                .map(([Icon, part]) => (
                                    <span key={String(part)} className="igr:inline-flex igr:min-w-0 igr:items-center igr:gap-1.5 igr:break-all igr:tabular-nums">
                                        <Icon className="igr:size-3.5 igr:shrink-0" aria-hidden />
                                        {part}
                                    </span>
                                ))}
                            {!profile.pii_visible && (profile.email || profile.phone) ? (
                                <Tooltip content={t('contacts.profile.piiHidden')}>
                                    <span className="igr:inline-flex igr:items-center" tabIndex={0} aria-label={t('contacts.profile.piiHidden')}>
                                        <Lock className="igr:size-3.5" />
                                    </span>
                                </Tooltip>
                            ) : null}
                        </p>
                        <div className="igr:mt-2.5 igr:flex igr:flex-wrap igr:items-center igr:gap-1.5">
                            {profile.lifecycle_stage ? <Badge variant="secondary">{t(`stages.${profile.lifecycle_stage}`, { defaultValue: profile.lifecycle_stage })}</Badge> : null}
                            {business.store && profile.rfm ? (
                                <Tooltip content={t('contacts.profile.rfmScores', { r: profile.rfm.r ?? '—', f: profile.rfm.f ?? '—', m: profile.rfm.m ?? '—' })}>
                                    <span tabIndex={0}>
                                        <Badge variant={rfmTone(profile.rfm.segment)}>{t(`contacts.rfm.${profile.rfm.segment}`, { defaultValue: profile.rfm.segment })}</Badge>
                                    </span>
                                </Tooltip>
                            ) : null}
                            {business.services && lead && lead.stage_kind === 'open' ? (
                                <>
                                    <Badge variant="outline">{lead.stage_label}</Badge>
                                    <Badge variant={TEMPERATURE_TONE[lead.temperature]}>{t(`contacts.temperature.${lead.temperature}`)}</Badge>
                                </>
                            ) : null}
                        </div>
                    </div>
                </div>
                {actions ? <div className="igr:flex igr:flex-wrap igr:items-center igr:gap-2 igr:lg:max-w-[50%] igr:lg:justify-end">{actions}</div> : null}
            </div>

            <dl className="igr:grid igr:grid-cols-2 igr:gap-px igr:border-t igr:bg-border igr:xl:grid-cols-4">
                {showServices ? <ServicesInsights services={services} /> : <StoreInsights profile={profile} />}
                <Insight icon={Compass} tone="sky" label={t('contacts.header.origin')}>
                    <OriginValue profile={profile} />
                </Insight>
            </dl>

            <div className="igr:flex igr:flex-col igr:gap-3 igr:border-t igr:px-4 igr:py-3 igr:sm:px-5 igr:md:flex-row igr:md:items-center igr:md:gap-6">
                <ConsentRow profile={profile} />
                <TagsEditor profile={profile} />
            </div>
        </Card>
    );
}

function Insight({ icon, tone, label, help, children }: { icon: LucideIcon; tone: IconTone; label: string; help?: string; children: ReactNode }) {
    return (
        <div className="igr:flex igr:min-w-0 igr:gap-3 igr:bg-card igr:px-3.5 igr:py-3 igr:sm:px-5 igr:sm:py-3.5">
            <IconTile icon={icon} tone={tone} size="sm" className="igr:mt-0.5 igr:max-sm:hidden" />
            <div className="igr:min-w-0 igr:flex-1">
                <dt className="igr:flex igr:items-center igr:gap-1 igr:text-caption igr:font-medium igr:text-muted-foreground">
                    {label}
                    {help ? <HelpTip content={help} label={label} /> : null}
                </dt>
                <dd className="igr:mt-0.5 igr:text-body">{children}</dd>
            </div>
        </div>
    );
}

function Strong({ children }: { children: ReactNode }) {
    return <span className="igr:block igr:font-semibold igr:text-foreground igr:tabular-nums">{children}</span>;
}

function Detail({ children }: { children: ReactNode }) {
    return <span className="igr:mt-0.5 igr:line-clamp-3 igr:text-support igr:text-muted-foreground">{children}</span>;
}

function StoreInsights({ profile }: { profile: ContactProfile }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const money = (v: string | null) => formatMoney(v, profile.currency, workspace.locale);
    const orders = profile.stats.orders_count;
    const outlook = purchaseOutlook(profile);
    const rfm = profile.rfm?.segment ?? null;

    return (
        <>
            <Insight icon={Wallet} tone="emerald" label={t('contacts.header.value')} help={t('contacts.profile.clvHistoricHelp')}>
                <Strong>{money(profile.clv.historic)}</Strong>
                <Detail>
                    {orders > 0 ? t('contacts.header.valueOrders', { count: orders, formatted: formatNumber(orders, workspace.locale) }) : t('contacts.header.noOrders')}
                    {profile.clv.predicted_12m !== null ? ` · ${t('contacts.header.predicted', { value: money(profile.clv.predicted_12m) })}` : ''}
                </Detail>
            </Insight>
            <Insight icon={Target} tone="violet" label={t('contacts.header.group')}>
                <Strong>{rfm ? t(`contacts.rfm.${rfm}`, { defaultValue: rfm }) : t('contacts.header.noGroup')}</Strong>
                <Detail>{rfm ? t(`contacts.rfmHelp.${rfm}`, { defaultValue: '' }) : t('contacts.header.noGroupHelp')}</Detail>
            </Insight>
            <Insight icon={Sparkles} tone={outlook.kind === 'risk' && outlook.band === 'high' ? 'rose' : 'indigo'} label={t('contacts.header.outlook')} help={t('contacts.profile.nextOrderHelp')}>
                {outlook.kind === 'next' ? (
                    <>
                        <Strong>{t('contacts.header.nextOn', { date: formatDate(outlook.at, workspace.locale, workspace.timezone) })}</Strong>
                        <Detail>{t('contacts.header.nextHelp')}</Detail>
                    </>
                ) : outlook.kind === 'risk' ? (
                    <>
                        <Strong>{t(`contacts.header.risk.${outlook.band}`)}</Strong>
                        <Detail>{t(`contacts.header.riskHelp.${outlook.band}`)}</Detail>
                    </>
                ) : outlook.kind === 'noPurchases' ? (
                    <>
                        <Strong>{t('contacts.header.firstPurchase')}</Strong>
                        <Detail>{t('contacts.header.firstPurchaseHelp')}</Detail>
                    </>
                ) : (
                    <>
                        <Strong>{t('contacts.header.unknown')}</Strong>
                        <Detail>{profile.predictions?.next_order_reason ?? t('contacts.profile.insufficientHelp')}</Detail>
                    </>
                )}
            </Insight>
        </>
    );
}

function ServicesInsights({ services }: { services: ContactServices | undefined }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    if (services === undefined) {
        return (
            <>
                {[0, 1, 2].map((i) => (
                    <div key={i} className="igr:bg-card igr:px-4 igr:py-3.5 igr:sm:px-5">
                        <Skeleton className="igr:h-3 igr:w-24" />
                        <Skeleton className="igr:mt-2 igr:h-5 igr:w-36" />
                        <Skeleton className="igr:mt-1.5 igr:h-3.5 igr:w-44" />
                    </div>
                ))}
            </>
        );
    }
    const money = (v: string | null) => formatMoney(v, services.currency, workspace.locale);
    const lead = currentLead(services);
    const next = nextAppointment(services);
    const won = Number(services.totals.won_value) > 0;
    const completed = services.totals.appointments_completed;

    return (
        <>
            <Insight icon={Target} tone="rose" label={t('contacts.header.process')}>
                {lead ? (
                    <>
                        <Strong>{lead.stage_label}</Strong>
                        <Detail>{[lead.service, lead.next_step ? t('contacts.header.nextStep', { step: lead.next_step }) : null].filter(Boolean).join(' · ') || t('contacts.header.noService')}</Detail>
                    </>
                ) : (
                    <>
                        <Strong>{t('contacts.header.noProcess')}</Strong>
                        <Detail>{t('contacts.header.noProcessHelp')}</Detail>
                    </>
                )}
            </Insight>
            <Insight icon={Wallet} tone="emerald" label={t('contacts.header.value')} help={t('contacts.header.valueServicesHelp')}>
                {won ? (
                    <>
                        <Strong>{money(services.totals.won_value)}</Strong>
                        <Detail>{t('contacts.header.wonValue')}</Detail>
                    </>
                ) : lead?.value ? (
                    <>
                        <Strong>{money(lead.value)}</Strong>
                        <Detail>{t('contacts.header.openValue')}</Detail>
                    </>
                ) : (
                    <>
                        <Strong>—</Strong>
                        <Detail>{t('contacts.header.noValue')}</Detail>
                    </>
                )}
            </Insight>
            <Insight icon={CalendarClock} tone="teal" label={t('contacts.header.appointment')}>
                {next ? (
                    <>
                        <Strong>{formatDateTime(next.starts_at, workspace.locale, workspace.timezone)}</Strong>
                        <Detail>{[next.service, t(`contacts.appointmentStatus.${next.status}`)].filter(Boolean).join(' · ')}</Detail>
                    </>
                ) : (
                    <>
                        <Strong>{t('contacts.header.noAppointment')}</Strong>
                        <Detail>
                            {completed > 0
                                ? t('contacts.header.attended', { count: completed })
                                : services.totals.appointments_no_show > 0
                                  ? t('contacts.header.noShows', { count: services.totals.appointments_no_show })
                                  : t('contacts.header.noAppointmentHelp')}
                        </Detail>
                    </>
                )}
            </Insight>
        </>
    );
}

function OriginValue({ profile }: { profile: ContactProfile }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const touch = profile.first_source;
    if (!touch?.channel) {
        return (
            <>
                <Strong>{t('contacts.header.originUnknown')}</Strong>
                <Detail>{profile.first_seen_at ? t('contacts.header.since', { date: formatDate(profile.first_seen_at, workspace.locale, workspace.timezone) }) : '—'}</Detail>
            </>
        );
    }
    return (
        <>
            <Strong>{t(`contacts.channels.${touch.channel}`, { defaultValue: touch.channel })}</Strong>
            <Detail>
                {[touch.utm_campaign, touch.at ?? profile.first_seen_at ? t('contacts.header.since', { date: formatDate(touch.at ?? profile.first_seen_at, workspace.locale, workspace.timezone) }) : null]
                    .filter(Boolean)
                    .join(' · ')}
            </Detail>
        </>
    );
}

function ConsentRow({ profile }: { profile: ContactProfile }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    return (
        <div className="igr:flex igr:flex-wrap igr:items-center igr:gap-1.5">
            <span className="igr:mr-1 igr:text-caption igr:font-medium igr:text-muted-foreground">{t('contacts.header.canReceive')}</span>
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
    );
}

function TagsEditor({ profile }: { profile: ContactProfile }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const can = useContactAbilities();
    const update = useUpdateContact(workspace.id, profile.id);
    const [adding, setAdding] = useState(false);
    const [value, setValue] = useState('');

    const save = (tags: string[]) => update.mutate({ tags }, { onError: (error) => toast.error(errorMessage(error, t)) });
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
        <div className="igr:flex igr:min-w-0 igr:flex-wrap igr:items-center igr:gap-1.5">
            <span className="igr:mr-1 igr:text-caption igr:font-medium igr:text-muted-foreground">{t('contacts.profile.tags')}</span>
            {profile.tags.map((tag) => (
                <Badge key={tag} variant="outline" className="igr:gap-0.5 igr:pr-1 igr:text-foreground">
                    {tag}
                    {can.edit ? (
                        <button
                            type="button"
                            className="igr:ml-0.5 igr:rounded-full igr:p-0.5 igr:text-muted-foreground igr:outline-none igr:hover:bg-accent igr:hover:text-foreground igr:focus-visible:ring-2 igr:focus-visible:ring-ring"
                            aria-label={t('contacts.profile.removeTag', { tag })}
                            disabled={update.isPending}
                            onClick={() => save(profile.tags.filter((x) => x !== tag))}
                        >
                            <X className="igr:size-3" />
                        </button>
                    ) : null}
                </Badge>
            ))}
            {profile.tags.length === 0 && !adding ? <span className="igr:text-caption igr:text-muted-foreground">{t('contacts.profile.noTags')}</span> : null}
            {can.edit ? (
                adding ? (
                    <Input
                        autoFocus
                        className="igr:h-7 igr:w-40 igr:text-xs"
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
                    <Button variant="ghost" size="sm" className="igr:h-7 igr:px-1.5 igr:text-xs" onClick={() => setAdding(true)}>
                        <Plus />
                        {t('contacts.profile.addTag')}
                    </Button>
                )
            ) : null}
        </div>
    );
}
