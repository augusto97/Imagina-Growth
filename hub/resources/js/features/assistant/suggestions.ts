import type { AssistantKind } from './model';

/**
 * La llave de una pregunta sugerida: un negocio sin tienda (servicios, leads, citas) no ve preguntas de ventas ni de
 * productos (`assistant.suggestionsServices`, mismas llaves que `assistant.suggestions`). Va aparte de `model.ts`
 * porque el marco de la app importa ese archivo y sus textos no deben cargarse al arrancar.
 */
export function suggestionKey(kind: AssistantKind, key: string, store: boolean): string {
    return `assistant.${store ? 'suggestions' : 'suggestionsServices'}.${kind}.${key}`;
}
