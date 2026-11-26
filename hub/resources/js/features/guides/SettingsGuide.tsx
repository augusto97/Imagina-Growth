import { BookOpen, ChevronDown, ChevronUp } from 'lucide-react';
import { useId, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import { IconTile } from '@/components/ui/icon-tile';
import { Progress } from '@/components/ui/progress';
import type { SettingsSection } from '@/features/platform/settings-api';
import { cn } from '@/lib/cn';
import { GuideBullets, GuideLinks, GuideProblems, GuideStepMarker, GuideValueRow } from './GuideParts';
import { type PlatformGuide, PLATFORM_GUIDES, guideProgress, guideStartsOpen, isGuideSection, problemList, stepValues, textList } from './model';

/**
 * La guía paso a paso de una sección de Plataforma → Ajustes que pide credenciales de un tercero (Meta, Google, TikTok,
 * correo, Amazon SES, IA, monitoreo): qué hacer en la consola del proveedor, con los enlaces, los valores del hub para
 * copiar, qué pasos ya se ven hechos con lo guardado, lo que suele fallar y cómo comprobar que funcionó. Se abre sola
 * mientras la sección no esté lista. Otras secciones no tienen guía y no muestran nada.
 */
export function SettingsGuide({ section }: { section: SettingsSection }) {
    if (!isGuideSection(section.key)) return null;
    return <GuidePanel guide={PLATFORM_GUIDES[section.key]} section={section} />;
}

function GuidePanel({ guide, section }: { guide: PlatformGuide; section: SettingsSection }) {
    const { t } = useTranslation();
    const id = useId();
    const progress = guideProgress(guide, section);
    const [open, setOpen] = useState(() => guideStartsOpen(section, progress));
    const base = `guides.platform.${guide.section}`;
    const before = textList(t(`${base}.before`, { returnObjects: true }));
    const problems = problemList(t(`${base}.problems`, { returnObjects: true }));
    const incomplete = guide.section === 'ses' && section.info.guide?.policies_complete === false;

    return (
        <section aria-labelledby={`${id}-title`} className="igr:rounded-xl igr:border igr:border-primary/20 igr:bg-brand-soft/40">
            <div className="igr:flex igr:flex-wrap igr:items-start igr:gap-3 igr:p-3 igr:sm:p-4">
                <IconTile icon={BookOpen} tone="brand" />
                <div className="igr:min-w-0 igr:flex-1 igr:basis-56">
                    <h3 id={`${id}-title`} className="igr:text-body igr:font-semibold">
                        {t('guides.ui.title')}
                    </h3>
                    <p className="igr:mt-0.5 igr:text-support igr:text-muted-foreground">{t(`${base}.intro`)}</p>
                    {progress.total > 0 ? (
                        <div className="igr:mt-2 igr:flex igr:max-w-xs igr:items-center igr:gap-2" title={t('guides.ui.progressHelp')}>
                            <Progress
                                value={progress.done / progress.total}
                                tone={progress.complete ? 'success' : 'brand'}
                                label={t('guides.ui.progress', { done: progress.done, total: progress.total })}
                            />
                            <span className="igr:shrink-0 igr:text-caption igr:font-medium igr:tabular-nums igr:text-muted-foreground">
                                {t('guides.ui.progress', { done: progress.done, total: progress.total })}
                            </span>
                        </div>
                    ) : null}
                </div>
                <Button type="button" variant="outline" size="sm" aria-expanded={open} aria-controls={`${id}-body`} onClick={() => setOpen((o) => !o)}>
                    {open ? <ChevronUp aria-hidden /> : <ChevronDown aria-hidden />}
                    {t(open ? 'guides.ui.hide' : 'guides.ui.show')}
                </Button>
            </div>
            <div id={`${id}-body`} hidden={!open} className="igr:space-y-5 igr:border-t igr:border-primary/15 igr:p-3 igr:sm:p-4">
                <p className="igr:text-caption igr:text-muted-foreground">{t('guides.ui.minutes', { minutes: guide.minutes })}</p>
                {before.length > 0 ? (
                    <div className="igr:space-y-2">
                        <h4 className="igr:text-support igr:font-semibold">{t('guides.ui.before')}</h4>
                        <GuideBullets items={before} />
                    </div>
                ) : null}
                <div className="igr:space-y-2">
                    <h4 className="igr:text-support igr:font-semibold">{t('guides.ui.stepsTitle')}</h4>
                    <ol className="igr:space-y-1">
                        {guide.steps.map((step, i) => {
                            const status = progress.statuses[i] ?? 'unknown';
                            const body = textList(t(`${base}.steps.${step.id}.body`, { returnObjects: true }));
                            const values = stepValues(step, section);
                            return (
                                <li key={step.id} className={cn('igr:flex igr:gap-3 igr:rounded-lg igr:px-2 igr:py-3 igr:sm:px-3', status === 'todo' && 'igr:bg-card/70')}>
                                    <GuideStepMarker index={i} status={status} />
                                    <div className="igr:min-w-0 igr:flex-1 igr:space-y-2">
                                        <p className={cn('igr:text-support igr:font-medium', status === 'done' ? 'igr:text-muted-foreground' : 'igr:text-foreground')}>
                                            {t(`${base}.steps.${step.id}.title`)}
                                        </p>
                                        {body.map((paragraph) => (
                                            <p key={paragraph} className="igr:text-support igr:text-muted-foreground">
                                                {paragraph}
                                            </p>
                                        ))}
                                        {values.length > 0 ? (
                                            <div className="igr:space-y-3 igr:rounded-md igr:border igr:bg-card igr:p-2.5">
                                                {values.map((v) => (
                                                    <GuideValueRow key={v.key} value={v} />
                                                ))}
                                                {incomplete && values.some((v) => v.key === 'iam_policy' || v.key === 'sns_policy_statement') ? (
                                                    <p className="igr:text-caption igr:text-warning">{t('guides.valuesHelp.incomplete')}</p>
                                                ) : null}
                                            </div>
                                        ) : null}
                                        <GuideLinks links={step.links ?? []} />
                                    </div>
                                </li>
                            );
                        })}
                    </ol>
                </div>
                {problems.length > 0 ? (
                    <div className="igr:space-y-2">
                        <h4 className="igr:text-support igr:font-semibold">{t('guides.ui.problems')}</h4>
                        <GuideProblems problems={problems} />
                    </div>
                ) : null}
                <div className="igr:space-y-1 igr:rounded-md igr:bg-success/10 igr:p-3">
                    <h4 className="igr:text-support igr:font-semibold igr:text-success">{t('guides.ui.check')}</h4>
                    <p className="igr:text-support igr:text-foreground">{t(`${base}.check`)}</p>
                </div>
            </div>
        </section>
    );
}
