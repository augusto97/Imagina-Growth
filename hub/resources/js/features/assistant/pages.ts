import pages from '../../../assistant/pages.json';

/**
 * Las pantallas a las que puede llevar un paso del asistente: la misma lista que usa el hub (`AssistantPages`,
 * `resources/assistant/pages.json`), clave → ruta dentro de `/w/{espacio}/`. El hub ya manda la ruta de cada paso; esto
 * sirve para las respuestas guardadas antes (un paso `page` sin ruta) y para que una prueba revise que cada ruta existe.
 */
export const ASSISTANT_PAGES: Readonly<Record<string, string>> = pages;

/** La ruta de una pantalla (no la de una estrategia, que necesita su clave). */
export function pagePath(key: string | null | undefined): string | null {
    const path = key ? ASSISTANT_PAGES[key] : undefined;
    return path !== undefined && !path.includes('{ref}') ? path : null;
}
