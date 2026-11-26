import { zodResolver } from '@hookform/resolvers/zod';
import { useQueryClient } from '@tanstack/react-query';
import { Briefcase, Building2, Clock, Palette, Sparkles, Store } from 'lucide-react';
import { type ReactNode, type SyntheticEvent, useState } from 'react';
import { useForm } from 'react-hook-form';
import { useTranslation } from 'react-i18next';
import { z } from 'zod';
import { keys, useUpdateWorkspace } from '@/api/queries';
import { Button } from '@/components/ui/button';
import { Callout } from '@/components/ui/callout';
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import { IconTile, type IconTone } from '@/components/ui/icon-tile';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ErrorState, FieldError, PageHeader } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { WorkspaceFields, type WorkspaceFormValues } from '@/features/onboarding/WorkspaceFields';
import { useUpdateOnboarding } from '@/features/onboarding/api';
import type { BusinessType, Vertical } from '@/features/strategies/types';
import { useBusinessProfile, useUpdateBusinessProfile } from '@/features/templates/api';
import { useApplyVertical, useVerticals } from '@/features/verticals/api';
import type { VerticalProfile } from '@/features/verticals/types';
import { useCan, useWorkspace } from '@/hooks/useWorkspace';
import { isApiError } from '@/lib/api';
import { cn } from '@/lib/cn';
import { applyServerErrors } from '@/lib/forms';
import { toast } from '@/stores/toast';
import { useSaveBusinessHours } from './api';
import { DAYS, type Hours, hoursFromSettings, verticalsFor } from './logic';

/**
 * Configuración → Tu negocio: a qué te dedicas (tipo de negocio y vertical, con lo que cambia), los datos del negocio
 * (país, moneda, zona e idioma, y los legales), el horario de atención (lo usa el agente con IA) y tu marca (el pie de
 * los correos y las plantillas). Cada bloque se guarda por su cuenta.
 */
export function WorkspaceSettingsPage() {
    const { t } = useTranslation();
    const canManage = useCan('manageWorkspace');
    return (
        <>
            <PageHeader title={t('settings.title')} description={t('business.description')} />
            {!canManage ? <Callout tone="neutral" className="igr:mb-5" title={t('settings.readOnly')} /> : null}
            <div className="igr:max-w-4xl igr:space-y-6">
                <BusinessTypeCard />
                <DetailsCard />
                <HoursCard />
                <BrandCard />
            </div>
        </>
    );
}

function Block({ icon, tone, title, description, children, footer, id }: { icon: typeof Store; tone: IconTone; title: ReactNode; description: ReactNode; children: ReactNode; footer?: ReactNode; id?: string }) {
    return (
        <Card id={id} className="igr:scroll-mt-6">
            <CardHeader className="igr:flex-row igr:items-start igr:gap-3">
                <IconTile icon={icon} tone={tone} />
                <div className="igr:min-w-0">
                    <CardTitle>{title}</CardTitle>
                    <CardDescription className="igr:mt-0.5">{description}</CardDescription>
                </div>
            </CardHeader>
            <CardContent className="igr:pt-3">{children}</CardContent>
            {footer ? <CardFooter className="igr:justify-end">{footer}</CardFooter> : null}
        </Card>
    );
}

/* ——— A qué te dedicas ——— */

const TYPES: BusinessType[] = ['store', 'services', 'both'];

