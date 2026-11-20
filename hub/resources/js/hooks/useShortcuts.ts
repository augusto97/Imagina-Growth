import { useEffect } from 'react';

function isTyping(target: EventTarget | null): boolean {
    if (!(target instanceof HTMLElement)) {
        return false;
    }
    return target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName);
}

/**
 * Atajos globales: ⌘K / Ctrl+K abre la paleta, ⌘B / Ctrl+B contrae o expande la barra lateral del escritorio (fuera de
 * los campos de texto, donde suele ser «negrita») y las secuencias «g x» navegan (g h inicio, g c contactos, g s sitios,
 * g e equipo).
 */
export function useShortcuts(handlers: { onPalette: () => void; onGo: (key: string) => void; onToggleSidebar?: () => void }): void {
    const { onPalette, onGo, onToggleSidebar } = handlers;
    useEffect(() => {
        let pendingG = 0;
        const listener = (event: KeyboardEvent) => {
            if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
                event.preventDefault();
                onPalette();
                return;
            }
            if (onToggleSidebar && (event.metaKey || event.ctrlKey) && !event.altKey && !event.shiftKey && event.key.toLowerCase() === 'b' && !isTyping(event.target)) {
                event.preventDefault();
                onToggleSidebar();
                return;
            }
            if (event.metaKey || event.ctrlKey || event.altKey || isTyping(event.target)) {
                return;
            }
            const now = Date.now();
            if (event.key === 'g') {
                pendingG = now;
                return;
            }
            if (now - pendingG < 900) {
                pendingG = 0;
                onGo(event.key.toLowerCase());
            }
        };
        window.addEventListener('keydown', listener);
        return () => window.removeEventListener('keydown', listener);
    }, [onPalette, onGo, onToggleSidebar]);
}
