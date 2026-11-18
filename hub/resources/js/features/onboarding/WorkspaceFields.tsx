import type { UseFormReturn } from 'react-hook-form';
import { useTranslation } from 'react-i18next';
import { Input, Select } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { FieldError } from '@/components/ui/misc';
import { COUNTRIES, COUNTRY_DEFAULTS, CURRENCIES, INDUSTRIES, LOCALES, TIMEZONES } from './workspaceOptions';

export interface WorkspaceFormValues {
    name: string;
    country: string;
    timezone: string;
    currency: string;
    locale: string;
    industry: string;
    legal_name?: string;
    privacy_policy_url?: string;
}

/** Campos compartidos por el onboarding y los ajustes del espacio de trabajo. */
export function WorkspaceFields({ form, disabled = false, autoDefaults = false }: { form: UseFormReturn<WorkspaceFormValues>; disabled?: boolean; autoDefaults?: boolean }) {
    const { t } = useTranslation();
    const errors = form.formState.errors;
    const countryField = form.register('country', {
        onChange: (e: { target: { value: string } }) => {
            const defaults = COUNTRY_DEFAULTS[e.target.value];
            if (autoDefaults && defaults) {
                form.setValue('timezone', defaults.timezone);
                form.setValue('currency', defaults.currency);
                form.setValue('locale', defaults.locale);
            }
        },
    });
    return (
        <fieldset disabled={disabled} className="igr:grid igr:gap-4 igr:sm:grid-cols-2">
            <div className="igr:space-y-1.5 igr:sm:col-span-2">
                <Label htmlFor="ws-name">{t('workspace.name')}</Label>
                <Input id="ws-name" placeholder={t('workspace.namePlaceholder')} aria-invalid={errors.name ? true : undefined} {...form.register('name')} />
                <FieldError message={errors.name?.message} />
            </div>
            <div className="igr:space-y-1.5">
                <Label htmlFor="ws-country">{t('workspace.country')}</Label>
                <Select id="ws-country" {...countryField}>
                    {COUNTRIES.map((c) => (
                        <option key={c} value={c}>
                            {t(`countries.${c}`)}
                        </option>
                    ))}
                </Select>
            </div>
            <div className="igr:space-y-1.5">
                <Label htmlFor="ws-industry">{t('workspace.industry')}</Label>
                <Select id="ws-industry" {...form.register('industry')}>
                    <option value="">{t('workspace.industryNone')}</option>
                    {INDUSTRIES.map((i) => (
                        <option key={i} value={i}>
                            {t(`industries.${i}`)}
                        </option>
                    ))}
                </Select>
            </div>
            <div className="igr:space-y-1.5">
                <Label htmlFor="ws-currency">{t('workspace.currency')}</Label>
                <Select id="ws-currency" {...form.register('currency')}>
                    {CURRENCIES.map((c) => (
                        <option key={c} value={c}>
                            {c} · {t(`currencies.${c}`)}
                        </option>
                    ))}
                </Select>
            </div>
            <div className="igr:space-y-1.5">
                <Label htmlFor="ws-timezone">{t('workspace.timezone')}</Label>
                <Select id="ws-timezone" {...form.register('timezone')}>
                    {TIMEZONES.map((tz) => (
                        <option key={tz} value={tz}>
                            {tz.replace(/_/g, ' ')}
                        </option>
                    ))}
                </Select>
            </div>
            <div className="igr:space-y-1.5">
                <Label htmlFor="ws-locale">{t('workspace.locale')}</Label>
                <Select id="ws-locale" {...form.register('locale')}>
                    {LOCALES.map((l) => (
                        <option key={l} value={l}>
                            {t(`locales.${l}`)}
                        </option>
                    ))}
                </Select>
            </div>
        </fieldset>
    );
}
