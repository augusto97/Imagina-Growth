import { MessageSquareText, Pin, Send, Users } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { EmptyState, ErrorState, PageHeader } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { useWorkspace } from '@/hooks/useWorkspace';
import { isApiError } from '@/lib/api';
import { toast } from '@/stores/toast';
import { type AskAnswer, useAsk, useAskHistory, useSaveAnswer } from './api';
import { AnswerResult, SourceBadge } from './components';
import { EXAMPLES } from './logic';

/* «Pregúntale a tus datos» (inteligencia.md §6.3): el modelo arma la consulta, el hub la corre y la tabla sale de los datos. */
export function AskPage() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const history = useAskHistory(workspace.id);
    const ask = useAsk(workspace.id);
    const [question, setQuestion] = useState('');
    const canUse = workspace.abilities.includes('useAi');

    const submit = (e?: { preventDefault: () => void }, text?: string) => {
        e?.preventDefault();
        const q = (text ?? question).trim();
        if (q.length < 3 || ask.isPending) return;
        ask.mutate(q, {
            onSuccess: () => setQuestion(''),
            onError: (err) => toast.error(isApiError(err) ? err.message : t('intelligence.ask.failed')),
        });
    };

    return (
        <>
            <PageHeader
                title={t('intelligence.ask.title')}
                description={t('intelligence.ask.description')}
                actions={
                    <Button asChild variant="outline" size="sm">
                        <Link to={`/w/${workspace.id}/inteligencia`}>{t('intelligence.title')}</Link>
                    </Button>
                }
            />
            {canUse ? (
                <Card className="igr:mb-4">
                    <CardContent className="igr:pt-4">
                        <form onSubmit={submit} className="igr:flex igr:gap-2">
                            <label htmlFor="ai-question" className="igr:sr-only">
                                {t('intelligence.ask.label')}
                            </label>
                            <input
                                id="ai-question"
                                value={question}
                                onChange={(e) => setQuestion(e.target.value)}
                                maxLength={500}
                                placeholder={t('intelligence.ask.placeholder')}
                                className="igr:h-9 igr:flex-1 igr:rounded-md igr:border igr:bg-background igr:px-3 igr:text-[13px] igr:outline-none igr:focus-visible:ring-2 igr:focus-visible:ring-ring"
                            />
                            <Button type="submit" loading={ask.isPending} disabled={question.trim().length < 3}>
                                <Send className="igr:size-3.5" aria-hidden />
                                {t('intelligence.ask.submit')}
                            </Button>
                        </form>
                        <div className="igr:mt-3 igr:flex igr:flex-wrap igr:gap-1.5" aria-label={t('intelligence.ask.examples')}>
                            {EXAMPLES.map((ex) => (
                                <button
                                    key={ex}
                                    type="button"
                                    disabled={ask.isPending}
                                    onClick={() => submit(undefined, ex)}
                                    className="igr:rounded-full igr:border igr:px-2.5 igr:py-1 igr:text-xs igr:text-muted-foreground igr:hover:bg-muted igr:hover:text-foreground igr:disabled:opacity-50"
                                >
                                    {ex}
                                </button>
                            ))}
                        </div>
                        <p className="igr:mt-3 igr:text-xs igr:text-muted-foreground">{t('intelligence.ask.privacy')}</p>
                    </CardContent>
                </Card>
            ) : (
                <p className="igr:mb-4 igr:text-[13px] igr:text-muted-foreground">{t('intelligence.ask.noPermission')}</p>
            )}
            {ask.isPending ? <Skeleton className="igr:mb-3 igr:h-40" /> : null}
            {history.isError ? (
                <ErrorState error={history.error} onRetry={() => void history.refetch()} />
            ) : history.isPending ? (
                <Skeleton className="igr:h-40" />
            ) : history.data.length === 0 ? (
                <EmptyState icon={MessageSquareText} title={t('intelligence.ask.empty')} description={t('intelligence.ask.emptyHelp')} />
            ) : (
                <div className="igr:space-y-3">
                    {history.data.map((a) => (
                        <AnswerCard key={a.id} answer={a} />
                    ))}
                </div>
            )}
        </>
    );
}

function AnswerCard({ answer }: { answer: AskAnswer }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const save = useSaveAnswer(workspace.id);
    const canSegment = workspace.abilities.includes('manageMarketing') && answer.result?.segment_available === true && answer.saved_as !== 'segment';

    const doSave = (as: 'segment' | 'dashboard') =>
        save.mutate(
            { id: answer.id, as },
            {
                onSuccess: () => toast.success(as === 'segment' ? t('intelligence.ask.savedSegment') : t('intelligence.ask.pinned')),
                onError: (err) => toast.error(isApiError(err) ? err.message : t('intelligence.ask.failed')),
            },
        );

    return (
        <Card>
            <CardContent className="igr:space-y-3 igr:pt-4">
                <p className="igr:text-[13px] igr:font-semibold">{answer.question}</p>
                {answer.answer ? (
                    <div className="igr:flex igr:items-start igr:gap-2">
                        <p className="igr:flex-1 igr:text-[13px] igr:leading-relaxed">{answer.answer}</p>
                        {answer.status !== 'failed' ? <SourceBadge source={answer.source} /> : null}
                    </div>
                ) : null}
                {answer.result ? <AnswerResult result={answer.result} granularity={answer.query?.granularity} /> : null}
                {answer.result && workspace.abilities.includes('useAi') ? (
                    <div className="igr:flex igr:flex-wrap igr:gap-2 igr:border-t igr:pt-3">
                        {answer.saved_as !== 'dashboard' ? (
                            <Button variant="outline" size="sm" onClick={() => doSave('dashboard')} loading={save.isPending && save.variables.as === 'dashboard'}>
                                <Pin className="igr:size-3.5" aria-hidden />
                                {t('intelligence.ask.pin')}
                            </Button>
                        ) : null}
                        {canSegment ? (
                            <Button variant="outline" size="sm" onClick={() => doSave('segment')} loading={save.isPending && save.variables.as === 'segment'}>
                                <Users className="igr:size-3.5" aria-hidden />
                                {t('intelligence.ask.saveSegment')}
                            </Button>
                        ) : null}
                    </div>
                ) : null}
            </CardContent>
        </Card>
    );
}
