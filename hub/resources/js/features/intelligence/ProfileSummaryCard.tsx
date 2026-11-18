import { Sparkles } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { useWorkspace } from '@/hooks/useWorkspace';
import { useProfileSummary } from './api';
import { SourceBadge } from './components';

/** Resumen del cliente con IA, al lado del perfil (M17): solo con agregados, marcado como IA. */
export function ProfileSummaryCard({ contactId }: { contactId: string }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const summary = useProfileSummary(workspace.id, contactId, true);
    if (summary.isError) return null;

    return (
        <Card>
            <CardHeader className="igr:flex igr:flex-row igr:items-center igr:justify-between igr:gap-2 igr:pb-2">
                <CardTitle className="igr:flex igr:items-center igr:gap-1.5">
                    <Sparkles className="igr:size-3.5 igr:text-muted-foreground" aria-hidden />
                    {t('intelligence.profile.title')}
                </CardTitle>
                {summary.data ? <SourceBadge source={summary.data.source} /> : null}
            </CardHeader>
            <CardContent>{summary.isPending ? <Skeleton className="igr:h-12" /> : <p className="igr:text-[13px] igr:leading-relaxed">{summary.data.text}</p>}</CardContent>
        </Card>
    );
}
