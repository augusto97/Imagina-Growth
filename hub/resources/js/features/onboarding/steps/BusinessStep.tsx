import { Briefcase, Car, Building, GraduationCap, HeartPulse, Hotel, House, Store, Combine, CircleEllipsis, type LucideIcon } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useUpdateWorkspace } from '@/api/queries';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { ChoiceCard } from '@/components/ui/choice-card';
import { Select } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Callout } from '@/components/ui/callout';
import type { BusinessType, Vertical } from '@/features/strategies/types';
import { useWorkspace } from '@/hooks/useWorkspace';
import { COUNTRIES, COUNTRY_DEFAULTS, CURRENCIES, TIMEZONES } from '../workspaceOptions';
import { businessChoice, preselectedVertical } from '../flow';
import { StepFrame } from '../StepFrame';
import type { Onboarding, OnboardingUpdate } from '../types';

/*
 * Paso 1 (docs/diseno/rediseno-v2.md §4.2 y §11.1): qué tipo de negocio es (tienda online, servicios o las dos cosas)
 * y, si no es solo tienda, a qué se dedica (las 9 verticales de `GET onboarding`, con la sugerida marcada). País,
 * moneda y zona horaria vienen precargados del espacio de trabajo y se cambian aquí mismo si hace falta.
 */

const TYPES: { key: BusinessType; icon: LucideIcon; tone: 'indigo' | 'emerald' | 'violet' }[] = [
    { key: 'store', icon: Store, tone: 'indigo' },
    { key: 'services', icon: Briefcase, tone: 'emerald' },
    { key: 'both', icon: Combine, tone: 'violet' },
];

const VERTICAL_ICONS: Record<Vertical, LucideIcon> = {
    store: Store,
    professional: Briefcase,
    health: HeartPulse,
    education: GraduationCap,
    real_estate: House,
    hospitality: Hotel,
    automotive: Car,
    b2b: Building,
    other: CircleEllipsis,
};

