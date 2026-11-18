import { createContext, useContext } from 'react';
import type { FlowOptions, NodeStats, Trigger } from '../types';

/** Lo que los nodos del lienzo necesitan sin pasarlo por `data`. */
export interface CanvasContextValue {
    options: FlowOptions | undefined;
    trigger: Trigger;
    issues: Record<string, string[]>;
    stats: Record<string, NodeStats> | null;
    readOnly: boolean;
    locale: string;
}

export const CanvasContext = createContext<CanvasContextValue | null>(null);

export function useCanvas(): CanvasContextValue {
    const ctx = useContext(CanvasContext);
    if (ctx === null) {
        throw new Error('useCanvas() fuera del constructor de flujos');
    }
    return ctx;
}
