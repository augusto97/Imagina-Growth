import { MessageSquareText, Pin, Send, Users } from 'lucide-react';
import { useIsMutating } from '@tanstack/react-query';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useNavigate } from 'react-router';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { EmptyState, ErrorState, PageHeader } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { useWorkspace } from '@/hooks/useWorkspace';
import { isApiError } from '@/lib/api';
import { toast } from '@/stores/toast';
import { type AskAnswer, useAsk, useAskHistory, useSaveAnswer } from './api';
import { AnswerResult, SourceBadge } from './components';
import { resultsAudience } from '@/features/analytics/results';
import { examplesFor } from './logic';

/**
 * La caja de «Pregúntale a tus datos» con ejemplos según el negocio (tienda: ventas y pedidos; sin tienda: visitas de la
 * web). La usan esta pantalla y la entrada del Analista con IA (`goToAnswers` lleva a la respuesta).
 */
export function AskBox({ goToAnswers = false }: { goToAnswers?: boolean }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const ask = useAsk(workspace.id);
    const navigate = useNavigate();
    const [question, setQuestion] = useState('');
    const examples = examplesFor(resultsAudience(workspace));

    const submit = (e?: { preventDefault: () => void }, text?: string) => {
        e?.preventDefault();
        const q = (text ?? question).trim();
        if (q.length < 3 || ask.isPending) return;
        ask.mutate(q, {
            onSuccess: () => {
                setQuestion('');
                if (goToAnswers) void navigate(`/w/${workspace.id}/inteligencia/preguntale`);
            },
            onError: (err) => toast.error(isApiError(err) ? err.message : t('intelligence.ask.failed')),
        });
    };

    return (
                        <>
                        <form onSubmit={submit} className="igr:flex igr:flex-col igr:gap-2 igr:sm:flex-row">
                            <label htmlFor="ai-question" className="igr:sr-only">
                                {t('intelligence.ask.label')}
                            </label>
                            <input
                                id="ai-question"
                                value={question}
                                onChange={(e) => setQuestion(e.target.value)}
                                maxLength={500}
                                placeholder={t(`intelligence.ask.placeholder_${examples.kind}`)}
                                className="igr:h-10 igr:min-w-0 igr:flex-1 igr:rounded-md igr:border igr:border-input igr:bg-card igr:px-3 igr:text-[14px] igr:outline-none igr:focus-visible:border-ring igr:focus-visible:ring-3 igr:focus-visible:ring-ring/25"
                            />
                            <Button type="submit" loading={ask.isPending} disabled={question.trim().length < 3}>
                                <Send className="igr:size-3.5" aria-hidden />
                                {t('intelligence.ask.submit')}
                            </Button>
                        </form>
                        <p className="igr:mt-3 igr:text-caption igr:font-medium igr:text-muted-foreground">{t('intelligence.ask.examples')}</p>
                        <div className="igr:mt-1.5 igr:flex igr:flex-wrap igr:gap-1.5">
                            {examples.keys.map((key) => {
                                const ex = t(`intelligence.ask.examplesBy.${examples.kind}.${key}`);
                                return (
                                    <button
                                        key={key}
                                        type="button"
                                        disabled={ask.isPending}
                                        onClick={() => submit(undefined, ex)}
                                        className="igr:rounded-full igr:border igr:bg-card igr:px-3 igr:py-1 igr:text-left igr:text-caption igr:text-muted-foreground igr:outline-none igr:hover:bg-muted igr:hover:text-foreground igr:focus-visible:ring-2 igr:focus-visible:ring-ring igr:disabled:opacity-50"
                                    >
                                        {ex}
                                    </button>
                                );
                            })}
                        </div>
                        <p className="igr:mt-3 igr:text-xs igr:text-muted-foreground">{t('intelligence.ask.privacy')}</p>
                        {ask.isPending && goToAnswers ? <p className="igr:mt-2 igr:text-caption igr:text-muted-foreground" role="status">{t('intelligence.ask.thinking')}</p> : null}
                        </>
    );
}

/* «Pregúntale a tus datos» (inteligencia.md §6.3): el modelo arma la consulta, el hub la corre y la tabla sale de los datos. */
export function AskPage() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const history = useAskHistory(workspace.id);
    // La pregunta en curso (la hace AskBox): la respuesta que viene ocupa su lugar con un esqueleto.
    const asking = useIsMutating({ mutationKey: ['ws', workspace.id, 'ai', 'ask'] }) > 0;
    const canUse = workspace.abilities.includes('useAi');

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
                        <AskBox />
                    </CardContent>
                </Card>
            ) : (
                <p className="igr:mb-4 igr:text-[13px] igr:text-muted-foreground">{t('intelligence.ask.noPermission')}</p>
            )}
            {asking ? <Skeleton className="igr:mb-3 igr:h-40" /> : null}
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
