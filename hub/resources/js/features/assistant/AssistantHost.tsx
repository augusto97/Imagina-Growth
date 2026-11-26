import { lazy, Suspense, useEffect, useState } from 'react';
import { ROUTE_TEXTS } from '@/app/routeTexts';
import { loadTexts } from '@/lib/i18n';
import { useAssistant } from './model';

/** El panel y sus textos se bajan al abrirlo por primera vez (o al pasar el puntero por el botón). */
const loadPanel = () => Promise.all([import('./AssistantPanel'), loadTexts(ROUTE_TEXTS.assistant ?? [])]).then(([m]) => ({ default: m.AssistantPanel }));
const LazyPanel = lazy(loadPanel);

/** Precarga el panel (el botón lo llama al pasar el puntero o al enfocarlo). */
export function preloadAssistant(): void {
    void loadPanel();
}

/**
 * «Pregúntale a la IA» dentro de `WorkspaceLayout`: el atajo ⌘J / Ctrl+J (también dentro de un campo de texto, como
 * ⌘K) y el panel, que se monta la primera vez que se abre y queda montado para no perder la conversación.
 */
export function AssistantHost() {
    const open = useAssistant((s) => s.open);
    // Se monta la primera vez que se abre y no se desmonta: la conversación sigue al cerrar y volver a abrir.
    const [mounted, setMounted] = useState(open);
    if (open && !mounted) setMounted(true);

    useEffect(() => {
        const listener = (event: KeyboardEvent) => {
            if ((event.metaKey || event.ctrlKey) && !event.altKey && !event.shiftKey && event.key.toLowerCase() === 'j') {
                event.preventDefault();
                const state = useAssistant.getState();
                state.setOpen(!state.open);
            }
        };
        window.addEventListener('keydown', listener);
        return () => window.removeEventListener('keydown', listener);
    }, []);

    if (!mounted) return null;
    return (
        <Suspense fallback={null}>
            <LazyPanel />
        </Suspense>
    );
}
