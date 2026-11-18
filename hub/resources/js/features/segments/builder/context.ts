import { createContext, useContext } from 'react';
import type { Path } from '../ast';
import type { SegmentOptions } from '../types';

export interface BuilderContextValue {
    ws: string;
    options: SegmentOptions;
    readOnly: boolean;
    /** Errores del servidor por ruta («definition.conditions.0.value»). */
    errors: Record<string, string>;
    selfId?: string | undefined;
    currency: string;
    locale: string;
}

export const BuilderContext = createContext<BuilderContextValue | null>(null);

export function useBuilder(): BuilderContextValue {
    const ctx = useContext(BuilderContext);
    if (ctx === null) {
        throw new Error('useBuilder() fuera del constructor de segmentos');
    }
    return ctx;
}

/** Ruta del nodo tal como la nombra el validador del servidor. */
export function pathKey(path: Path): string {
    return ['definition', ...path.flatMap((i) => ['conditions', String(i)])].join('.');
}

/** Errores de este nodo (no los de sus hijos, que los muestra cada hijo). */
export function errorsFor(errors: Record<string, string>, path: Path): string[] {
    const key = pathKey(path);
    return Object.entries(errors)
        .filter(([k]) => k === key || (k.startsWith(`${key}.`) && !k.slice(key.length + 1).startsWith('conditions.')))
        .map(([, message]) => message);
}
