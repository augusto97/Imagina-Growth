import { Eye, Lock } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { ChoiceCard } from '@/components/ui/choice-card';
import { Input, Select } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { QuoteCaptureSettings } from './types';

/**
 * Cómo se muestra el cotizador: si el precio se ve a cambio del contacto (lo recomendado: cada cotización es un lead) o
 * al instante, qué datos pide, con qué casillas de consentimiento (siempre sin marcar), el WhatsApp del negocio para
 * «Hablar por WhatsApp», el sitio y los textos.
 */
export function DisplaySettings({
    capture,
    onChange,
    sites,
    siteId,
    onSite,
}: {
    capture: QuoteCaptureSettings;
    onChange: (capture: QuoteCaptureSettings) => void;
    sites: { id: string; name: string }[];
    siteId: string;
    onSite: (id: string) => void;
}) {
    const { t } = useTranslation();
    const set = <K extends keyof QuoteCaptureSettings>(k: K, v: QuoteCaptureSettings[K]) => onChange({ ...capture, [k]: v });
    const toggle = <T extends string>(list: readonly T[], value: T, on: boolean): T[] =>
        on ? [...list.filter((x) => x !== value), value] : list.filter((x) => x !== value);

    return (
        <div className="igr:space-y-5">
            <fieldset className="igr:space-y-2">
                <legend className="igr:mb-2 igr:text-sm igr:font-semibold">{t('quotes.wizard.when')}</legend>
                <div className="igr:grid igr:gap-2 igr:sm:grid-cols-2">
                    <ChoiceCard
                        name="qw-capture"
                        value="required"
                        checked={capture.required}
                        onChange={() => set('required', true)}
                        icon={Lock}
                        tone="indigo"
                        title={t('quotes.wizard.whenContact')}
                        description={t('quotes.wizard.whenContactHelp')}
                        badge={
                            <span className="igr:rounded-full igr:bg-success/12 igr:px-2 igr:py-0.5 igr:text-caption igr:font-medium igr:text-success">
                                {t('quotes.wizard.recommended')}
                            </span>
                        }
                    />
                    <ChoiceCard
                        name="qw-capture"
                        value="open"
                        checked={!capture.required}
                        onChange={() => set('required', false)}
                        icon={Eye}
                        tone="sky"
                        title={t('quotes.wizard.whenOpen')}
                        description={t('quotes.wizard.whenOpenHelp')}
                    />
                </div>
            </fieldset>

            {capture.required ? (
                <div className="igr:space-y-3 igr:rounded-xl igr:border igr:p-3">
                    <fieldset>
                        <legend className="igr:text-[13px] igr:font-medium">{t('quotes.wizard.askFor')}</legend>
                        <div className="igr:mt-1.5 igr:flex igr:flex-wrap igr:gap-x-4 igr:gap-y-2 igr:text-[13px]">
                            {(['first_name', 'phone', 'email', 'last_name'] as const).map((f) => (
                                <label key={f} className="igr:flex igr:items-center igr:gap-1.5">
                                    <input
                                        type="checkbox"
                                        checked={capture.fields.includes(f)}
                                        onChange={(e) => set('fields', toggle(capture.fields, f, e.target.checked))}
                                    />
                                    {t(`quotes.contactField.${f}`)}
                                </label>
                            ))}
                        </div>
                    </fieldset>
                    <div className="igr:grid igr:gap-3 igr:sm:grid-cols-2">
                        <div>
                            <Label htmlFor="qw-require" className="igr:text-xs">
                                {t('quotes.editor.require')}
                            </Label>
                            <Select
                                id="qw-require"
                                className="igr:mt-1"
                                value={capture.require}
                                onChange={(e) => set('require', e.target.value as QuoteCaptureSettings['require'])}
                            >
                                {(['phone_or_email', 'phone', 'email'] as const).map((r) => (
                                    <option key={r} value={r}>
                                        {t(`quotes.require.${r}`)}
                                    </option>
                                ))}
                            </Select>
                        </div>
                        <fieldset>
                            <legend className="igr:text-xs igr:font-medium">{t('quotes.editor.consents')}</legend>
                            <div className="igr:mt-2 igr:flex igr:flex-wrap igr:gap-x-4 igr:gap-y-2 igr:text-[13px]">
                                {(['whatsapp', 'email', 'sms'] as const).map((c) => (
                                    <label key={c} className="igr:flex igr:items-center igr:gap-1.5">
                                        <input
                                            type="checkbox"
                                            checked={capture.consent_channels.includes(c)}
                                            onChange={(e) => set('consent_channels', toggle(capture.consent_channels, c, e.target.checked))}
                                        />
                                        {t(`quotes.channel.${c}`)}
                                    </label>
                                ))}
                            </div>
                            <p className="igr:mt-1 igr:text-caption igr:text-muted-foreground">{t('quotes.wizard.consentsHelp')}</p>
                        </fieldset>
                    </div>
                </div>
            ) : null}

            <div className="igr:grid igr:gap-3 igr:sm:grid-cols-2">
                <div>
                    <Label htmlFor="qw-wa" className="igr:text-xs">
                        {t('quotes.editor.whatsapp')}
                    </Label>
                    <Input
                        id="qw-wa"
                        className="igr:mt-1"
                        inputMode="numeric"
                        placeholder={t('quotes.editor.whatsappPlaceholder')}
                        value={capture.whatsapp_number ?? ''}
                        onChange={(e) => set('whatsapp_number', e.target.value.replace(/\D/g, '') || null)}
                    />
                    <p className="igr:mt-1 igr:text-caption igr:text-muted-foreground">{t('quotes.wizard.whatsappHelp')}</p>
                </div>
                {sites.length > 0 ? (
                    <div>
                        <Label htmlFor="qw-site" className="igr:text-xs">
                            {t('quotes.editor.site')}
                        </Label>
                        <Select id="qw-site" className="igr:mt-1" value={siteId} onChange={(e) => onSite(e.target.value)}>
                            <option value="">{t('quotes.editor.allSites')}</option>
                            {sites.map((s) => (
                                <option key={s.id} value={s.id}>
                                    {s.name}
                                </option>
                            ))}
                        </Select>
                    </div>
                ) : null}
            </div>

            <div className="igr:grid igr:gap-3 igr:sm:grid-cols-2">
                {(capture.required ? (['title', 'cta'] as const) : (['cta'] as const)).map((k) => (
                    <div key={k}>
                        <Label htmlFor={`qw-${k}`} className="igr:text-xs">
                            {t(`quotes.editor.texts.${k}`)}
                        </Label>
                        <Input
                            id={`qw-${k}`}
                            className="igr:mt-1"
                            maxLength={200}
                            value={capture[k] ?? ''}
                            placeholder={t(`quotes.editor.texts.${k}Placeholder`)}
                            onChange={(e) => set(k, e.target.value || null)}
                        />
                    </div>
                ))}
            </div>
        </div>
    );
}
