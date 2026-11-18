import { History, Sparkles } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { EmptyState, ErrorState, FieldError, PageHeader } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { useCan, useWorkspace } from '@/hooks/useWorkspace';
import { formatRelative } from '@/lib/format';
import { useCopilotStatus, useDraft, useDrafts, useLearnTone } from './api';
import { CopilotDialog, CreateWithAiButton, copilotError } from './CopilotDialog';
import type { DraftKind } from './types';

const STATUS_VARIANT = { draft: 'secondary', applied: 'success', discarded: 'outline' } as const;

/** Historial del copiloto y tono de marca (ruta `copiloto`). */
export function CopilotPage() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const [kind, setKind] = useState<DraftKind | undefined>(undefined);
    const drafts = useDrafts(workspace.id, kind);
    const [openId, setOpenId] = useState<string | null>(null);
    const open = useDraft(workspace.id, openId);

    return (
        <>
            <PageHeader
                title={t('copilot.page.title')}
                description={t('copilot.page.description')}
                actions={
                    <div className="igr:flex igr:gap-2">
                        <CreateWithAiButton kind="flow" />
                        <CreateWithAiButton kind="campaign" />
                    </div>
                }
            />
            <div className="igr:grid igr:gap-4 igr:lg:grid-cols-[1fr_320px]">
                <Card>
                    <CardHeader className="igr:flex igr:flex-row igr:items-center igr:justify-between igr:gap-2">
                        <CardTitle className="igr:flex igr:items-center igr:gap-1.5">
                            <History className="igr:size-4" aria-hidden="true" />
                            {t('copilot.page.history')}
                        </CardTitle>
                        <div role="group" aria-label={t('copilot.page.filter')} className="igr:flex igr:gap-1">
                            {([undefined, 'campaign', 'flow', 'content'] as const).map((k) => (
                                <Button key={k ?? 'all'} size="sm" variant={kind === k ? 'secondary' : 'ghost'} aria-pressed={kind === k} onClick={() => setKind(k)}>
                                    {t(`copilot.kind.${k ?? 'all'}`)}
                                </Button>
                            ))}
                        </div>
                    </CardHeader>
                    <CardContent>
                        {drafts.isPending ? (
                            <div className="igr:space-y-2">
                                <Skeleton className="igr:h-12" />
                                <Skeleton className="igr:h-12" />
                            </div>
                        ) : drafts.isError ? (
                            <ErrorState error={drafts.error} onRetry={() => void drafts.refetch()} />
                        ) : drafts.data.length === 0 ? (
                            <EmptyState icon={Sparkles} title={t('copilot.page.empty')} description={t('copilot.page.emptyHelp')} />
                        ) : (
                            <ul className="igr:divide-y">
                                {drafts.data.map((d) => (
                                    <li key={d.id} className="igr:flex igr:items-start igr:justify-between igr:gap-3 igr:py-2">
                                        <div className="igr:min-w-0">
                                            <p className="igr:truncate igr:text-[13px] igr:font-medium">{d.kind === 'content' ? t(`copilot.contentType.${d.name ?? 'subjects'}`) : d.name}</p>
                                            <p className="igr:truncate igr:text-xs igr:text-muted-foreground">«{d.prompt}»</p>
                                            <p className="igr:text-[11px] igr:text-muted-foreground">
                                                {t(`copilot.kind.${d.kind}`)} · {formatRelative(d.created_at, workspace.locale)}
                                            </p>
                                        </div>
                                        <div className="igr:flex igr:shrink-0 igr:items-center igr:gap-2">
                                            <Badge variant={STATUS_VARIANT[d.status]}>{t(`copilot.status.${d.status}`)}</Badge>
                                            {d.kind !== 'content' && d.status === 'applied' && d.applied[0] ? (
                                                <Button asChild size="sm" variant="ghost">
                                                    <Link to={d.kind === 'flow' ? `/w/${workspace.id}/flujos/${d.applied[0].id}` : `/w/${workspace.id}/campanas/${d.applied[0].id}/editar`}>{t('copilot.page.openApplied')}</Link>
                                                </Button>
                                            ) : d.kind !== 'content' ? (
                                                <Button size="sm" variant="ghost" onClick={() => setOpenId(d.id)}>
                                                    {t('copilot.page.review')}
                                                </Button>
                                            ) : null}
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </CardContent>
                </Card>
                <ToneCard />
            </div>
            {openId !== null && open.data ? <CopilotDialog kind={open.data.kind} initial={open.data} onClose={() => setOpenId(null)} /> : null}
        </>
    );
}

function ToneCard() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const status = useCopilotStatus(workspace.id);
    const learn = useLearnTone(workspace.id);
    const canLearn = useCan('manageMarketing');
    const tone = status.data?.tone ?? null;

    return (
        <Card>
            <CardHeader>
                <CardTitle>{t('copilot.tone.title')}</CardTitle>
            </CardHeader>
            <CardContent className="igr:space-y-2 igr:text-[13px]">
                {status.isPending ? (
                    <Skeleton className="igr:h-20" />
                ) : tone === null ? (
                    <p className="igr:text-muted-foreground">{t('copilot.tone.empty')}</p>
                ) : (
                    <>
                        <p>{tone.summary}</p>
                        <div className="igr:flex igr:flex-wrap igr:gap-1">
                            {tone.traits.map((x) => (
                                <Badge key={x} variant="secondary">
                                    {x}
                                </Badge>
                            ))}
                        </div>
                        {tone.examples.length > 0 ? <p className="igr:text-xs igr:italic igr:text-muted-foreground">«{tone.examples[0]}»</p> : null}
                        <p className="igr:text-[11px] igr:text-muted-foreground">{t('copilot.tone.updated', { when: formatRelative(tone.updated_at, workspace.locale) })}</p>
                    </>
                )}
                {status.data && !status.data.available ? <p className="igr:text-xs igr:text-warning">{t('copilot.unavailable')}</p> : null}
                <FieldError message={learn.isError ? copilotError(learn.error, t('copilot.errors.generic')) : undefined} />
                {canLearn ? (
                    <Button size="sm" variant="outline" loading={learn.isPending} onClick={() => learn.mutate()}>
                        <Sparkles />
                        {tone === null ? t('copilot.tone.learn') : t('copilot.tone.relearn')}
                    </Button>
                ) : null}
            </CardContent>
        </Card>
    );
}
