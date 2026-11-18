import { ArrowLeft, FileText, Plus, RefreshCw } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useSearchParams } from 'react-router';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Select } from '@/components/ui/input';
import { EmptyState, ErrorState, PageHeader } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TBody, TD, TH, THead, TR } from '@/components/ui/table';
import { useCan, useWorkspace } from '@/hooks/useWorkspace';
import { isApiError } from '@/lib/api';
import { toast } from '@/stores/toast';
import { type WaTemplate, useChannels, useDeleteWaTemplate, useSyncChannel, useWaTemplates } from './api';
import { WaTemplateEditor } from './WaTemplateEditor';

function statusVariant(status: string): 'success' | 'warning' | 'destructive' | 'outline' {
    if (status === 'APPROVED') return 'success';
    if (status === 'PENDING' || status === 'IN_APPEAL') return 'warning';
    if (status === 'REJECTED' || status === 'DISABLED' || status === 'PAUSED') return 'destructive';
    return 'outline';
}

/** Plantillas de WhatsApp de cada número oficial (M07). */
export function WaTemplatesPage() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const canManage = useCan('manageMarketing');
    const channels = useChannels(workspace.id);
    const numbers = (channels.data?.data ?? []).filter((a) => a.provider === 'meta_cloud' && a.status !== 'disconnected');
    const [params, setParams] = useSearchParams();
    const account = params.get('numero') ?? numbers.find((n) => n.is_default)?.id ?? numbers[0]?.id ?? null;
    const templates = useWaTemplates(workspace.id, account);
    const sync = useSyncChannel(workspace.id);
    const remove = useDeleteWaTemplate(workspace.id);
    const [editing, setEditing] = useState<{ template: WaTemplate | null } | null>(null);
    const linksBase = (() => {
        const url = numbers.find((n) => n.id === account)?.webhook_url;
        return url ? url.replace(/\/webhooks\/meta$/, '') : window.location.origin;
    })();

    return (
        <div>
            <Link to={`/w/${workspace.id}/canales?tab=whatsapp`} className="igr:mb-3 igr:inline-flex igr:items-center igr:gap-1 igr:text-[13px] igr:text-muted-foreground igr:hover:text-foreground">
                <ArrowLeft className="igr:size-3.5" />
                {t('channels.templates.back')}
            </Link>
            <PageHeader
                title={t('channels.templates.title')}
                description={t('channels.templates.description')}
                actions={
                    account && canManage ? (
                        <>
                            <Button
                                variant="outline"
                                loading={sync.isPending}
                                onClick={() => sync.mutate(account, { onSuccess: (r) => toast.success(t('channels.toast.synced', { count: r.templates })), onError: (e) => toast.error(t('channels.templates.sync'), isApiError(e) ? e.message : undefined) })}
                            >
                                <RefreshCw />
                                {t('channels.templates.sync')}
                            </Button>
                            <Button onClick={() => setEditing({ template: null })}>
                                <Plus />
                                {t('channels.templates.new')}
                            </Button>
                        </>
                    ) : undefined
                }
            />
            {numbers.length > 1 && (
                <div className="igr:mb-3 igr:max-w-xs">
                    <Select aria-label={t('channels.templates.account')} value={account ?? ''} onChange={(e) => setParams({ numero: e.target.value }, { replace: true })}>
                        {numbers.map((n) => (
                            <option key={n.id} value={n.id}>
                                {n.display_name} {n.identity ? `· ${n.identity}` : ''}
                            </option>
                        ))}
                    </Select>
                </div>
            )}
            {channels.isPending ? (
                <Skeleton className="igr:h-48" />
            ) : account === null ? (
                <EmptyState icon={FileText} title={t('channels.templates.noAccount')} action={<Button asChild><Link to={`/w/${workspace.id}/canales?tab=whatsapp`}>{t('channels.actions.connectWhatsApp')}</Link></Button>} />
            ) : templates.isError ? (
                <ErrorState error={templates.error} onRetry={() => void templates.refetch()} />
            ) : templates.isPending ? (
                <Skeleton className="igr:h-48" />
            ) : templates.data.data.length === 0 ? (
                <EmptyState icon={FileText} title={t('channels.templates.empty')} description={t('channels.templates.emptyHelp')} />
            ) : (
                <div className="igr:overflow-x-auto igr:rounded-lg igr:border">
                    <Table>
                        <THead>
                            <TR>
                                <TH>{t('channels.templates.name')}</TH>
                                <TH>{t('channels.templates.category')}</TH>
                                <TH>{t('channels.templates.language')}</TH>
                                <TH>{t('channels.templates.status')}</TH>
                                <TH className="igr:sr-only">{t('channels.actions.more')}</TH>
                            </TR>
                        </THead>
                        <TBody>
                            {templates.data.data.map((tpl) => (
                                <TR key={tpl.id}>
                                    <TD>
                                        <span className="igr:font-mono igr:text-xs">{tpl.name}</span>
                                        {tpl.rejected_reason && <p className="igr:mt-0.5 igr:text-xs igr:text-destructive">{t('channels.templates.rejected', { reason: tpl.rejected_reason })}</p>}
                                    </TD>
                                    <TD>{t(`channels.templates.categories.${tpl.category}`)}</TD>
                                    <TD className="igr:text-muted-foreground">{tpl.language}</TD>
                                    <TD>
                                        <Badge variant={statusVariant(tpl.status)}>{t(`channels.templates.statuses.${tpl.status}`, { defaultValue: tpl.status })}</Badge>
                                    </TD>
                                    <TD className="igr:text-right igr:whitespace-nowrap">
                                        {canManage && (
                                            <>
                                                <Button variant="ghost" size="sm" onClick={() => setEditing({ template: tpl })}>
                                                    {t('channels.templates.edit')}
                                                </Button>
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    className="igr:text-destructive"
                                                    onClick={() => window.confirm(t('channels.templates.deleteConfirm', { name: tpl.name })) && remove.mutate(tpl.id, { onSuccess: () => toast.success(t('channels.templates.deleted')) })}
                                                >
                                                    {t('channels.templates.delete')}
                                                </Button>
                                            </>
                                        )}
                                    </TD>
                                </TR>
                            ))}
                        </TBody>
                    </Table>
                </div>
            )}
            {editing && account && <WaTemplateEditor open account={account} template={editing.template} linksBase={linksBase} onClose={() => setEditing(null)} />}
        </div>
    );
}
