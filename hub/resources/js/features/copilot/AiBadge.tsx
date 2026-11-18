import { Sparkles } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Badge } from '@/components/ui/badge';

/** «Generado con IA»: todo texto del copiloto se marca y se puede editar antes de usarlo (inteligencia.md §6.1). */
export function AiBadge({ source }: { source?: 'ai' | 'fallback' | 'template' }) {
    const { t } = useTranslation();
    if (source === 'fallback' || source === 'template') {
        return <Badge variant="outline">{t('copilot.badge.noAi')}</Badge>;
    }
    return (
        <Badge>
            <Sparkles className="igr:mr-1 igr:size-3" aria-hidden="true" />
            {t('copilot.badge.ai')}
        </Badge>
    );
}
