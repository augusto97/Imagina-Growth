import { ChevronDown, Cpu, Sparkles, Zap } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Callout } from '@/components/ui/callout';
import { Select } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { FieldError } from '@/components/ui/misc';
import { useWorkspace } from '@/hooks/useWorkspace';
import { isApiError } from '@/lib/api';
import { cn } from '@/lib/cn';
import {
    type AiFeatureInfo,
    type AiModelInfo,
    type AiTier,
    choice,
    effortsFor,
    factoryModel,
    overridesCount,
    tierModel,
    usd,
} from './ai-settings-model';
import { type AiTestResult, type SettingField, type SettingsSection, useAiTest } from './settings-api';
import type { Draft } from './settings-model';

/*
 * Plataforma → Ajustes → IA: el modelo y el esfuerzo de los dos grupos de funciones, lo de una función concreta
 * («Avanzado») y «Probar la IA». Los campos son del registro del hub (se guardan con el resto de la sección); aquí solo
 * se pintan con su explicación: qué hace cada modelo, cuánto cuesta y qué cambia el esfuerzo.
 */

type OnChange = (key: string, value: string | boolean | null) => void;

function useModelName() {
    const { t, i18n } = useTranslation();
    return (id: string) => (i18n.exists(`platformSettings.ai.models.${id}.name`) ? t(`platformSettings.ai.models.${id}.name`) : id);
}

export function AiModelFields({ section, draft, errors, onChange }: { section: SettingsSection; draft: Draft; errors: Record<string, string>; onChange: OnChange }) {
    const { t } = useTranslation();
    const models = section.info.models ?? [];
    const features = section.info.features ?? [];
    if (models.length === 0) return null;

    return (
        <div className="igr:space-y-4">
            <ModelGuide models={models} />
            <div className="igr:grid igr:gap-3 igr:lg:grid-cols-2">
                {(['main', 'fast'] as const).map((tier) => (
                    <TierCard key={tier} tier={tier} section={section} models={models} features={features} draft={draft} errors={errors} onChange={onChange} />
                ))}
            </div>
            <p className="igr:text-xs igr:text-muted-foreground">{t('platformSettings.ai.explain.budget')}</p>
            <AdvancedFeatures section={section} models={models} features={features} draft={draft} errors={errors} onChange={onChange} />
        </div>
    );
}

/** Qué hace cada modelo y cuánto cuesta, y qué cambia el esfuerzo, en palabras. */
function ModelGuide({ models }: { models: AiModelInfo[] }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const name = useModelName();
    return (
        <div className="igr:space-y-3 igr:rounded-lg igr:border igr:bg-muted/40 igr:p-3">
            <p className="igr:text-[13px] igr:font-medium">{t('platformSettings.ai.explain.title')}</p>
            <p className="igr:text-xs igr:text-muted-foreground">{t('platformSettings.ai.explain.model')}</p>
            <ul className="igr:grid igr:gap-2 igr:sm:grid-cols-2">
                {models.map((m) => (
                    <li key={m.id} className="igr:rounded-md igr:border igr:bg-card igr:p-2.5">
                        <p className="igr:flex igr:flex-wrap igr:items-center igr:gap-1.5 igr:text-[13px] igr:font-medium">
                            {name(m.id)}
                            {m.id === 'claude-sonnet-5-5' || m.id === 'claude-haiku-5-5' ? <Badge variant="brand">{t('platformSettings.ai.explain.recommended')}</Badge> : null}
                        </p>
                        <p className="igr:text-xs igr:text-muted-foreground">{t(`platformSettings.ai.models.${m.id}.help`, { defaultValue: '' })}</p>
                        <p className="igr:mt-1 igr:text-xs igr:tabular-nums">
                            {t('platformSettings.ai.explain.price', { input: usd(m.input_usd, workspace.locale), output: usd(m.output_usd, workspace.locale) })}
                        </p>
                    </li>
                ))}
            </ul>
            <p className="igr:text-xs igr:text-muted-foreground">{t('platformSettings.ai.explain.effort')}</p>
        </div>
    );
}

