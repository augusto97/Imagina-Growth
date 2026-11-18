import { useTranslation } from 'react-i18next';
import { Badge } from '@/components/ui/badge';
import type { ContactImport } from '../api';

export function ImportStatusBadge({ status }: { status: ContactImport['status'] }) {
    const { t } = useTranslation();
    switch (status) {
        case 'completed':
            return <Badge variant="success">{t('contacts.import.state.completed')}</Badge>;
        case 'failed':
            return <Badge variant="destructive">{t('contacts.import.state.failed')}</Badge>;
        case 'running':
            return <Badge variant="default">{t('contacts.import.state.running')}</Badge>;
        default:
            return <Badge variant="secondary">{t('contacts.import.state.queued')}</Badge>;
    }
}
