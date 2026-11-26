import { Calculator } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Skeleton } from '@/components/ui/skeleton';
import { useWorkspace } from '@/hooks/useWorkspace';
import { isApiError } from '@/lib/api';
import { useQuoteAssistant } from './api';
import { describeAnswers, exampleScenarios, formatRange } from './model';
import type { CalculatorKind, QuoteDefinition, ScenarioResult } from './types';

/**
 * Dos ejemplos de lo que verá un cliente con estos precios, calculados en el hub con el mismo motor que el cotizador
 * público: «Si alguien pide 40 m² · Lavable premium, ve $686.000 – $839.000».
 */
export function PriceExamples({ kind, definition, currency }: { kind: CalculatorKind; definition: QuoteDefinition; currency: string }) {
    const { t, i18n } = useTranslation();
    const tr = (k: string, o?: Record<string, unknown>) => t(k, o ?? {});
    const { workspace } = useWorkspace();
    const { scenarios } = useQuoteAssistant(workspace.id);
    const { mutate } = scenarios;
    const examples = useMemo(() => exampleScenarios(definition), [definition]);
    const [results, setResults] = useState<ScenarioResult[] | null>(null);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        const handle = window.setTimeout(() => {
            mutate(
                { kind, definition, scenarios: examples },
                {
                    onSuccess: (r) => {
                        setResults(r.data);
                        setError(null);
                    },
                    onError: (e) => {
                        setResults(null);
                        setError(isApiError(e) ? (Object.values(e.fieldErrors)[0] ?? e.message) : t('errors.generic'));
                    },
                },
            );
        }, 400);
        return () => window.clearTimeout(handle);
    }, [kind, definition, examples, mutate, t]);

    return (
        <div className="igr:space-y-3" aria-live="polite">
            <p className="igr:flex igr:items-center igr:gap-2 igr:text-sm igr:font-semibold">
                <Calculator className="igr:size-4 igr:text-primary" aria-hidden />
                {t('quotes.wizard.examples')}
            </p>
            {error ? (
                <p className="igr:text-support igr:text-muted-foreground">{error}</p>
            ) : results === null ? (
                <>
                    <Skeleton className="igr:h-14" />
                    <Skeleton className="igr:h-14" />
                </>
            ) : (
                examples.map((answers, i) => {
                    const r = results[i];
                    return (
                        <div key={i} className="igr:rounded-xl igr:border igr:bg-card igr:p-3">
                            <p className="igr:text-caption igr:text-muted-foreground">
                                {t('quotes.wizard.ifSomeone', { what: describeAnswers(definition, answers, tr, i18n.language) || t('quotes.wizard.basic') })}
                            </p>
                            <p className="igr:mt-0.5 igr:text-section igr:font-semibold igr:tabular-nums">
                                {r?.ok ? formatRange(r.min, r.max, currency, i18n.language) : (r?.message ?? '—')}
                            </p>
                        </div>
                    );
                })
            )}
            <p className="igr:text-caption igr:text-muted-foreground">{t('quotes.wizard.examplesHelp')}</p>
        </div>
    );
}