export function BusinessStep({ state, save, busy }: { state: Onboarding; save: (changes: OnboardingUpdate) => Promise<void>; busy: boolean }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const updateWorkspace = useUpdateWorkspace(workspace.id);
    const [type, setType] = useState<BusinessType>(state.business_type ?? state.suggested_business_type);
    const [vertical, setVertical] = useState<Vertical | null>(preselectedVertical(state));
    const [region, setRegion] = useState({ country: state.workspace.country, currency: state.workspace.currency, timezone: state.workspace.timezone });
    const [editRegion, setEditRegion] = useState(false);
    const needsVertical = type !== 'store';
    const verticals = state.verticals.filter((v) => v.key !== 'store');
    const regionChanged = region.country !== state.workspace.country || region.currency !== state.workspace.currency || region.timezone !== state.workspace.timezone;

    const next = async () => {
        if (regionChanged) {
            await updateWorkspace.mutateAsync(region);
        }
        const choice = businessChoice(type, vertical);
        await save({ business_type: choice.business_type, ...(choice.vertical ? { vertical: choice.vertical } : {}), current_step: 'connect' });
    };

    return (
        <StepFrame
            step="business"
            title={t('onboarding.wizard.business.title')}
            description={t('onboarding.wizard.business.description')}
            onNext={() => void next()}
            nextDisabled={needsVertical && vertical === null}
            busy={busy || updateWorkspace.isPending}
        >
            <fieldset>
                <legend className="igr:text-body igr:font-semibold igr:text-foreground">{t('onboarding.wizard.business.typeLegend')}</legend>
                <div className="igr:mt-3 igr:grid igr:gap-2.5">
                    {TYPES.map((o) => (
                        <ChoiceCard
                            key={o.key}
                            name="business-type"
                            value={o.key}
                            checked={type === o.key}
                            onChange={(v) => setType(v as BusinessType)}
                            icon={o.icon}
                            tone={o.tone}
                            title={t(`onboarding.wizard.business.types.${o.key}.title`)}
                            description={t(`onboarding.wizard.business.types.${o.key}.description`)}
                        />
                    ))}
                </div>
            </fieldset>

            {needsVertical ? (
                <fieldset className="igr:mt-8">
                    <legend className="igr:text-body igr:font-semibold igr:text-foreground">{t('onboarding.wizard.business.verticalLegend')}</legend>
                    <p className="igr:mt-1 igr:text-support igr:text-muted-foreground">{t('onboarding.wizard.business.verticalHint')}</p>
                    <div className="igr:mt-3 igr:grid igr:gap-2.5 igr:sm:grid-cols-2">
                        {verticals.map((v) => (
                            <ChoiceCard
                                key={v.key}
                                name="vertical"
                                value={v.key}
                                size="sm"
                                checked={vertical === v.key}
                                onChange={(val) => setVertical(val as Vertical)}
                                icon={VERTICAL_ICONS[v.key]}
                                tone="slate"
                                title={v.name}
                                description={v.examples}
                                badge={v.key === state.suggested_vertical && state.vertical === null ? <Badge variant="brand">{t('onboarding.wizard.business.suggested')}</Badge> : undefined}
                            />
                        ))}
                    </div>
                </fieldset>
            ) : null}

            <section className="igr:mt-8 igr:rounded-xl igr:border igr:bg-card igr:p-4" aria-labelledby="wizard-region">
                <div className="igr:flex igr:flex-wrap igr:items-center igr:gap-3">
                    <div className="igr:min-w-0 igr:flex-1">
                        <h2 id="wizard-region" className="igr:text-support igr:font-semibold igr:text-foreground">
                            {t('onboarding.wizard.business.region')}
                        </h2>
                        <p className="igr:mt-0.5 igr:text-support igr:text-muted-foreground">
                            {t(`countries.${region.country}`, { defaultValue: region.country })} · {t(`currencies.${region.currency}`, { defaultValue: region.currency })} · {region.timezone.replace(/_/g, ' ')}
                        </p>
                    </div>
                    {!editRegion && workspace.abilities.includes('manageWorkspace') ? (
                        <Button variant="outline" size="sm" onClick={() => setEditRegion(true)}>
                            {t('onboarding.wizard.business.changeRegion')}
                        </Button>
                    ) : null}
                </div>
                {editRegion ? (
                    <div className="igr:mt-4 igr:grid igr:gap-3 igr:sm:grid-cols-3">
                        <div className="igr:space-y-1.5">
                            <Label htmlFor="wz-country">{t('workspace.country')}</Label>
                            <Select
                                id="wz-country"
                                value={region.country}
                                onChange={(e) => {
                                    const d = COUNTRY_DEFAULTS[e.target.value];
                                    setRegion({ country: e.target.value, currency: d?.currency ?? region.currency, timezone: d?.timezone ?? region.timezone });
                                }}
                            >
                                {COUNTRIES.map((c) => (
                                    <option key={c} value={c}>
                                        {t(`countries.${c}`)}
                                    </option>
                                ))}
                            </Select>
                        </div>
                        <div className="igr:space-y-1.5">
                            <Label htmlFor="wz-currency">{t('workspace.currency')}</Label>
                            <Select id="wz-currency" value={region.currency} onChange={(e) => setRegion({ ...region, currency: e.target.value })}>
                                {CURRENCIES.map((c) => (
                                    <option key={c} value={c}>
                                        {t(`currencies.${c}`)}
                                    </option>
                                ))}
                            </Select>
                        </div>
                        <div className="igr:space-y-1.5">
                            <Label htmlFor="wz-tz">{t('workspace.timezone')}</Label>
                            <Select id="wz-tz" value={region.timezone} onChange={(e) => setRegion({ ...region, timezone: e.target.value })}>
                                {TIMEZONES.map((z) => (
                                    <option key={z} value={z}>
                                        {z.replace(/_/g, ' ')}
                                    </option>
                                ))}
                            </Select>
                        </div>
                    </div>
                ) : null}
                {updateWorkspace.isError ? (
                    <Callout tone="danger" className="igr:mt-3">
                        {t('errors.generic')}
                    </Callout>
                ) : null}
            </section>
        </StepFrame>
    );
}
