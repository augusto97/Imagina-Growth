import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { wsPath } from '@/api/queries';
import { api } from '@/lib/api';

/* Encuesta «¿Cómo nos conociste?» (modulos.md M12, adelantada a la Fase 1). */

export interface SurveyOption {
    key: string;
    label: string;
}

export interface SurveyQuestions {
    title: string;
    question: string;
    options: SurveyOption[];
    allow_other: boolean;
    other_label: string;
    thanks: string;
}

export interface HdyhauSurvey {
    id: string;
    status: 'active' | 'paused';
    questions: SurveyQuestions;
    responses: { total: number; by_answer: Record<string, number> };
}

export interface SurveyUpdate {
    status: 'active' | 'paused';
    title: string;
    question: string;
    options: { key?: string | null; label: string }[];
    allow_other: boolean;
    other_label: string | null;
    thanks: string;
}

/** Clave de la respuesta «Otro» (PublicWidgetsController). */
export const OTHER_KEY = 'otro';

const key = (ws: string) => ['ws', ws, 'survey', 'hdyhau'] as const;

export function useHdyhauSurvey(ws: string, enabled = true) {
    return useQuery({
        queryKey: key(ws),
        queryFn: ({ signal }) => api.get<HdyhauSurvey>(`${wsPath(ws)}/surveys/hdyhau`, signal),
        enabled,
        staleTime: 60_000,
    });
}

export function useUpdateHdyhauSurvey(ws: string) {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: (data: SurveyUpdate) => api.put<HdyhauSurvey>(`${wsPath(ws)}/surveys/hdyhau`, data),
        onSuccess: (survey) => qc.setQueryData(key(ws), survey),
    });
}

/** Etiqueta legible de una respuesta guardada (clave de opción u «otro»). */
export function answerLabel(survey: HdyhauSurvey | undefined, answer: string | null | undefined): string | null {
    if (answer === null || answer === undefined || answer === '') {
        return null;
    }
    if (survey === undefined) {
        return answer;
    }
    if (answer === OTHER_KEY) {
        return survey.questions.other_label;
    }
    return survey.questions.options.find((o) => o.key === answer)?.label ?? answer;
}
