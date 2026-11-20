import { createContext, useContext } from 'react';
import type { FlowOptions, Goal, NodeStats, Trigger } from '../types';

/** Lo que los nodos y las conexiones del lienzo necesitan sin pasarlo por `data`. */
export interface CanvasContextValue {
    options: FlowOptions | undefined;
    trigger: Trigger;
    goal?: Goal | null;
    issues: Record<string, string[]>;
    stats: Record<string, NodeStats> | null;
    readOnly: boolean;
    locale: string;
    /** Salidas con conexión de cada paso, para ofrecer «+» en las libres. */
    connected?: Record<string, string[]>;
    /** Agrega un paso después de una salida libre de un paso. */
    onAddAfter?: (nodeId: string, branch: string | null, type: string) => void;
    /** Agrega un paso en medio de una conexión. */
    onInsert?: (edgeId: string, type: string) => void;
}

export const CanvasContext = createContext<CanvasContextValue | null>(null);

export function useCanvas(): CanvasContextValue {
    const ctx = useContext(CanvasContext);
    if (ctx === null) {
        throw new Error('useCanvas() fuera del constructor de flujos');
    }
    return ctx;
}
