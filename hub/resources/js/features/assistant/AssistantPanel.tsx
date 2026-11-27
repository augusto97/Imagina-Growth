import { ArrowRight, Bot, RotateCcw, Send, Settings, Sparkles, Users, Workflow } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useLocation } from 'react-router';
import { Button } from '@/components/ui/button';
import { Callout } from '@/components/ui/callout';
import { Textarea } from '@/components/ui/input';
import { Sheet, SheetBody, SheetContent, SheetDescription, SheetFooter, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { useBusiness } from '@/features/contacts/business';
import { CopilotDialog } from '@/features/copilot/CopilotDialog';
import { useSaveAnswer } from '@/features/intelligence/api';
import { AnswerResult, SourceBadge } from '@/features/intelligence/components';
import { useCan, useWorkspace } from '@/hooks/useWorkspace';
import { isApiError } from '@/lib/api';
import { toast } from '@/stores/toast';
import { type AssistantAction, type AssistantAnswer, type AssistantStep, useAssistantAsk } from './api';
import { SUGGESTION_KEYS, historyFor, pageFromPath, useAssistant } from './model';
import { pagePath } from './pages';
import { suggestionKey } from './suggestions';

/** Una vuelta de la conversación del panel: la pregunta y, cuando llega, la respuesta. */
interface Turn {
    key: number;
    question: string;
    answer: AssistantAnswer | null;
    error: string | null;
}

/**
 * «Pregúntale a la IA» (inteligencia.md §6.3): un panel lateral que se abre desde cualquier pantalla (botón de la barra,
 * ⌘J / Ctrl+J o ⌘K). Sabe en qué pantalla está la persona (`pageFromPath`) y sugiere preguntas para esa pantalla; las
 * cifras de las respuestas salen de los datos (el hub las valida) y los pasos que propone abren el copiloto o una
 * pantalla: nada se crea ni se envía sin que la persona lo revise. La conversación dura lo que dura la visita.
 */
export function AssistantPanel() {
    const { t } = useTranslation();
    const { workspace, me } = useWorkspace();
    const { open, setOpen, takePending } = useAssistant();
    const location = useLocation();
    const base = `/w/${workspace.id}`;
    const page = useMemo(() => pageFromPath(location.pathname, base), [location.pathname, base]);
    const { store } = useBusiness();
    const ask = useAssistantAsk(workspace.id);
    const [turns, setTurns] = useState<Turn[]>([]);
    const [question, setQuestion] = useState('');
    const [copilot, setCopilot] = useState<{ kind: 'campaign' | 'flow'; prompt: string } | null>(null);
    const counter = useRef(0);
    const end = useRef<HTMLDivElement>(null);
    const canUse = useCan('useAi');

    const send = (text: string) => {
        const q = text.trim();
        if (q.length < 2 || ask.isPending) return;
        const key = ++counter.current;
        const history = historyFor(
            turns.map((x) => ({ question: x.question, answer: x.answer?.answer ?? null, status: x.answer?.status ?? 'failed', steps: x.answer?.steps ?? [] })),
        );
        setTurns((list) => [...list, { key, question: q, answer: null, error: null }]);
        setQuestion('');
        ask.mutate(
            { question: q, page, history },
            {
                onSuccess: (answer) => setTurns((list) => list.map((x) => (x.key === key ? { ...x, answer } : x))),
                onError: (err) => setTurns((list) => list.map((x) => (x.key === key ? { ...x, error: isApiError(err) ? err.message : t('assistant.failed') } : x))),
            },
        );
    };

    // Volver a preguntar lo que no se pudo responder (la IA no respondió o se cortó la red).
    const retry = (turn: Turn) => {
        if (ask.isPending) return;
        setTurns((list) => list.filter((x) => x.key !== turn.key));
        send(turn.question);
    };

    // Lo que se escribió en ⌘K («Preguntar a la IA: …») se pregunta al abrir.
    useEffect(() => {
        if (!open) return;
        const pending = takePending();
        if (pending !== null) send(pending);
        // eslint-disable-next-line react-hooks/exhaustive-deps -- solo al abrir
    }, [open]);

    useEffect(() => {
        end.current?.scrollIntoView({ block: 'end' });
    }, [turns]);

    const suggestions = SUGGESTION_KEYS[page.kind].map((key) => t(suggestionKey(page.kind, key, store)));

    return (
        <>
            <Sheet open={open} onOpenChange={setOpen}>
                <SheetContent side="right" className="igr:sm:max-w-lg" aria-describedby="igr-assistant-desc">
                    <SheetHeader>
                        <SheetTitle className="igr:flex igr:items-center igr:gap-2">
                            <Sparkles className="igr:size-4 igr:text-primary" aria-hidden />
                            {t('assistant.title')}
                        </SheetTitle>
                        <SheetDescription id="igr-assistant-desc">{t(`assistant.context.${page.kind}`)}</SheetDescription>
                    </SheetHeader>
                    <SheetBody className="igr:space-y-4 igr:overflow-y-auto">
                        {!canUse ? (
                            <Callout tone="info" title={t('assistant.noPermissionTitle')}>
                                {t('assistant.noPermission')}
                            </Callout>
                        ) : turns.length === 0 ? (
                            <div className="igr:space-y-3">
                                <p className="igr:text-support igr:text-muted-foreground">{t('assistant.intro')}</p>
                                <p className="igr:text-caption igr:font-medium igr:text-muted-foreground">{t('assistant.try')}</p>
                                <ul className="igr:space-y-1.5">
                                    {suggestions.map((s) => (
                                        <li key={s}>
                                            <button
                                                type="button"
                                                onClick={() => send(s)}
                                                className="igr:flex igr:w-full igr:items-center igr:gap-2 igr:rounded-lg igr:border igr:bg-card igr:px-3 igr:py-2 igr:text-left igr:text-[13px] igr:hover:bg-muted igr:focus-visible:ring-2 igr:focus-visible:ring-ring igr:outline-none"
                                            >
                                                <ArrowRight className="igr:size-3.5 igr:shrink-0 igr:text-primary" aria-hidden />
                                                {s}
                                            </button>
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        ) : (
                            <ol className="igr:space-y-4" aria-live="polite">
                                {turns.map((turn) => (
                                    <li key={turn.key} className="igr:space-y-2">
                                        <p className="igr:ml-auto igr:w-fit igr:max-w-[85%] igr:rounded-2xl igr:rounded-br-sm igr:bg-primary igr:px-3 igr:py-2 igr:text-[13px] igr:text-primary-foreground">
                                            {turn.question}
                                        </p>
                                        {turn.error ? (
                                            <Callout tone="danger" title={t('assistant.failedTitle')}>
                                                <p>{turn.error}</p>
                                                <RetryButton onClick={() => retry(turn)} disabled={ask.isPending} />
                                            </Callout>
                                        ) : turn.answer === null ? (
                                            <Thinking />
                                        ) : (
                                            <AnswerView
                                                answer={turn.answer}
                                                platformAdmin={me.user.is_platform_admin}
                                                onCopilot={(kind, prompt) => setCopilot({ kind, prompt })}
                                                onNavigate={() => setOpen(false)}
                                                onRetry={() => retry(turn)}
                                                busy={ask.isPending}
                                            />
                                        )}
                                    </li>
                                ))}
                            </ol>
                        )}
                        <div ref={end} />
                    </SheetBody>
                    {canUse ? (
                        <SheetFooter className="igr:block igr:space-y-1.5">
                            <form
                                className="igr:flex igr:items-end igr:gap-2"
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    send(question);
                                }}
                            >
                                <label htmlFor="igr-assistant-question" className="igr:sr-only">
                                    {t('assistant.label')}
                                </label>
                                <Textarea
                                    id="igr-assistant-question"
                                    rows={2}
                                    maxLength={500}
                                    value={question}
                                    placeholder={t('assistant.placeholder')}
                                    className="igr:min-h-0 igr:flex-1 igr:resize-none"
                                    onChange={(e) => setQuestion(e.target.value)}
                                    onKeyDown={(e) => {
                                        if (e.key === 'Enter' && !e.shiftKey) {
                                            e.preventDefault();
                                            send(question);
                                        }
                                    }}
                                />
                                <Button type="submit" size="icon" loading={ask.isPending} disabled={question.trim().length < 2} aria-label={t('assistant.send')}>
                                    <Send />
                                </Button>
                            </form>
                            <p className="igr:text-caption igr:text-muted-foreground">{t('assistant.privacy')}</p>
                        </SheetFooter>
                    ) : null}
                </SheetContent>
            </Sheet>
            {copilot !== null ? <CopilotDialog kind={copilot.kind} initialPrompt={copilot.prompt} onClose={() => setCopilot(null)} /> : null}
        </>
    );
}

/** «Pensando»: puntos que se mueven y, si tarda, un aviso de que sigue trabajando (nunca un silencio largo). */
function Thinking() {
    const { t } = useTranslation();
    const [elapsed, setElapsed] = useState(0);
    useEffect(() => {
        const started = Date.now();
        const timer = window.setInterval(() => setElapsed(Math.floor((Date.now() - started) / 1000)), 1000);
        return () => window.clearInterval(timer);
    }, []);
    const message = elapsed >= 40 ? t('assistant.thinkingVeryLong') : elapsed >= 8 ? t('assistant.thinkingLong') : t('assistant.thinking');
    return (
        <p className="igr:flex igr:items-center igr:gap-2 igr:text-caption igr:text-muted-foreground" role="status">
            <Bot className="igr:size-4 igr:shrink-0 igr:animate-pulse" aria-hidden />
            <span className="igr:inline-flex igr:gap-0.5" aria-hidden>
                <span className="igr:size-1 igr:animate-bounce igr:rounded-full igr:bg-current" />
                <span className="igr:size-1 igr:animate-bounce igr:rounded-full igr:bg-current igr:[animation-delay:150ms]" />
                <span className="igr:size-1 igr:animate-bounce igr:rounded-full igr:bg-current igr:[animation-delay:300ms]" />
            </span>
            {message}
        </p>
    );
}

function RetryButton({ onClick, disabled }: { onClick: () => void; disabled: boolean }) {
    const { t } = useTranslation();
    return (
        <Button size="sm" variant="outline" className="igr:mt-2" onClick={onClick} disabled={disabled}>
            <RotateCcw />
            {t('assistant.retry')}
        </Button>
    );
}

/** Los pasos de la respuesta, numerados; los que tienen pantalla llevan allá (y cierran el panel). */
function StepList({ steps, onNavigate }: { steps: AssistantStep[]; onNavigate: () => void }) {
    const { workspace } = useWorkspace();
    if (steps.length === 0) return null;
    return (
        <ol className="igr:space-y-1.5">
            {steps.map((step, i) => (
                <li key={`${i}-${step.text}`} className="igr:flex igr:items-start igr:gap-2 igr:text-[13px]">
                    <span className="igr:mt-px igr:flex igr:size-5 igr:shrink-0 igr:items-center igr:justify-center igr:rounded-full igr:bg-brand-soft igr:text-caption igr:font-medium igr:text-brand-soft-foreground">
                        {i + 1}
                    </span>
                    {step.path !== null ? (
                        <Link
                            to={`/w/${workspace.id}/${step.path}`}
                            onClick={onNavigate}
                            className="igr:group igr:inline-flex igr:items-start igr:gap-1 igr:font-medium igr:text-primary igr:underline-offset-2 igr:hover:underline igr:focus-visible:underline igr:outline-none"
                        >
                            {step.text}
                            <ArrowRight className="igr:mt-0.5 igr:size-3.5 igr:shrink-0 igr:transition-transform igr:group-hover:translate-x-0.5" aria-hidden />
                        </Link>
                    ) : (
                        <span>{step.text}</span>
                    )}
                </li>
            ))}
        </ol>
    );
}

function AnswerView({
    answer,
    platformAdmin,
    onCopilot,
    onNavigate,
    onRetry,
    busy,
}: {
    answer: AssistantAnswer;
    platformAdmin: boolean;
    onCopilot: (kind: 'campaign' | 'flow', prompt: string) => void;
    onNavigate: () => void;
    onRetry: () => void;
    busy: boolean;
}) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const save = useSaveAnswer(workspace.id);
    const [saved, setSaved] = useState(answer.saved_as !== null);
    const off = answer.fallback_reason === 'disabled' || answer.fallback_reason === 'budget';

    if (off) {
        return (
            <Callout tone="warning" title={t(answer.fallback_reason === 'budget' ? 'assistant.budgetTitle' : 'assistant.offTitle')}>
                <p>{answer.answer}</p>
                {answer.fallback_reason === 'disabled' && platformAdmin ? (
                    <Button asChild size="sm" variant="outline" className="igr:mt-2" onClick={onNavigate}>
                        <Link to={`/w/${workspace.id}/plataforma?pestana=ajustes&seccion=ai`}>
                            <Settings />
                            {t('assistant.turnOn')}
                        </Link>
                    </Button>
                ) : null}
            </Callout>
        );
    }

    // La IA no pudo responder (proveedor caído, rechazo): se dice en palabras y, si sirve, se vuelve a preguntar.
    if (answer.status === 'failed') {
        return (
            <Callout tone="warning" title={t('assistant.failedTitle')}>
                <p>{answer.answer}</p>
                {answer.retryable ? <RetryButton onClick={onRetry} disabled={busy} /> : null}
            </Callout>
        );
    }

    return (
        <div className="igr:space-y-2.5 igr:rounded-2xl igr:rounded-bl-sm igr:border igr:bg-card igr:p-3">
            <div className="igr:flex igr:items-start igr:justify-between igr:gap-2">
                <p className="igr:text-[13px] igr:whitespace-pre-line">{answer.answer}</p>
                <SourceBadge source={answer.source} reason={answer.fallback_reason ?? undefined} />
            </div>
            <StepList steps={answer.steps ?? []} onNavigate={onNavigate} />
            {answer.result ? (
                <div className="igr:min-w-0 igr:overflow-x-auto">
                    <AnswerResult result={answer.result} granularity={answer.query?.granularity} />
                </div>
            ) : null}
            <div className="igr:flex igr:flex-wrap igr:gap-1.5">
                {answer.result?.segment_available && !saved ? (
                    <Button
                        size="sm"
                        variant="outline"
                        loading={save.isPending}
                        onClick={() =>
                            save.mutate(
                                { id: answer.id, as: 'segment' },
                                {
                                    onSuccess: () => {
                                        setSaved(true);
                                        toast.success(t('assistant.savedSegment'));
                                    },
                                    onError: (err) => toast.error(isApiError(err) ? err.message : t('assistant.failed')),
                                },
                            )
                        }
                    >
                        <Users />
                        {t('assistant.saveSegment')}
                    </Button>
                ) : null}
                {answer.actions.map((action) => (
                    <ActionButton key={`${action.kind}-${action.page ?? action.prompt ?? ''}`} action={action} onCopilot={onCopilot} onNavigate={onNavigate} />
                ))}
            </div>
        </div>
    );
}

function ActionButton({ action, onCopilot, onNavigate }: { action: AssistantAction; onCopilot: (kind: 'campaign' | 'flow', prompt: string) => void; onNavigate: () => void }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    if ((action.kind === 'campaign' || action.kind === 'flow') && action.prompt) {
        const prompt = action.prompt;
        const kind = action.kind;
        return (
            <Button size="sm" variant="brand" onClick={() => onCopilot(kind, prompt)} title={prompt}>
                {kind === 'campaign' ? <Send /> : <Workflow />}
                {t(`assistant.actions.${kind}`)}
            </Button>
        );
    }
    const path = action.path ?? pagePath(action.page);
    if (path === null) return null;
    return (
        <Button asChild size="sm" variant="outline" onClick={onNavigate}>
            <Link to={`/w/${workspace.id}/${path}`}>
                <ArrowRight />
                {t(`assistant.pages.${action.page ?? ''}`)}
            </Link>
        </Button>
    );
}