function BusinessTypeCard() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const canManage = useCan('manageWorkspace');
    const verticals = useVerticals(workspace.id);
    const qc = useQueryClient();
    const update = useUpdateOnboarding(workspace.id);
    const apply = useApplyVertical(workspace.id);
    const current = verticals.data?.current;
    const [type, setType] = useState<BusinessType | null>(null);
    const [vertical, setVertical] = useState<Vertical | null>(null);
    const chosenType = type ?? workspace.business_type ?? current?.business_type ?? 'store';
    const options = verticalsFor(verticals.data?.data ?? [], chosenType);
    const chosenVertical = vertical ?? (current && options.some((v) => v.key === current.vertical) ? current.vertical : (options[0]?.key ?? null));
    const profile = verticals.data?.data.find((v) => v.key === chosenVertical);
    const dirty = current !== undefined && (chosenType !== (workspace.business_type ?? current.business_type) || chosenVertical !== current.vertical || !current.saved);

    const save = async () => {
        if (chosenVertical === null) return;
        try {
            await update.mutateAsync({ business_type: chosenType, vertical: chosenVertical });
            // Los valores de la vertical (embudo de leads e industria de las plantillas): no pisan lo personalizado.
            if (chosenVertical !== current?.vertical || current.applied === null) await apply.mutateAsync(chosenVertical);
            await qc.invalidateQueries({ queryKey: keys.me });
            setType(null);
            setVertical(null);
            toast.success(t('business.type.saved'));
        } catch (error) {
            toast.error(t('business.type.error'), isApiError(error) ? error.message : undefined);
        }
    };

    return (
        <Block icon={Briefcase} tone="violet" title={t('business.type.title')} description={t('business.type.description')}
            footer={canManage ? (
                <Button onClick={() => void save()} loading={update.isPending || apply.isPending} disabled={!dirty || chosenVertical === null}>
                    {t('business.type.save')}
                </Button>
            ) : undefined}
        >
            {verticals.isError ? (
                <ErrorState error={verticals.error} onRetry={() => void verticals.refetch()} />
            ) : verticals.isPending ? (
                <div className="igr:space-y-3">
                    <Skeleton className="igr:h-20" />
                    <Skeleton className="igr:h-32" />
                </div>
            ) : (
                <fieldset disabled={!canManage} className="igr:space-y-5">
                    <div role="radiogroup" aria-label={t('business.type.kind')} className="igr:grid igr:gap-2 igr:sm:grid-cols-3">
                        {TYPES.map((k) => (
                            <Choice key={k} name="business-type" checked={chosenType === k} onSelect={() => { setType(k); setVertical(null); }} title={t(`business.type.kinds.${k}.title`)} help={t(`business.type.kinds.${k}.help`)} />
                        ))}
                    </div>
                    <div>
                        <p className="igr:mb-2 igr:text-support igr:font-medium">{t('business.type.vertical')}</p>
                        <div role="radiogroup" aria-label={t('business.type.vertical')} className="igr:grid igr:gap-2 igr:sm:grid-cols-2 igr:lg:grid-cols-3">
                            {options.map((v) => (
                                <Choice key={v.key} name="vertical" checked={chosenVertical === v.key} onSelect={() => setVertical(v.key)} title={v.name} help={v.examples} />
                            ))}
                        </div>
                    </div>
                    {profile && dirty ? <WhatChanges profile={profile} /> : null}
                </fieldset>
            )}
        </Block>
    );
}

/** Opción en tarjeta (radio): título y una línea de ayuda; el radio nativo da el teclado y el lector de pantalla. */
function Choice({ name, checked, onSelect, title, help }: { name: string; checked: boolean; onSelect: () => void; title: string; help?: string }) {
    return (
        <label
            className={cn(
                'igr:flex igr:cursor-pointer igr:gap-2.5 igr:rounded-lg igr:border igr:p-3 igr:transition-colors igr:has-[:focus-visible]:ring-2 igr:has-[:focus-visible]:ring-ring igr:has-[:disabled]:cursor-default',
                checked ? 'igr:border-primary igr:bg-brand-soft/50' : 'igr:hover:bg-accent/50',
            )}
        >
            <input type="radio" name={name} checked={checked} onChange={onSelect} className="igr:mt-0.5 igr:accent-primary" />
            <span className="igr:min-w-0">
                <span className="igr:block igr:text-support igr:font-medium">{title}</span>
                {help ? <span className="igr:mt-0.5 igr:block igr:text-xs igr:text-muted-foreground">{help}</span> : null}
            </span>
        </label>
    );
}

