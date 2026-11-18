import { BookOpen, Plus, TriangleAlert } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useSearchParams } from 'react-router';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { ErrorState, PageHeader } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useWorkspace } from '@/hooks/useWorkspace';
import { formatNumber } from '@/lib/format';
import { useIntegrations } from './api';
import { EcosystemTab } from './EcosystemTab';
import { McpTab } from './McpTab';
import { CopyLine } from './SecretReveal';
import { CreateTokenDialog, TokensTab } from './TokensTab';
import { WebhooksTab } from './WebhooksTab';

const TABS = ['tokens', 'webhooks', 'mcp', 'ecosystem'] as const;
type Tab = (typeof TABS)[number];

/** «API e integraciones» (M19): API pública, webhooks, servidor MCP y ecosistema Imagina. */
export function IntegrationsPage() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const overview = useIntegrations(workspace.id);
    const [params, setParams] = useSearchParams();
    const draft = params.get('borrador');
    const requested = params.get('tab');
    const tab: Tab = draft ? 'mcp' : TABS.includes(requested as Tab) ? (requested as Tab) : 'tokens';
    const [creating, setCreating] = useState<'token' | 'mcp' | null>(null);

    return (
        <>
            <PageHeader
                title={t('integrations.title')}
                description={t('integrations.description')}
                actions={
                    overview.data && (
                        <>
                            <Button variant="outline" asChild>
                                <a href={overview.data.docs_url} target="_blank" rel="noreferrer">
                                    <BookOpen className="igr:size-4" aria-hidden />
                                    {t('integrations.docs')}
                                </a>
                            </Button>
                            <Button onClick={() => setCreating('token')} disabled={!overview.data.plan.api}>
                                <Plus className="igr:size-4" aria-hidden />
                                {t('integrations.tokens.create')}
                            </Button>
                        </>
                    )
                }
            />
            {overview.isError ? (
                <ErrorState error={overview.error} onRetry={() => void overview.refetch()} />
            ) : overview.isPending ? (
                <Skeleton className="igr:h-64" />
            ) : (
                <div className="igr:space-y-5">
                    {!overview.data.plan.api && (
                        <p className="igr:flex igr:items-center igr:gap-2 igr:rounded-md igr:border igr:border-warning/40 igr:bg-warning/10 igr:p-3 igr:text-[13px]">
                            <TriangleAlert className="igr:size-4 igr:text-warning" aria-hidden />
                            {t('integrations.planMissing')}
                        </p>
                    )}
                    <Card>
                        <CardContent className="igr:grid igr:gap-4 igr:pt-4 igr:sm:grid-cols-3">
                            <div className="igr:space-y-1">
                                <p className="igr:text-xs igr:text-muted-foreground">{t('integrations.baseUrl')}</p>
                                <CopyLine value={overview.data.base_url} label={t('integrations.copy')} />
                            </div>
                            <div>
                                <p className="igr:text-xs igr:text-muted-foreground">{t('integrations.requestsThisMonth')}</p>
                                <p className="igr:text-lg igr:font-semibold igr:tabular-nums">{formatNumber(overview.data.requests_this_month)}</p>
                            </div>
                            <div>
                                <p className="igr:text-xs igr:text-muted-foreground">{t('integrations.rateLimit')}</p>
                                <p className="igr:text-lg igr:font-semibold igr:tabular-nums">{t('integrations.perMinute', { n: formatNumber(overview.data.rate_limit_per_minute) })}</p>
                            </div>
                        </CardContent>
                    </Card>
                    <Tabs
                        value={tab}
                        onValueChange={(v) => {
                            const next = new URLSearchParams();
                            if (v !== 'tokens') next.set('tab', v);
                            setParams(next, { replace: true });
                        }}
                    >
                        <TabsList>
                            {TABS.map((k) => (
                                <TabsTrigger key={k} value={k}>
                                    {t(`integrations.tabs.${k}`)}
                                </TabsTrigger>
                            ))}
                        </TabsList>
                        <TabsContent value="tokens">
                            <TokensTab ws={workspace.id} overview={overview.data} onCreate={() => setCreating('token')} />
                        </TabsContent>
                        <TabsContent value="webhooks">
                            <WebhooksTab ws={workspace.id} overview={overview.data} />
                        </TabsContent>
                        <TabsContent value="mcp">
                            <McpTab ws={workspace.id} overview={overview.data} highlight={draft} onCreateToken={() => setCreating('mcp')} />
                        </TabsContent>
                        <TabsContent value="ecosystem">
                            <EcosystemTab ws={workspace.id} overview={overview.data} />
                        </TabsContent>
                    </Tabs>
                    {creating && <CreateTokenDialog key={creating} ws={workspace.id} overview={overview.data} open kind={creating} onClose={() => setCreating(null)} />}
                </div>
            )}
        </>
    );
}
