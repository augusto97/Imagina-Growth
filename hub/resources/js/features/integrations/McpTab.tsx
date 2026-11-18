import { Bot, Check, X } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { EmptyState, ErrorState } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { errorMessage } from '@/features/contacts/errors';
import { useCan } from '@/hooks/useWorkspace';
import { cn } from '@/lib/cn';
import { formatDateTime } from '@/lib/format';
import { toast } from '@/stores/toast';
import { type IntegrationsOverview, claudeCodeCommand, useMcpDrafts, useReviewMcpDraft } from './api';
import { CopyLine } from './SecretReveal';

/** Servidor MCP: cómo conectarlo en Claude y los borradores que propuso un agente. */
export function McpTab({ ws, overview, highlight, onCreateToken }: { ws: string; overview: IntegrationsOverview; highlight: string | null; onCreateToken: () => void }) {
    const { t } = useTranslation();
    const canReview = useCan('manageMarketing');
    const drafts = useMcpDrafts(ws, canReview);
    const review = useReviewMcpDraft(ws);

    return (
        <div className="igr:grid igr:gap-4 igr:lg:grid-cols-2">
            <Card>
                <CardHeader>
                    <CardTitle className="igr:flex igr:items-center igr:gap-2">
                        <Bot className="igr:size-4" aria-hidden />
                        {t('integrations.mcp.title')}
                        {!overview.plan.mcp && <Badge variant="warning">{t('integrations.notInPlan')}</Badge>}
                    </CardTitle>
                    <CardDescription>{t('integrations.mcp.description')}</CardDescription>
                </CardHeader>
                <CardContent className="igr:space-y-4 igr:text-[13px]">
                    <div className="igr:space-y-1.5">
                        <p className="igr:font-medium">{t('integrations.mcp.url')}</p>
                        <CopyLine value={overview.mcp_url} label={t('integrations.copy')} />
                    </div>
                    <ol className="igr:list-decimal igr:space-y-1.5 igr:pl-5">
                        <li>
                            {t('integrations.mcp.step1')}{' '}
                            <Button variant="link" size="sm" onClick={onCreateToken} disabled={!overview.plan.mcp}>
                                {t('integrations.tokens.createMcp')}
                            </Button>
                        </li>
                        <li>{t('integrations.mcp.step2')}</li>
                        <li>{t('integrations.mcp.step3')}</li>
                    </ol>
                    <div className="igr:space-y-1.5">
                        <p className="igr:font-medium">{t('integrations.mcp.claudeCode')}</p>
                        <CopyLine value={claudeCodeCommand(overview.mcp_url)} label={t('integrations.copy')} />
                    </div>
                    <p className="igr:text-xs igr:text-muted-foreground">{t('integrations.mcp.tools')}</p>
                </CardContent>
            </Card>
            <Card>
                <CardHeader>
                    <CardTitle>{t('integrations.mcp.drafts')}</CardTitle>
                    <CardDescription>{t('integrations.mcp.draftsHint')}</CardDescription>
                </CardHeader>
                <CardContent>
                    {!canReview ? (
                        <p className="igr:text-[13px] igr:text-muted-foreground">{t('integrations.mcp.noPermission')}</p>
                    ) : drafts.isError ? (
                        <ErrorState error={drafts.error} onRetry={() => void drafts.refetch()} />
                    ) : drafts.isPending ? (
                        <Skeleton className="igr:h-24" />
                    ) : drafts.data.length === 0 ? (
                        <EmptyState icon={Bot} title={t('integrations.mcp.noDrafts')} className="igr:py-6" />
                    ) : (
                        <ul className="igr:divide-y">
                            {drafts.data.map((d) => (
                                <li key={d.id} className={cn('igr:flex igr:items-start igr:justify-between igr:gap-3 igr:py-3', highlight === d.id && 'igr:rounded-md igr:bg-primary/5 igr:px-2')}>
                                    <div className="igr:min-w-0">
                                        <p className="igr:font-medium">{d.name}</p>
                                        {d.description && <p className="igr:text-xs igr:text-muted-foreground">{d.description}</p>}
                                        <p className="igr:mt-0.5 igr:text-xs igr:text-muted-foreground">{d.created_at ? formatDateTime(d.created_at) : ''}</p>
                                    </div>
                                    {d.status === 'draft' ? (
                                        <div className="igr:flex igr:shrink-0 igr:gap-1">
                                            <Button
                                                size="sm"
                                                loading={review.isPending && review.variables.id === d.id && review.variables.action === 'approve'}
                                                onClick={() =>
                                                    review.mutate({ id: d.id, action: 'approve' }, { onSuccess: () => toast.success(t('integrations.mcp.approved')), onError: (e) => toast.error(errorMessage(e, t)) })
                                                }
                                            >
                                                <Check className="igr:size-3.5" aria-hidden />
                                                {t('integrations.mcp.approve')}
                                            </Button>
                                            <Button variant="ghost" size="sm" onClick={() => review.mutate({ id: d.id, action: 'discard' })}>
                                                <X className="igr:size-3.5" aria-hidden />
                                                {t('integrations.mcp.discard')}
                                            </Button>
                                        </div>
                                    ) : (
                                        <Badge variant={d.status === 'applied' ? 'success' : 'outline'}>{t(`integrations.mcp.status.${d.status}`)}</Badge>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                </CardContent>
            </Card>
        </div>
    );
}
