import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { wsPath } from '@/api/queries';
import { api } from '@/lib/api';
import type {
    Calculator,
    CalculatorKind,
    CalculatorList,
    Estimate,
    QuoteCaptureSettings,
    QuoteDefinition,
    QuoteProposal,
    QuoteRequest,
    QuoteTemplate,
    ScenarioResult,
} from './types';

export const quoteKeys = {
    all: (ws: string) => ['ws', ws, 'quotes'] as const,
    list: (ws: string) => ['ws', ws, 'quotes', 'list'] as const,
    one: (ws: string, id: string) => ['ws', ws, 'quotes', 'one', id] as const,
    requests: (ws: string, calculator: string, page: number) => ['ws', ws, 'quotes', 'requests', calculator, page] as const,
    templates: (ws: string) => ['ws', ws, 'quotes', 'templates'] as const,
};

const base = (ws: string) => `${wsPath(ws)}/quotes`;

export function useCalculators(ws: string) {
    return useQuery({ queryKey: quoteKeys.list(ws), queryFn: ({ signal }) => api.get<CalculatorList>(`${base(ws)}/calculators`, signal) });
}

export function useCalculator(ws: string, id: string) {
    return useQuery({ queryKey: quoteKeys.one(ws, id), queryFn: ({ signal }) => api.get<Calculator>(`${base(ws)}/calculators/${encodeURIComponent(id)}`, signal) });
}

export function useQuoteRequests(ws: string, calculator: string, page: number) {
    const qs = new URLSearchParams({ page: String(page) });
    if (calculator !== '') qs.set('calculator', calculator);
    return useQuery({
        queryKey: quoteKeys.requests(ws, calculator, page),
        queryFn: ({ signal }) => api.get<{ data: QuoteRequest[]; meta: { current_page: number; last_page: number; total: number } }>(`${base(ws)}/requests?${qs.toString()}`, signal),
        placeholderData: keepPreviousData,
    });
}

export function useQuoteMutations(ws: string) {
    const qc = useQueryClient();
    const done = () => void qc.invalidateQueries({ queryKey: quoteKeys.all(ws) });
    return {
        save: useMutation({
            mutationFn: ({ id, data }: { id: string | null; data: Partial<Calculator> }) =>
                id === null ? api.post<Calculator>(`${base(ws)}/calculators`, data) : api.patch<Calculator>(`${base(ws)}/calculators/${encodeURIComponent(id)}`, data),
            onSuccess: (data) => {
                qc.setQueryData(quoteKeys.one(ws, data.id), data);
                done();
            },
        }),
        remove: useMutation({ mutationFn: (id: string) => api.delete<null>(`${base(ws)}/calculators/${encodeURIComponent(id)}`), onSuccess: done }),
        preview: useMutation({
            mutationFn: ({ id, answers, definition, kind }: { id: string; answers: Record<string, unknown>; definition?: unknown; kind?: string }) =>
                api.post<Estimate>(`${base(ws)}/calculators/${encodeURIComponent(id)}/preview`, { answers, definition, kind }),
        }),
    };
}

/** Plantillas por oficio del asistente (no cambian mientras se usa la pantalla). */
export function useQuoteTemplates(ws: string) {
    return useQuery({
        queryKey: quoteKeys.templates(ws),
        queryFn: ({ signal }) => api.get<{ currency: string; data: QuoteTemplate[] }>(`${base(ws)}/templates`, signal),
        staleTime: 10 * 60_000,
    });
}

/** El asistente: la propuesta con IA, los ejemplos de cálculo y la vista previa de la página (nada se guarda). */
export function useQuoteAssistant(ws: string) {
    return {
        propose: useMutation({ mutationFn: (description: string) => api.post<QuoteProposal>(`${base(ws)}/assistant`, { description }) }),
        scenarios: useMutation({
            mutationFn: (body: { kind: CalculatorKind; definition: QuoteDefinition; scenarios: Record<string, unknown>[] }) =>
                api.post<{ data: ScenarioResult[] }>(`${base(ws)}/preview`, body),
        }),
        pagePreview: useMutation({
            mutationFn: (body: { name: string; kind: CalculatorKind; definition: QuoteDefinition; capture: QuoteCaptureSettings }) =>
                api.post<{ url: string }>(`${base(ws)}/page-preview`, body),
        }),
    };
}

/** El QR de la página del cotizador (SVG para ver e imprimir, PNG para redes). */
export function quoteQrPaths(ws: string, id: string): { svg: string; png: string } {
    const path = `${base(ws)}/calculators/${encodeURIComponent(id)}/qr`;
    return { svg: `${path}?format=svg`, png: `${path}?format=png` };
}