function TierCard({
    tier,
    section,
    models,
    features,
    draft,
    errors,
    onChange,
}: {
    tier: AiTier;
    section: SettingsSection;
    models: AiModelInfo[];
    features: AiFeatureInfo[];
    draft: Draft;
    errors: Record<string, string>;
    onChange: OnChange;
}) {
    const { t } = useTranslation();
    const name = useModelName();
    const modelField = section.fields.find((f) => f.key === `${tier}_model`);
    const effortField = section.fields.find((f) => f.key === `${tier}_effort`);
    if (!modelField || !effortField) return null;
    const model = tierModel(tier, section.fields, draft, features);
    const factory = factoryModel(tier, features);
    const serverModel = typeof modelField.fallback === 'string' && modelField.fallback !== '' ? modelField.fallback : null;
    const emptyModel = serverModel
        ? t('platformSettings.useServer', { value: name(serverModel) })
        : factory
          ? t('platformSettings.ai.factory', { model: name(factory) })
          : t('platformSettings.ai.factoryMixed');
    const serverEffort = typeof effortField.fallback === 'string' && effortField.fallback !== '' ? effortField.fallback : null;
    const effort = choice(effortField, draft);
    const allowed = effortsFor(models, model ?? '');
    const Icon = tier === 'main' ? Sparkles : Zap;
    const id = `ps-ai-${tier}`;

    return (
        <div className="igr:space-y-3 igr:rounded-lg igr:border igr:p-3">
            <div>
                <p className="igr:flex igr:items-center igr:gap-1.5 igr:text-[13px] igr:font-medium">
                    <Icon className="igr:size-4 igr:text-primary" aria-hidden />
                    {t(`platformSettings.ai.tiers.${tier}.title`)}
                </p>
                <p className="igr:text-xs igr:text-muted-foreground">{t(`platformSettings.ai.tiers.${tier}.description`)}</p>
            </div>
            <div className="igr:space-y-1.5">
                <Label htmlFor={`${id}-model`}>{t('platformSettings.ai.model')}</Label>
                <Select id={`${id}-model`} value={choice(modelField, draft)} onChange={(e) => onChange(modelField.key, e.target.value === '' ? null : e.target.value)}>
                    <option value="">{emptyModel}</option>
                    {models.map((m) => (
                        <option key={m.id} value={m.id}>
                            {name(m.id)}
                        </option>
                    ))}
                </Select>
                <FieldError message={errors[modelField.key]} />
            </div>
            <div className="igr:space-y-1.5">
                <Label htmlFor={`${id}-effort`}>{t('platformSettings.ai.effort')}</Label>
                <Select
                    id={`${id}-effort`}
                    value={effort}
                    aria-invalid={errors[effortField.key] ? true : undefined}
                    onChange={(e) => onChange(effortField.key, e.target.value === '' ? null : e.target.value)}
                >
                    <option value="">{serverEffort ? t('platformSettings.useServer', { value: t(`platformSettings.ai.efforts.${serverEffort}.name`) }) : t('platformSettings.ai.factoryMixed')}</option>
                    {allowed.map((e) => (
                        <option key={e} value={e}>
                            {t(`platformSettings.ai.efforts.${e}.name`)}
                        </option>
                    ))}
                </Select>
                {effort !== '' ? <p className="igr:text-xs igr:text-muted-foreground">{t(`platformSettings.ai.efforts.${effort}.help`)}</p> : null}
                <FieldError message={errors[effortField.key]} />
            </div>
        </div>
    );
}

