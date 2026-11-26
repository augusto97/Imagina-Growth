import { Check, ExternalLink } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { CopyButton } from '@/components/ui/copy-field';
import { cn } from '@/lib/cn';
import { GUIDE_LINKS, type GuideLinkKey, type GuideProblem, type GuideStepStatus, type GuideValue } from './model';

/* Piezas de las guías paso a paso: enlaces a las consolas, valores para copiar, problemas y el número de cada paso. */

/** Botones a la consola del proveedor; se abren en otra pestaña y lo dicen al lector de pantalla. */
export function GuideLinks({ links }: { links: readonly GuideLinkKey[] }) {
    const { t } = useTranslation();
    if (links.length === 0) return null;
    return (
        <div className="igr:flex igr:flex-wrap igr:gap-2">
            {links.map((key) => (
                <a
                    key={key}
                    href={GUIDE_LINKS[key]}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="igr:inline-flex igr:min-h-8 igr:items-center igr:gap-1.5 igr:rounded-md igr:border igr:border-border igr:bg-card igr:px-2.5 igr:py-1 igr:text-xs igr:font-medium igr:text-foreground igr:shadow-xs igr:transition-colors igr:hover:bg-accent igr:focus-visible:outline-none igr:focus-visible:ring-3 igr:focus-visible:ring-ring/25"
                >
                    <ExternalLink className="igr:size-3.5 igr:shrink-0" aria-hidden />
                    {t(`guides.links.${key}`)}
                    <span className="igr:sr-only"> {t('guides.ui.opensNewTab')}</span>
                </a>
            ))}
        </div>
    );
}

/**
 * Un valor calculado por el hub para pegar en la consola: una dirección o un texto en una línea, una lista como chips
 * (se copian una por línea) o un JSON en un bloque que se puede seleccionar.
 */
export function GuideValueRow({ value }: { value: GuideValue }) {
    const { t, i18n } = useTranslation();
    const label = t(`guides.values.${value.key}`);
    const helpKey = `guides.valuesHelp.${value.key}`;
    const copyLabel = t('guides.ui.copyValue', { thing: label.toLowerCase() });
    return (
        <div className="igr:min-w-0 igr:space-y-1.5">
            <div className="igr:flex igr:flex-wrap igr:items-center igr:justify-between igr:gap-2">
                <span className="igr:text-xs igr:font-medium igr:text-foreground">{label}</span>
                {value.list || value.multiline ? (
                    <CopyButton value={value.text} variant="outline" size="sm" label={copyLabel}>
                        {t('guides.ui.copyAll')}
                    </CopyButton>
                ) : null}
            </div>
            {value.list ? (
                <ul className="igr:flex igr:flex-wrap igr:gap-1.5" aria-label={label}>
                    {value.list.map((item) => (
                        <li key={item} className="igr:max-w-full igr:break-all igr:rounded igr:bg-muted igr:px-1.5 igr:py-0.5 igr:font-mono igr:text-[11px]">
                            {item}
                        </li>
                    ))}
                </ul>
            ) : value.multiline ? (
                <pre className="igr:max-h-64 igr:overflow-auto igr:rounded-md igr:border igr:bg-muted/60 igr:p-2 igr:font-mono igr:text-[11px] igr:leading-relaxed" tabIndex={0} aria-label={label}>
                    {value.text}
                </pre>
            ) : (
                <div className="igr:flex igr:min-w-0 igr:items-center igr:gap-1.5">
                    <code className="igr:min-w-0 igr:flex-1 igr:break-all igr:rounded igr:bg-muted igr:px-1.5 igr:py-1 igr:font-mono igr:text-xs">{value.text}</code>
                    <CopyButton value={value.text} variant="ghost" className="igr:size-8" label={copyLabel} />
                </div>
            )}
            {i18n.exists(helpKey) ? <p className="igr:text-caption igr:text-muted-foreground">{t(helpKey)}</p> : null}
        </div>
    );
}

/** El número del paso, o una palomita si ya está listo; el estado también en texto para el lector de pantalla. */
export function GuideStepMarker({ index, status }: { index: number; status: GuideStepStatus }) {
    const { t } = useTranslation();
    return (
        <span
            className={cn(
                'igr:flex igr:size-6 igr:shrink-0 igr:items-center igr:justify-center igr:rounded-full igr:text-[11px] igr:font-semibold igr:tabular-nums',
                status === 'done' ? 'igr:bg-success igr:text-success-foreground' : status === 'todo' ? 'igr:bg-primary igr:text-primary-foreground' : 'igr:border igr:border-input igr:text-muted-foreground',
            )}
        >
            {status === 'done' ? <Check className="igr:size-3.5" strokeWidth={3} aria-hidden /> : <span aria-hidden>{index + 1}</span>}
            <span className="igr:sr-only">{`${index + 1} · ${t(`guides.ui.status.${status}`)}`}</span>
        </span>
    );
}

/** «Si algo falla»: lo que se ve y cómo se arregla. */
export function GuideProblems({ problems }: { problems: GuideProblem[] }) {
    if (problems.length === 0) return null;
    return (
        <dl className="igr:space-y-2.5">
            {problems.map((p) => (
                <div key={p.problem} className="igr:text-support">
                    <dt className="igr:font-medium igr:text-foreground">{p.problem}</dt>
                    <dd className="igr:text-muted-foreground">{p.fix}</dd>
                </div>
            ))}
        </dl>
    );
}

/** Una lista corta con viñetas (lo que necesitas, antes de empezar). */
export function GuideBullets({ items }: { items: string[] }) {
    if (items.length === 0) return null;
    return (
        <ul className="igr:list-disc igr:space-y-1 igr:pl-5 igr:text-support igr:text-muted-foreground igr:marker:text-primary/60">
            {items.map((item) => (
                <li key={item}>{item}</li>
            ))}
        </ul>
    );
}