/** El aviso de lo que cambia al guardar otro tipo de negocio o vertical (antes de guardar). */
function WhatChanges({ profile }: { profile: VerticalProfile }) {
    const { t } = useTranslation();
    const store = profile.business_type === 'store';
    const items = [
        t('business.type.changes.strategies'),
        store ? t('business.type.changes.store') : t('business.type.changes.menu'),
        profile.pipeline ? t('business.type.changes.pipeline', { stages: profile.pipeline.stages.map((s) => s.label).join(' → ') }) : null,
        !store ? t('business.type.changes.words', { clients: profile.terms.clients }) : null,
        t('business.type.changes.home'),
    ].filter((x): x is string => x !== null);
    return (
        <Callout tone="brand" icon={Sparkles} title={t('business.type.changes.title')}>
            <ul className="igr:mt-1 igr:list-disc igr:space-y-0.5 igr:pl-4">
                {items.map((x) => (
                    <li key={x}>{x}</li>
                ))}
            </ul>
            <p className="igr:mt-2">{t('business.type.changes.safe')}</p>
        </Callout>
    );
}

/* ——— Datos del negocio ——— */

function DetailsCard() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const canManage = useCan('manageWorkspace');
    const update = useUpdateWorkspace(workspace.id);
    const [formError, setFormError] = useState<string | null>(null);
    const schema = z.object({
        name: z.string().trim().min(2, t('validation.min', { count: 2 })).max(120),
        country: z.string(),
        timezone: z.string(),
        currency: z.string(),
        locale: z.string(),
        industry: z.string(),
        legal_name: z.string().max(255).optional(),
        privacy_policy_url: z.union([z.literal(''), z.url(t('validation.url'))]).optional(),
    });
    const form = useForm<WorkspaceFormValues>({
        resolver: zodResolver(schema),
        values: {
            name: workspace.name,
            country: workspace.country,
            timezone: workspace.timezone,
            currency: workspace.currency,
            locale: workspace.locale,
            industry: workspace.industry ?? '',
            legal_name: workspace.legal_name ?? '',
            privacy_policy_url: workspace.privacy_policy_url ?? '',
        },
    });
    const onSubmit = form.handleSubmit(async (values) => {
        setFormError(null);
        try {
            await update.mutateAsync({
                ...values,
                industry: values.industry === '' ? null : values.industry,
                legal_name: values.legal_name ? values.legal_name : null,
                privacy_policy_url: values.privacy_policy_url ? values.privacy_policy_url : null,
            });
            toast.success(t('settings.saved'));
        } catch (error) {
            setFormError(applyServerErrors(error, form.setError, ['name', 'privacy_policy_url', 'legal_name'], t('errors.generic')));
        }
    });
    const errors = form.formState.errors;

    return (
        <form onSubmit={(e) => void onSubmit(e)} noValidate>
            <Block
                icon={Building2}
                tone="indigo"
                title={t('business.details.title')}
                description={t('settings.generalHelp')}
                footer={
                    canManage ? (
                        <div className="igr:flex igr:w-full igr:flex-wrap igr:items-center igr:justify-between igr:gap-2">
                            <span className="igr:text-support igr:text-destructive">{formError}</span>
                            <Button type="submit" loading={form.formState.isSubmitting} disabled={!form.formState.isDirty}>
                                {t('business.details.save')}
                            </Button>
                        </div>
                    ) : undefined
                }
            >
                <div className="igr:space-y-5">
                    <WorkspaceFields form={form} disabled={!canManage} />
                    <fieldset disabled={!canManage} className="igr:grid igr:gap-4 igr:border-t igr:pt-5 igr:sm:grid-cols-2">
                        <legend className="igr:sr-only">{t('settings.legal')}</legend>
                        <div className="igr:sm:col-span-2">
                            <p className="igr:text-support igr:font-medium">{t('settings.legal')}</p>
                            <p className="igr:text-support igr:text-muted-foreground">{t('settings.legalHelp')}</p>
                        </div>
                        <div className="igr:space-y-1.5">
                            <Label htmlFor="legal_name">{t('settings.legalName')}</Label>
                            <Input id="legal_name" {...form.register('legal_name')} />
                            <FieldError message={errors.legal_name?.message} />
                        </div>
                        <div className="igr:space-y-1.5">
                            <Label htmlFor="privacy">{t('settings.privacyUrl')}</Label>
                            <Input id="privacy" type="url" placeholder="https://" aria-invalid={errors.privacy_policy_url ? true : undefined} {...form.register('privacy_policy_url')} />
                            <FieldError message={errors.privacy_policy_url?.message} />
                        </div>
                    </fieldset>
                </div>
            </Block>
        </form>
    );
}

