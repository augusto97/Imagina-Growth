import { useMutation, useQueryClient } from '@tanstack/react-query';
import { wsPath } from '@/api/queries';
import { api } from '@/lib/api';
import type { AskAnswer } from '@/features/intelligence/api';
import type { AssistantKind, AssistantPage } from './model';

/* «Pregúntale a la IA» (POST ai/assistant, hub/routes/api/ai.php). */

export interface AssistantAction {
    kind: 'campaign' | 'flow' | 'page';
    prompt: string | null;
    page: string | null;
    /** Ruta dentro de `/w/{espacio}/` de un paso `page` (respuestas de antes). */
    path?: string | null;
}

/** Un paso concreto de la respuesta, con la pantalla donde se hace (`path` null: sin enlace). */
export interface AssistantStep {
    text: string;
    page: string | null;
    ref: string | null;
    path: string | null;
}

export interface AssistantAnswer extends AskAnswer {
    context: AssistantKind | null;
    actions: AssistantAction[];
    steps?: AssistantStep[];
    /** disabled: la IA está apagada; budget: se acabó el cupo del mes; provider, refusal: falló; numbers, empty, invalid: respondió sin IA. */
    fallback_reason: string | null;
    /** La IA no respondió esta vez: se puede volver a preguntar. */
    retryable?: boolean;
}

export function useAssistantAsk(ws: string) {
    const client = useQueryClient();
    return useMutation({
        mutationFn: (body: { question: string; page: AssistantPage; history: { question: string; answer: string }[] }) =>
            api.post<AssistantAnswer>(`${wsPath(ws)}/ai/assistant`, {
                question: body.question,
                page: body.page.id !== null ? { kind: body.page.kind, id: body.page.id } : { kind: body.page.kind },
                history: body.history,
            }),
        // El historial de «Pregúntale a tus datos» también muestra lo que se preguntó aquí.
        onSuccess: () => void client.invalidateQueries({ queryKey: ['ws', ws, 'ai', 'ask'] }),
    });
}
