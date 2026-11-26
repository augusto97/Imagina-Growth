import { ChevronDown, LifeBuoy } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { GuideBullets, GuideProblems } from './GuideParts';
import { hasConnectionGuide, problemList, textList } from './model';

/**
 * «Antes de conectar» en la hoja de cada tarjeta de Conexiones: lo que el cliente necesita tener listo (ser administrador
 * del Business Manager, una cuenta publicitaria con pago, un número que no esté en la app normal de WhatsApp…), el paso
 * a paso corto y lo que suele fallar con su arreglo. Plegado; se abre de entrada si la tarjeta todavía no está conectada.
 */
export function ConnectionGuide({ card, defaultOpen = false }: { card: string; defaultOpen?: boolean }) {
    const { t } = useTranslation();
    if (!hasConnectionGuide(card)) return null;
    const base = `guides.connections.${card}`;
    const need = textList(t(`${base}.need`, { returnObjects: true }));
    const steps = textList(t(`${base}.steps`, { returnObjects: true }));
    const notes = textList(t(`${base}.notes`, { returnObjects: true }));
    const problems = problemList(t(`${base}.problems`, { returnObjects: true }));
    if (need.length === 0 && steps.length === 0 && problems.length === 0) return null;

    return (
        <details open={defaultOpen} className="igr:group igr:rounded-xl igr:border igr:bg-muted/30">
            <summary className="igr:flex igr:cursor-pointer igr:list-none igr:items-center igr:gap-3 igr:rounded-xl igr:p-3 igr:focus-visible:outline-none igr:focus-visible:ring-3 igr:focus-visible:ring-ring/25 igr:[&::-webkit-details-marker]:hidden">
                <LifeBuoy className="igr:size-4 igr:shrink-0 igr:text-primary" aria-hidden />
                <span className="igr:min-w-0 igr:flex-1">
                    <span className="igr:block igr:text-support igr:font-semibold">{t('guides.ui.connection.title')}</span>
                    <span className="igr:block igr:text-caption igr:text-muted-foreground">{t('guides.ui.connection.summary')}</span>
                </span>
                <ChevronDown className="igr:size-4 igr:shrink-0 igr:text-muted-foreground igr:transition-transform igr:group-open:rotate-180" aria-hidden />
            </summary>
            <div className="igr:space-y-4 igr:border-t igr:p-3">
                {need.length > 0 ? (
                    <div className="igr:space-y-1.5">
                        <h4 className="igr:text-caption igr:font-semibold igr:uppercase igr:tracking-wide igr:text-muted-foreground">{t('guides.ui.connection.need')}</h4>
                        <GuideBullets items={need} />
                    </div>
                ) : null}
                {steps.length > 0 ? (
                    <div className="igr:space-y-1.5">
                        <h4 className="igr:text-caption igr:font-semibold igr:uppercase igr:tracking-wide igr:text-muted-foreground">{t('guides.ui.connection.steps')}</h4>
                        <ol className="igr:list-decimal igr:space-y-1 igr:pl-5 igr:text-support igr:text-muted-foreground igr:marker:font-semibold igr:marker:text-primary">
                            {steps.map((s) => (
                                <li key={s}>{s}</li>
                            ))}
                        </ol>
                    </div>
                ) : null}
                {notes.length > 0 ? (
                    <div className="igr:space-y-1.5">
                        <h4 className="igr:text-caption igr:font-semibold igr:uppercase igr:tracking-wide igr:text-muted-foreground">{t('guides.ui.connection.notes')}</h4>
                        <GuideBullets items={notes} />
                    </div>
                ) : null}
                {problems.length > 0 ? (
                    <div className="igr:space-y-1.5">
                        <h4 className="igr:text-caption igr:font-semibold igr:uppercase igr:tracking-wide igr:text-muted-foreground">{t('guides.ui.connection.problems')}</h4>
                        <GuideProblems problems={problems} />
                    </div>
                ) : null}
            </div>
        </details>
    );
}
