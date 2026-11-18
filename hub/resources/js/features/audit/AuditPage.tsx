import { ClipboardList } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { useAudit } from '@/api/queries';
import { Card } from '@/components/ui/card';
import { EmptyState, ErrorState, PageHeader } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TBody, TD, TH, THead, TR } from '@/components/ui/table';
import { useWorkspace } from '@/hooks/useWorkspace';
import { formatDateTime } from '@/lib/format';

function summarize(changes: Record<string, unknown> | null): string {
    if (!changes) {
        return '';
    }
    return Object.entries(changes)
        .map(([k, v]) => `${k}: ${typeof v === 'string' ? v : JSON.stringify(v)}`)
        .join(' · ');
}

export function AuditPage() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const audit = useAudit(workspace.id);

    return (
        <>
            <PageHeader title={t('audit.title')} description={t('audit.description')} />
            {audit.isError ? (
                <ErrorState error={audit.error} onRetry={() => void audit.refetch()} />
            ) : audit.isPending ? (
                <Skeleton className="igr:h-64" />
            ) : audit.data.length === 0 ? (
                <EmptyState icon={ClipboardList} title={t('audit.empty')} />
            ) : (
                <Card>
                    <Table>
                        <THead>
                            <TR className="igr:hover:bg-transparent">
                                <TH>{t('audit.when')}</TH>
                                <TH>{t('audit.who')}</TH>
                                <TH>{t('audit.action')}</TH>
                                <TH>{t('audit.details')}</TH>
                            </TR>
                        </THead>
                        <TBody>
                            {audit.data.map((entry) => (
                                <TR key={entry.id}>
                                    <TD className="igr:whitespace-nowrap igr:text-muted-foreground">{formatDateTime(entry.created_at, workspace.locale, workspace.timezone)}</TD>
                                    <TD>{entry.user ?? <span className="igr:text-muted-foreground">{t('audit.system')}</span>}</TD>
                                    <TD>{t(`audit.actions.${entry.action}`, { defaultValue: entry.action })}</TD>
                                    <TD className="igr:max-w-md igr:truncate igr:font-mono igr:text-xs igr:text-muted-foreground" title={summarize(entry.changes)}>
                                        {summarize(entry.changes)}
                                    </TD>
                                </TR>
                            ))}
                        </TBody>
                    </Table>
                </Card>
            )}
        </>
    );
}
