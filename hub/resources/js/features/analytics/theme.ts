import { useSyncExternalStore } from 'react';

/*
 * Colores de los gráficos (skill dataviz, paleta de referencia validada en claro y
 * oscuro con validate_palette.js). Categórica en orden fijo: azul, naranja, aqua.
 * El aqua en claro queda bajo 3:1 de contraste: por eso cada gráfico con varias
 * series lleva leyenda, etiquetas directas o su vista de tabla (regla de alivio).
 */

const LIGHT = {
    series: ['#2a78d6', '#eb6834', '#1baf7a'],
    muted: '#898781',
    grid: '#e1e0d9',
    axis: '#c3c2b7',
    surface: '#fcfcfb',
    // Secuencial azul 100→650: lo más claro es «casi cero».
    sequential: ['#cde2fb', '#b7d3f6', '#9ec5f4', '#86b6ef', '#6da7ec', '#5598e7', '#3987e5', '#2a78d6', '#256abf', '#1c5cab', '#184f95', '#104281'],
};

const DARK = {
    series: ['#3987e5', '#d95926', '#199e70'],
    muted: '#898781',
    grid: '#2c2c2a',
    axis: '#383835',
    surface: '#1a1a19',
    // En oscuro, «casi cero» se funde con la superficie (pasos oscuros) y lo intenso se aclara.
    sequential: ['#0d366b', '#104281', '#184f95', '#1c5cab', '#256abf', '#2a78d6', '#3987e5', '#5598e7', '#6da7ec', '#86b6ef', '#9ec5f4', '#b7d3f6'],
};

/** Estados reservados (nunca son una serie): siempre van con icono y texto. */
export const STATUS = { good: '#0ca30c', warning: '#fab219', serious: '#ec835a', critical: '#d03b3b' } as const;

function subscribe(onChange: () => void): () => void {
    const observer = new MutationObserver(onChange);
    observer.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
    return () => observer.disconnect();
}

const isDark = () => document.documentElement.classList.contains('dark');

export function useChartTheme() {
    const dark = useSyncExternalStore(subscribe, isDark, () => false);
    return dark ? { ...DARK, dark } : { ...LIGHT, dark };
}

export type ChartTheme = ReturnType<typeof useChartTheme>;

/** Color secuencial para t ∈ [0, 1] y si el texto encima debe ser claro. */
export function sequentialCell(theme: ChartTheme, t: number): { background: string; light: boolean } {
    const steps = theme.sequential;
    const clamped = Math.max(0, Math.min(1, Number.isFinite(t) ? t : 0));
    const index = Math.min(steps.length - 1, Math.round(clamped * (steps.length - 1)));
    const background = steps[index] ?? steps[0] ?? '#cde2fb';
    // Claro: los pasos desde #3987e5 (índice 6) ya piden texto blanco. Oscuro: hasta #3987e5.
    const light = theme.dark ? index <= 6 : index >= 6;
    return { background, light };
}
