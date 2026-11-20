import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { wsPath } from '@/api/queries';
import { api } from '@/lib/api';

/*
 * Preguntas de calificación del chat (M18, auditoría §5.1 función 3): tipos, API y la
 * lógica del editor. Las reglas finales las valida el servidor (QualificationRules).
 */

export type QuestionType = 'choice' | 'yes_no' | 'number' | 'text';
export type RouteAction = 'owner' | 'seller' | 'agent' | 'web';
export type RouteLink = 'catalog' | 'quote' | 'booking' | 'custom';
export type RuleWhen = 'disqualified' | 'score_gte' | 'score_lt' | 'answer';

export interface QOption {
    key: string;
    label: string;
    points: number;
    disqualifies: boolean;
}

export interface Question {
    key: string;
    text: string;
    type: QuestionType;
    required: boolean;
    options?: QOption[];
    min?: number | null;
    max?: number | null;
    points?: number;
    out_of_range?: 'disqualify' | 'none';
}

export interface RouteTarget {
    action: RouteAction;
    link?: RouteLink;
    url?: string | null;
}

export interface RouteRule extends RouteTarget {
    when: RuleWhen;
    value?: number;
    question?: string;
    option?: string;
}

export interface QualificationSet {
    id: string;
    name: string;
    pipeline_id: string | null;
    services: string[];
    active: boolean;
    questions: Question[];
    routing: { rules: RouteRule[]; default: RouteTarget };
}

export type QualificationDraft = Omit<QualificationSet, 'id'>;

export interface QualificationIndex {
    sets: QualificationSet[];
    pipelines: { id: string; name: string }[];
    links: { catalog: string | null; booking: string };
    can_manage: boolean;
}

/** Lo que se ve en el lead y en el chat: cada respuesta con la frase del cliente. */
export interface LeadQualification {
    set: { id: string | null; name: string } | null;
    answers: { key: string; question: string; required: boolean; value: string | null; quote: string | null; points: number | null; disqualifies: boolean; at: string | null }[];
    score: number;
    disqualified: boolean;
    complete: boolean;
    route: { action: RouteAction; link?: RouteLink; at?: string } | null;
}

export const QUESTION_TYPES: QuestionType[] = ['choice', 'yes_no', 'number', 'text'];
export const ACTIONS: RouteAction[] = ['owner', 'seller', 'agent', 'web'];
export const LINKS: RouteLink[] = ['catalog', 'quote', 'booking', 'custom'];
export const WHEN: RuleWhen[] = ['disqualified', 'score_gte', 'score_lt', 'answer'];
export const MAX_QUESTIONS = 8;
export const MAX_OPTIONS = 8;
export const MAX_RULES = 10;

const keys = (ws: string) => ['ws', ws, 'leads', 'qualification'] as const;

export function useQualification(ws: string) {
    return useQuery({ queryKey: keys(ws), queryFn: ({ signal }) => api.get<QualificationIndex>(`${wsPath(ws)}/leads/qualification`, signal) });
}

export function useQualificationSets(ws: string) {
    const qc = useQueryClient();
    const done = () => void qc.invalidateQueries({ queryKey: keys(ws) });
    return {
        create: useMutation({ mutationFn: (body: QualificationDraft) => api.post<QualificationSet>(`${wsPath(ws)}/leads/qualification`, body), onSuccess: done }),
        update: useMutation({ mutationFn: ({ id, ...body }: QualificationSet) => api.put<QualificationSet>(`${wsPath(ws)}/leads/qualification/${id}`, body), onSuccess: done }),
        remove: useMutation({ mutationFn: (id: string) => api.delete(`${wsPath(ws)}/leads/qualification/${id}`), onSuccess: done }),
    };
}

/** Una clave libre `q1`, `q2`… (o `o1`… para opciones). */
export function nextKey(prefix: string, used: string[]): string {
    let i = used.length + 1;
    while (used.includes(`${prefix}${i}`)) i++;
    return `${prefix}${i}`;
}

/** Pregunta nueva del tipo pedido, con valores razonables. */
export function newQuestion(type: QuestionType, used: string[], labels: { yes: string; no: string; option: (n: number) => string }): Question {
    const base = { key: nextKey('q', used), text: '', type, required: true };
    switch (type) {
        case 'choice':
            return { ...base, options: [1, 2].map((n) => ({ key: `o${n}`, label: labels.option(n), points: 0, disqualifies: false })) };
        case 'yes_no':
            return {
                ...base,
                options: [
                    { key: 'yes', label: labels.yes, points: 0, disqualifies: false },
                    { key: 'no', label: labels.no, points: 0, disqualifies: false },
                ],
            };
        case 'number':
            return { ...base, min: null, max: null, points: 0, out_of_range: 'none' };
        default:
            return { ...base, points: 0 };
    }
}

/** Cambia el tipo conservando el texto, la clave y si es obligatoria. */
export function changeType(q: Question, type: QuestionType, labels: { yes: string; no: string; option: (n: number) => string }): Question {
    const fresh = newQuestion(type, [], labels);
    return { ...fresh, key: q.key, text: q.text, required: q.required };
}

export function emptyDraft(): QualificationDraft {
    return {
        name: '',
        pipeline_id: null,
        services: [],
        active: true,
        questions: [],
        routing: { rules: [{ when: 'disqualified', action: 'web', link: 'catalog', url: null }], default: { action: 'seller' } },
    };
}

/** Problemas que el editor muestra antes de guardar (claves de `qualification.errors`). */
export function draftErrors(d: QualificationDraft): string[] {
    const out = new Set<string>();
    if (d.name.trim() === '') out.add('name');
    if (d.questions.length === 0) out.add('noQuestions');
    for (const q of d.questions) {
        if (q.text.trim().length < 3) out.add('questionText');
        if (q.type === 'choice') {
            const labels = (q.options ?? []).map((o) => o.label.trim().toLowerCase());
            if (labels.length < 2 || labels.some((l) => l === '') || new Set(labels).size !== labels.length) out.add('options');
        }
        if (q.type === 'number' && q.min !== null && q.min !== undefined && q.max !== null && q.max !== undefined && q.min > q.max) out.add('range');
    }
    for (const r of [...d.routing.rules, d.routing.default]) {
        if (r.action === 'web' && (r.link === 'quote' || r.link === 'custom') && !(r.url ?? '').trim()) out.add('url');
        if (r.action === 'web' && (r.url ?? '').trim() !== '' && !/^https?:\/\/\S+\.\S+/.test((r.url ?? '').trim())) out.add('url');
    }
    for (const r of d.routing.rules) {
        if (r.when === 'answer') {
            const q = d.questions.find((x) => x.key === r.question);
            if (!q || !(q.options ?? []).some((o) => o.key === r.option)) out.add('ruleAnswer');
        }
    }
    return [...out];
}

/** Preguntas que sirven para una regla «si responde…» (las de opciones). */
export function choiceQuestions(d: Pick<QualificationDraft, 'questions'>): Question[] {
    return d.questions.filter((q) => (q.type === 'choice' || q.type === 'yes_no') && (q.options ?? []).length > 0);
}

/** Lista separada por comas, sin vacíos ni repetidos (palabras de servicio). */
export function parseWords(text: string): string[] {
    return [
        ...new Set(
            text
                .split(',')
                .map((w) => w.trim())
                .filter((w) => w !== ''),
        ),
    ].slice(0, 20);
}