/* ——— Horario de atención ——— */

function HoursCard() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const canManage = useCan('manageWorkspace');
    const update = useSaveBusinessHours(workspace.id);
    const saved = hoursFromSettings(workspace.settings);
    // Lo guardado manda hasta que la persona cambia algo (un borrador); al guardar, vuelve a lo guardado.
    const [draft, setDraft] = useState<Hours | null>(null);
    const hours = draft ?? saved.hours;
    const setHours = (fn: (h: Hours) => Hours) => setDraft(fn(hours));
    const [errors, setErrors] = useState<Record<string, string>>({});
    const dirty = !saved.defined || (draft !== null && JSON.stringify(draft) !== JSON.stringify(saved.hours));
    const toggleDay = (d: number) => setHours((h) => ({ ...h, days: h.days.includes(d) ? h.days.filter((x) => x !== d) : [...h.days, d].sort((a, b) => a - b) }));

    const submit = (e: SyntheticEvent) => {
        e.preventDefault();
        setErrors({});
        update.mutate(hours, {
            onSuccess: () => {
                setDraft(null);
                toast.success(t('business.hours.saved'));
            },
            onError: (err) => {
                if (isApiError(err, 'validation')) setErrors(err.fieldErrors);
                toast.error(t('business.hours.error'), isApiError(err) ? err.message : undefined);
            },
        });
    };

    return (
        <form onSubmit={submit}>
            <Block
                id="horario"
                icon={Clock}
                tone="sky"
                title={t('business.hours.title')}
                description={t('business.hours.description')}
                footer={
                    canManage ? (
                        <Button type="submit" loading={update.isPending} disabled={!dirty || hours.days.length === 0}>
                            {t('business.hours.save')}
                        </Button>
                    ) : undefined
                }
            >
                <fieldset disabled={!canManage} className="igr:space-y-4">
                    {!saved.defined ? <p className="igr:text-support igr:text-muted-foreground">{t('business.hours.notSet')}</p> : null}
                    <div>
                        <p className="igr:mb-2 igr:text-support igr:font-medium" id="dias-atencion">
                            {t('business.hours.days')}
                        </p>
                        <div className="igr:flex igr:flex-wrap igr:gap-1.5" role="group" aria-labelledby="dias-atencion">
                            {DAYS.map((d) => (
                                <button
                                    key={d}
                                    type="button"
                                    aria-pressed={hours.days.includes(d)}
                                    onClick={() => toggleDay(d)}
                                    className={cn(
                                        'igr:h-9 igr:min-w-12 igr:rounded-full igr:border igr:px-3 igr:text-support igr:font-medium igr:transition-colors igr:outline-none igr:focus-visible:ring-2 igr:focus-visible:ring-ring igr:disabled:opacity-60',
                                        hours.days.includes(d) ? 'igr:border-primary igr:bg-primary igr:text-primary-foreground' : 'igr:bg-card igr:text-muted-foreground igr:hover:bg-accent',
                                    )}
                                >
                                    <span aria-hidden>{t(`business.hours.short.${String(d)}`)}</span>
                                    <span className="igr:sr-only">{t(`agent.daysLong.${String(d)}`, { defaultValue: t(`business.hours.short.${String(d)}`) })}</span>
                                </button>
                            ))}
                        </div>
                        <FieldError message={errors['business_hours.days']} />
                    </div>
                    <div className="igr:grid igr:max-w-sm igr:grid-cols-2 igr:gap-3">
                        <div className="igr:space-y-1.5">
                            <Label htmlFor="hours-start">{t('business.hours.start')}</Label>
                            <Input id="hours-start" type="time" value={hours.start} onChange={(e) => setHours((h) => ({ ...h, start: e.target.value }))} />
                        </div>
                        <div className="igr:space-y-1.5">
                            <Label htmlFor="hours-end">{t('business.hours.end')}</Label>
                            <Input id="hours-end" type="time" value={hours.end} onChange={(e) => setHours((h) => ({ ...h, end: e.target.value }))} />
                            <FieldError message={errors['business_hours.end']} />
                        </div>
                    </div>
                    <p className="igr:text-xs igr:text-muted-foreground">{t('business.hours.uses', { timezone: workspace.timezone.replace(/_/g, ' ') })}</p>
                </fieldset>
            </Block>
        </form>
    );
}

