import { Sparkles } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { useWorkspace } from '@/hooks/useWorkspace';
import { useReviewSummary } from './api';
import { SourceBadge } from './components';

/** Lo que dicen las reseñas de un producto, resumido con IA (sin nombres de autores). */
export function ReviewSummaryCard({ productId }: { productId: string }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const summary = useReviewSummary(workspace.id, productId);
    if (summary.isError || (summary.data && !summary.data.available)) return null;

    return (
        <Card>
            <CardHeader className="igr:flex igr:flex-row igr:items-center igr:justify-between igr:gap-2 igr:pb-2">
                <CardTitle className="igr:flex igr:items-center igr:gap-1.5">
                    <Sparkles className="igr:size-3.5 igr:text-muted-foreground" aria-hidden />
                    {t('intelligence.reviews.title')}
                </CardTitle>
                {summary.data ? <SourceBadge source={summary.data.source} /> : null}
            </CardHeader>
            <CardContent className="igr:space-y-3 igr:text-[13px]">
                {summary.isPending ? (
                    <Skeleton className="igr:h-16" />
                ) : (
                    <>
                        <p className="igr:leading-relaxed">{summary.data.summary}</p>
                        <div className="igr:grid igr:gap-3 igr:sm:grid-cols-2">
                            {summary.data.positives.length > 0 ? (
                                <div>
                                    <p className="igr:mb-1 igr:text-xs igr:font-medium igr:text-muted-foreground">{t('intelligence.reviews.positives')}</p>
                                    <ul className="igr:list-disc igr:space-y-0.5 igr:pl-4">
                                        {summary.data.positives.map((p) => (
                                            <li key={p}>{p}</li>
                                        ))}
                                    </ul>
                                </div>
                            ) : null}
                            {summary.data.negatives.length > 0 ? (
                                <div>
                                    <p className="igr:mb-1 igr:text-xs igr:font-medium igr:text-muted-foreground">{t('intelligence.reviews.negatives')}</p>
                                    <ul className="igr:list-disc igr:space-y-0.5 igr:pl-4">
                                        {summary.data.negatives.map((p) => (
                                            <li key={p}>{p}</li>
                                        ))}
                                    </ul>
                                </div>
                            ) : null}
                        </div>
                    </>
                )}
            </CardContent>
        </Card>
    );
}
