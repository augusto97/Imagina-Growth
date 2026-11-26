import { create } from 'zustand';

/*
 * «Pregúntale a la IA» en toda la app (inteligencia.md §6.3), sin React: de qué pantalla habla la persona (sale de la
 * URL, así ninguna página tiene que avisar), qué preguntas sugerir ahí y a qué pantalla lleva cada paso que propone.
 */

export type AssistantKind = 'home' | 'contact' | 'segment' | 'campaign' | 'flow' | 'conversation' | 'leads' | 'results' | 'other';

export interface AssistantPage {
    kind: AssistantKind;
    id: string | null;
}

/** Pantallas a las que puede llevar un paso (las mismas de `AiAssistant::PAGES` en el hub). */
export const ASSISTANT_PAGES: Record<string, string> = {
    segments: 'segmentos',
    campaigns: 'campanas',
    flows: 'flujos',
    templates: 'plantillas',
    insights: 'hallazgos',
    leads: 'leads',
    inbox: 'bandeja',
    results: 'resultados',
    contacts: 'contactos',
    strategies: 'estrategias',
    connections: 'conexiones',
    onsite: 'en-tu-web',
    reviews: 'resenas',
    loyalty: 'fidelizacion',
};

const DETAIL: { prefix: string; kind: AssistantKind }[] = [
    { prefix: 'contactos', kind: 'contact' },
    { prefix: 'segmentos', kind: 'segment' },
    { prefix: 'campanas', kind: 'campaign' },
    { prefix: 'flujos', kind: 'flow' },
    { prefix: 'bandeja', kind: 'conversation' },
];

const ID = /^[a-z]{2,4}_[0-9A-Za-z]{26}$/;

/** De qué habla la pantalla: el perfil, el grupo, la campaña, la automatización o la conversación abierta, o la sección. */
export function pageFromPath(pathname: string, base: string): AssistantPage {
    const rest = pathname.startsWith(base) ? pathname.slice(base.length) : pathname;
    const parts = rest.split('/').filter(Boolean);
    const [first, second] = parts;
    if (first === undefined) return { kind: 'home', id: null };
    for (const d of DETAIL) {
        if (first === d.prefix && second !== undefined && ID.test(second)) return { kind: d.kind, id: second };
    }
    if (first === 'leads' || first === 'hoy') return { kind: 'leads', id: null };
    if (first === 'resultados' || first === 'analitica' || first === 'reportes') return { kind: 'results', id: null };
    return { kind: 'other', id: null };
}

/** Las preguntas sugeridas en cada pantalla (claves de `assistant.suggestions.<tipo>`). */
export const SUGGESTION_KEYS: Record<AssistantKind, readonly string[]> = {
    home: ['week', 'best', 'today'],
    contact: ['who', 'next', 'risk'],
    segment: ['size', 'message', 'value'],
    campaign: ['result', 'improve', 'next'],
    flow: ['result', 'improve', 'holdout'],
    conversation: ['reply', 'customer', 'offer'],
    leads: ['hot', 'stuck', 'won'],
    results: ['week', 'channels', 'compare'],
    other: ['week', 'best', 'how'],
};

/** Lo último de la conversación del panel que viaja con la pregunta (el hub toma 3). */
export function historyFor(turns: { question: string; answer: string | null; status: string }[]): { question: string; answer: string }[] {
    return turns
        .filter((t) => t.status !== 'failed' && t.answer !== null && t.answer !== '')
        .slice(-3)
        .map((t) => ({ question: t.question.slice(0, 500), answer: (t.answer ?? '').slice(0, 1500) }));
}

interface AssistantState {
    open: boolean;
    /** Lo que se escribió en ⌘K («Preguntar a la IA: …») para preguntar al abrir. */
    pending: string | null;
    setOpen: (open: boolean) => void;
    ask: (question?: string) => void;
    takePending: () => string | null;
}

export const useAssistant = create<AssistantState>()((set, get) => ({
    open: false,
    pending: null,
    setOpen: (open) => set({ open }),
    ask: (question) => set({ open: true, pending: question !== undefined && question.trim() !== '' ? question.trim() : null }),
    takePending: () => {
        const pending = get().pending;
        if (pending !== null) set({ pending: null });
        return pending;
    },
}));