/** Una función concreta con otro modelo o esfuerzo: plegado, porque casi nunca hace falta. */
function AdvancedFeatures({
    section,
    models,
    features,
    draft,
    errors,
    onChange,
}: {
    section: SettingsSection;
    models: AiModelInfo[];
    features: AiFeatureInfo[];
    draft: Draft;
    errors: Record<string, string>;
    onChange: OnChange;
}) {
    const { t } = useTranslation();
    const name = useModelName();
    const changed = overridesCount(section.fields, draft, features);
    const [open, setOpen] = useState(changed > 0);
    const field = (key: string): SettingField | undefined => section.fields.find((f) => f.key === key);

    return (
        <div className="igr:rounded-lg igr:border">
            <button
                type="button"
                className="igr:flex igr:w-full igr:items-center igr:gap-2 igr:px-3 igr:py-2.5 igr:text-left igr:text-[13px] igr:font-medium igr:hover:bg-accent/50"
                aria-expanded={open}
                onClick={() => setOpen((o) => !o)}
            >
                <Cpu className="igr:size-4 igr:text-muted-foreground" aria-hidden />
                <span className="igr:flex-1">{t('platformSettings.ai.advanced.title')}</span>
                {changed > 0 ? <Badge variant="info">{t('platformSettings.ai.advanced.changed', { count: changed })}</Badge> : null}
                <ChevronDown className={cn('igr:size-4 igr:transition-transform', open ? 'igr:rotate-180' : '')} aria-hidden />
            </button>
            {open ? (
                <div className="igr:space-y-2 igr:border-t igr:p-3">
                    <p className="igr:text-xs igr:text-muted-foreground">{t('platformSettings.ai.advanced.help')}</p>
                    <ul className="igr:divide-y">
                        {features.map((f) => {
                            const modelField = field(`feature_${f.key}_model`);
                            const effortField = field(`feature_${f.key}_effort`);
                            if (!modelField || !effortField) return null;
                            const group = tierModel(f.tier, section.fields, draft, features) ?? f.default_model;
                            const model = choice(modelField, draft) || group;
                            const effort = choice(effortField, draft);
                            const error = errors[modelField.key] ?? errors[effortField.key];
                            return (
                                <li key={f.key} className="igr:grid igr:gap-2 igr:py-2.5 igr:md:grid-cols-[minmax(0,1fr)_12rem_9rem] igr:md:items-center">
                                    <div className="igr:min-w-0">
                                        <p className="igr:flex igr:flex-wrap igr:items-center igr:gap-1.5 igr:text-[13px]">
                                            {t(`platformSettings.ai.features.${f.key}.name`)}
                                            <Badge variant="outline">{t(`platformSettings.ai.advanced.${f.tier}`)}</Badge>
                                        </p>
                                        <p className="igr:text-xs igr:text-muted-foreground">
                                            {t(`platformSettings.ai.features.${f.key}.help`)}{' '}
                                            {t('platformSettings.ai.now', {
                                                model: name(f.effective.model),
                                                effort: f.effective.effort ? t(`platformSettings.ai.efforts.${f.effective.effort}.name`) : t('platformSettings.ai.modelDefault'),
                                            })}
                                        </p>
                                        <FieldError message={error} />
                                    </div>
                                    <Select
                                        aria-label={t(`platformSettings.fields.ai.${modelField.key}.label`)}
                                        value={choice(modelField, draft)}
                                        onChange={(e) => onChange(modelField.key, e.target.value === '' ? null : e.target.value)}
                                    >
                                        <option value="">{t('platformSettings.ai.group', { model: name(group) })}</option>
                                        {models.map((m) => (
                                            <option key={m.id} value={m.id}>
                                                {name(m.id)}
                                            </option>
                                        ))}
                                    </Select>
                                    <Select
                                        aria-label={t(`platformSettings.fields.ai.${effortField.key}.label`)}
                                        value={effort}
                                        aria-invalid={errors[effortField.key] ? true : undefined}
                                        onChange={(e) => onChange(effortField.key, e.target.value === '' ? null : e.target.value)}
                                    >
                                        <option value="">{t('platformSettings.ai.groupEffort')}</option>
                                        {effortsFor(models, model).map((e) => (
                                            <option key={e} value={e}>
                                                {t(`platformSettings.ai.efforts.${e}.name`)}
                                            </option>
                                        ))}
                                    </Select>
                                </li>
                            );
                        })}
                    </ul>
                </div>
            ) : null}
        </div>
    );
}

