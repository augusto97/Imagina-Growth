import type { Workspace } from '@/api/types';

/*
 * Tienda, servicios o los dos, con la misma regla que el menú (components/layout/nav.ts `itemVisible`): lo que eligió
 * el asistente (o implica la vertical); sin eso, lo que dicen los sitios (`capabilities`); sin ninguna señal, los dos.
 * Lo usan las pantallas de Mensajes para hablar como el negocio (nunca «carrito» ni «pedido» sin tienda).
 */

export type BusinessKind = 'store' | 'services' | 'both';

export function businessKind(workspace: Pick<Workspace, 'business_type' | 'capabilities'>): BusinessKind {
    const declared = workspace.business_type ?? null;
    if (declared === 'store' || declared === 'services' || declared === 'both') {
        return declared;
    }
    const caps = workspace.capabilities ?? null;
    if (caps?.store === true && caps.services !== true) {
        return 'store';
    }
    if (caps?.services === true && caps.store !== true) {
        return 'services';
    }
    return 'both';
}

/** ¿Algo para `audience` aplica a este negocio? */
export function servesAudience(kind: BusinessKind, audience: 'store' | 'services' | 'both'): boolean {
    return audience === 'both' || kind === 'both' || audience === kind;
}
