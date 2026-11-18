import type { SurveyOption, SurveyQuestions, SurveyUpdate } from './api';

/* Edición de las opciones de la encuesta. Las claves existentes se conservan para no romper las respuestas guardadas. */

export const MIN_OPTIONS = 2;
export const MAX_OPTIONS = 15;
export const LABEL_MAX = 80;

export interface DraftOption {
    /** Identificador local para React (no se envía). */
    uid: string;
    /** Clave guardada; null en opciones nuevas (el hub la genera desde la etiqueta). */
    key: string | null;
    label: string;
}

export interface SurveyDraft {
    status: 'active' | 'paused';
    title: string;
    question: string;
    options: DraftOption[];
    allow_other: boolean;
    other_label: string;
    thanks: string;
}

let counter = 0;
const uid = () => `opt-${String(++counter)}`;

export function toDraft(status: 'active' | 'paused', q: SurveyQuestions): SurveyDraft {
    return {
        status,
        title: q.title,
        question: q.question,
        options: q.options.map((o: SurveyOption) => ({ uid: uid(), key: o.key, label: o.label })),
        allow_other: q.allow_other,
        other_label: q.other_label,
        thanks: q.thanks,
    };
}

export function moveOption(options: readonly DraftOption[], index: number, delta: -1 | 1): DraftOption[] {
    const target = index + delta;
    if (index < 0 || index >= options.length || target < 0 || target >= options.length) {
        return [...options];
    }
    const next = [...options];
    const [item] = next.splice(index, 1);
    if (item) {
        next.splice(target, 0, item);
    }
    return next;
}

export function addOption(options: readonly DraftOption[], label = ''): DraftOption[] {
    return options.length >= MAX_OPTIONS ? [...options] : [...options, { uid: uid(), key: null, label }];
}

export function removeOption(options: readonly DraftOption[], index: number): DraftOption[] {
    return options.length <= MIN_OPTIONS ? [...options] : options.filter((_, i) => i !== index);
}

export type DraftProblem = 'title' | 'question' | 'thanks' | 'options' | 'emptyOption' | 'duplicateOption';

export function draftProblems(draft: SurveyDraft): DraftProblem[] {
    const problems: DraftProblem[] = [];
    if (draft.title.trim() === '') {
        problems.push('title');
    }
    if (draft.question.trim() === '') {
        problems.push('question');
    }
    if (draft.thanks.trim() === '') {
        problems.push('thanks');
    }
    if (draft.options.length < MIN_OPTIONS) {
        problems.push('options');
    }
    if (draft.options.some((o) => o.label.trim() === '')) {
        problems.push('emptyOption');
    }
    const labels = draft.options.map((o) => o.label.trim().toLocaleLowerCase()).filter((l) => l !== '');
    if (new Set(labels).size !== labels.length) {
        problems.push('duplicateOption');
    }
    return problems;
}

export function toPayload(draft: SurveyDraft): SurveyUpdate {
    return {
        status: draft.status,
        title: draft.title.trim(),
        question: draft.question.trim(),
        options: draft.options.map((o) => (o.key ? { key: o.key, label: o.label.trim() } : { label: o.label.trim() })),
        allow_other: draft.allow_other,
        other_label: draft.other_label.trim() === '' ? null : draft.other_label.trim(),
        thanks: draft.thanks.trim(),
    };
}

/** Respuestas de la más a la menos frecuente, con su porcentaje. */
export function breakdown(byAnswer: Readonly<Record<string, number>>, total: number): { answer: string; count: number; share: number }[] {
    return Object.entries(byAnswer)
        .map(([answer, count]) => ({ answer, count, share: total > 0 ? count / total : 0 }))
        .sort((a, b) => b.count - a.count || a.answer.localeCompare(b.answer));
}