/**
 * «Probar la IA»: una respuesta corta del modelo elegido con la clave guardada, y el resultado a la vista hasta la
 * próxima prueba. Va dentro del formulario de la sección: botones `type="button"`, nunca un <form> anidado.
 */
export function AiTestPanel({ section, dirty }: { section: SettingsSection; dirty: boolean }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const name = useModelName();
    const test = useAiTest();
    const models = section.info.models ?? [];
    const main = section.info.features?.find((f) => f.key === 'assistant')?.effective.model ?? models[0]?.id ?? '';
    const [model, setModel] = useState('');
    const [result, setResult] = useState<AiTestResult | null>(null);

    const run = () => {
        setResult(null);
        test.mutate(model !== '' ? { model } : {}, {
            onSuccess: setResult,
            onError: (err) => {
                const body = isApiError(err) && err.body && typeof err.body === 'object' && 'ok' in err.body ? (err.body as AiTestResult) : null;
                setResult(body ?? { ok: false, message: isApiError(err) ? err.message : t('errors.title'), model: model || main, effort: null });
            },
        });
    };

    return (
        <div className="igr:space-y-2 igr:rounded-lg igr:border igr:bg-muted/40 igr:p-3">
            <p className="igr:text-[13px] igr:font-medium">{t('platformSettings.ai.test.title')}</p>
            <p className="igr:text-xs igr:text-muted-foreground">{t('platformSettings.ai.test.help')}</p>
            <div className="igr:flex igr:flex-wrap igr:items-end igr:gap-2">
                <div className="igr:min-w-0 igr:flex-1 igr:basis-56 igr:space-y-1.5">
                    <Label htmlFor="ps-ai-test-model">{t('platformSettings.ai.test.model')}</Label>
                    <Select id="ps-ai-test-model" value={model} onChange={(e) => setModel(e.target.value)}>
                        <option value="">{t('platformSettings.ai.tiers.main.title') + ` (${name(main)})`}</option>
                        {models.map((m) => (
                            <option key={m.id} value={m.id}>
                                {name(m.id)}
                            </option>
                        ))}
                    </Select>
                </div>
                <Button type="button" variant="outline" className="igr:h-9" loading={test.isPending} onClick={run}>
                    <Sparkles />
                    {t('platformSettings.ai.test.run')}
                </Button>
            </div>
            {dirty ? <p className="igr:text-xs igr:text-warning">{t('platformSettings.ai.test.unsaved')}</p> : null}
            {result ? (
                <Callout tone={result.ok ? 'success' : 'danger'} title={result.ok ? t('platformSettings.ai.test.okTitle') : t('platformSettings.ai.test.failTitle')}>
                    <p>{result.message}</p>
                    <ul className="igr:mt-1 igr:space-y-0.5 igr:text-xs igr:text-muted-foreground">
                        {result.latency_ms !== undefined ? (
                            <li>{t('platformSettings.ai.test.latency', { seconds: (result.latency_ms / 1000).toLocaleString(workspace.locale, { maximumFractionDigits: 1 }) })}</li>
                        ) : null}
                        {result.served_model ? <li>{t('platformSettings.ai.test.served', { model: result.served_model })}</li> : null}
                        {result.reply ? <li>{t('platformSettings.ai.test.reply', { reply: result.reply })}</li> : null}
                        {result.cost_usd !== undefined ? <li>{t('platformSettings.ai.test.cost', { cost: usd(result.cost_usd, workspace.locale) })}</li> : null}
                    </ul>
                    {result.detail ? (
                        <details className="igr:mt-1 igr:text-xs">
                            <summary className="igr:cursor-pointer igr:text-muted-foreground">{t('platformSettings.ai.test.detail')}</summary>
                            <p className="igr:mt-1 igr:break-all igr:font-mono">{result.detail}</p>
                        </details>
                    ) : null}
                </Callout>
            ) : null}
        </div>
    );
}