/* ——— Tu marca y contacto ——— */

function BrandCard() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const canManage = useCan('manageWorkspace');
    const canSee = useCan('viewDashboards');
    const profile = useBusinessProfile(workspace.id);
    const save = useUpdateBusinessProfile(workspace.id);
    const [draft, setDraft] = useState<Record<string, string> | null>(null);
    const values = draft ?? (profile.data as Record<string, string> | undefined) ?? {};
    const field = (key: 'name' | 'logo_url' | 'address' | 'contact' | 'whatsapp') => values[key] ?? '';
    const set = (key: string, value: string) => setDraft({ ...values, [key]: value });
    const errors = isApiError(save.error, 'validation') ? save.error.fieldErrors : {};
    if (!canSee) return null;

    return (
        <form
            onSubmit={(e) => {
                e.preventDefault();
                save.mutate(values, {
                    onSuccess: () => {
                        setDraft(null);
                        toast.success(t('business.brand.saved'));
                    },
                });
            }}
        >
            <Block
                icon={Palette}
                tone="rose"
                title={t('business.brand.title')}
                description={t('business.brand.description')}
                footer={
                    canManage ? (
                        <Button type="submit" loading={save.isPending} disabled={draft === null}>
                            {t('business.brand.save')}
                        </Button>
                    ) : undefined
                }
            >
                {profile.isError ? (
                    <ErrorState error={profile.error} onRetry={() => void profile.refetch()} />
                ) : profile.isPending ? (
                    <Skeleton className="igr:h-40" />
                ) : (
                    <fieldset disabled={!canManage} className="igr:grid igr:gap-4 igr:sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
                        <div className="igr:space-y-1.5">
                            <Label htmlFor="brand-name">{t('business.brand.name')}</Label>
                            <Input id="brand-name" placeholder={workspace.name} value={field('name')} onChange={(e) => set('name', e.target.value)} />
                            <FieldError message={errors.name} />
                        </div>
                        <div className="igr:space-y-1.5">
                            <Label htmlFor="brand-logo">{t('business.brand.logo')}</Label>
                            <div className="igr:flex igr:items-center igr:gap-2">
                                {field('logo_url') !== '' ? <img src={field('logo_url')} alt="" className="igr:size-9 igr:shrink-0 igr:rounded-md igr:border igr:object-contain" /> : null}
                                <Input id="brand-logo" type="url" placeholder="https://" value={field('logo_url')} onChange={(e) => set('logo_url', e.target.value)} />
                            </div>
                            <FieldError message={errors.logo_url} />
                        </div>
                        <div className="igr:space-y-1.5 igr:sm:col-span-2">
                            <Label htmlFor="brand-address">{t('business.brand.address')}</Label>
                            <Input id="brand-address" placeholder={t('business.brand.addressPlaceholder')} value={field('address')} onChange={(e) => set('address', e.target.value)} />
                            <FieldError message={errors.address} />
                        </div>
                        <div className="igr:space-y-1.5">
                            <Label htmlFor="brand-contact">{t('business.brand.contact')}</Label>
                            <Input id="brand-contact" placeholder={t('business.brand.contactPlaceholder')} value={field('contact')} onChange={(e) => set('contact', e.target.value)} />
                            <FieldError message={errors.contact} />
                        </div>
                        <div className="igr:space-y-1.5">
                            <Label htmlFor="brand-whatsapp">{t('business.brand.whatsapp')}</Label>
                            <Input id="brand-whatsapp" inputMode="tel" placeholder={t('business.brand.whatsappPlaceholder')} value={field('whatsapp')} onChange={(e) => set('whatsapp', e.target.value)} />
                            <FieldError message={errors.whatsapp} />
                        </div>
                        <p className="igr:text-xs igr:text-muted-foreground igr:sm:col-span-2">{t('business.brand.help')}</p>
                    </fieldset>
                )}
            </Block>
        </form>
    );
}
