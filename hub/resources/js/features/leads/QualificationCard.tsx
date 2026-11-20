import { Quote } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Badge } from '@/components/ui/badge';
import type { LeadQualification } from './qualification';

/**
 * Las respuestas a las preguntas de calificación del chat (auditoría §5.1 función 3), cada
 * una con la frase del cliente de la que salió, el puntaje y la derivación. Solo lectura:
 * las respuestas salen de lo que escribió el cliente.
 */
export function QualificationCard({ qualification, compact = false }: { qualification: LeadQualification | null | undefined; compact?: boolean }) {
    const { t } = useTranslation();
    if (!qualification) return null;
    const answered = qualification.answers.filter((a) => a.value !== null);

    return (
        <section className="igr:space-y-2">
            <div className="igr:flex igr:flex-wrap igr:items-center igr:gap-2">
                <h3 className="igr:text-[13px] igr:font-medium">{t('qualification.card.title')}</h3>
                {qualification.set ? <span className="igr:text-xs igr:text-muted-foreground">{qualification.set.name}</span> : null}
                <Badge variant={qualification.disqualified ? 'warning' : qualification.complete ? 'success' : 'secondary'} className="igr:ml-auto">
                    {qualification.disqualified
                        ? t('qualification.card.disqualified')
                        : qualification.complete
                          ? t('qualification.card.complete')
                          : t('qualification.card.inProgress')}
                </Badge>
            </div>
            <ul className="igr:space-y-1.5">
                {(compact ? answered : qualification.answers).map((a) => (
                    <li key={a.key} className="igr:text-[13px]">
                        <span className="igr:block igr:text-xs igr:text-muted-foreground">{a.question}</span>
                        {a.value !== null ? (
                            <span className="igr:flex igr:items-start igr:justify-between igr:gap-2">
                                <span>
                                    <span className="igr:font-medium">{a.value}</span>
                                    {a.disqualifies ? <span className="igr:ml-1.5 igr:text-xs igr:text-warning">{t('qualification.card.disqualifies')}</span> : null}
                                    {a.quote ? (
                                        <span className="igr:mt-0.5 igr:flex igr:gap-1 igr:text-xs igr:text-muted-foreground">
                                            <Quote className="igr:mt-0.5 igr:size-3 igr:shrink-0" aria-hidden />
                                            <span>{t('qualification.card.quote', { quote: a.quote })}</span>
                                        </span>
                                    ) : null}
                                </span>
                                {a.points ? (
                                    <span className="igr:shrink-0 igr:text-xs igr:tabular-nums igr:text-muted-foreground">
                                        {a.points > 0 ? '+' : ''}
                                        {a.points}
                                    </span>
                                ) : null}
                            </span>
                        ) : (
                            <span className="igr:text-muted-foreground">{a.required ? t('qualification.card.pending') : t('qualification.card.optional')}</span>
                        )}
                    </li>
                ))}
            </ul>
            <p className="igr:text-xs igr:text-muted-foreground">
                {t('qualification.card.score', { score: qualification.score })}
                {qualification.route ? ` · ${t(`qualification.card.routed.${qualification.route.action}`)}` : ''}
            </p>
        </section>
    );
}
