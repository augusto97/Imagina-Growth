import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { TooltipProvider } from '@/components/ui/tooltip';
import i18n from '@/lib/i18n';
import texts from '@/locales/es-CO/intelligence.json';
import { Markdown, SourceBadge } from './components';
import { EXAMPLE_KEYS, budgetShare, chartUnit, examplesFor, parseMarkdown } from './logic';

type Tree = { [key: string]: string | Tree };

function has(path: string): boolean {
    let node: string | Tree = texts;
    for (const part of path.split('.')) {
        if (typeof node !== 'object' || !(part in node)) return false;
        node = node[part] as string | Tree;
    }
    return typeof node === 'string';
}

/** Funciones de config/igr-ai.php y motivos de AiUnavailable. */
const FEATURES = [
    'weekly_analyst',
    'ask_data',
    'assistant',
    'copilot',
    'content',
    'sales_agent',
    'inbound_classifier',
    'review_moderator',
    'profile_summary',
    'review_summary',
    'monthly_report',
    'lead_qualifier',
    'lead_brief',
    'lead_followup',
    'lead_funnel',
    'reactivation',
];
const REASONS = ['numbers', 'disabled', 'budget', 'provider', 'refusal', 'invalid'];

describe('Inteligencia: lógica', () => {
    it('el markdown de los informes solo arma párrafos, listas y negritas, nunca HTML', () => {
        const blocks = parseMarkdown('Vendiste **$4.200.000**.\n\n**Por qué**\n\nNo hay datos.\n\n- uno\n- <b>dos</b>');
        expect(blocks).toHaveLength(4);
        expect(blocks[0]?.lines[0]).toEqual([
            { text: 'Vendiste ', bold: false },
            { text: '$4.200.000', bold: true },
            { text: '.', bold: false },
        ]);
        expect(blocks[3]?.kind).toBe('list');
        render(<Markdown body={'- <b>dos</b>'} />);
        expect(screen.getByText('<b>dos</b>')).toBeTruthy();
    });

    it('el uso del presupuesto queda entre 0 y 1 y las unidades se traducen a las de los gráficos', () => {
        expect(budgetShare(5, 25)).toBe(0.2);
        expect(budgetShare(40, 25)).toBe(1);
        expect(budgetShare(3, 0)).toBe(0);
        expect(chartUnit('percent')).toBe('ratio');
        expect(chartUnit('money')).toBe('money');
        expect(chartUnit(undefined)).toBe('count');
    });

    it('los ejemplos de «Pregúntale» cambian con el tipo de negocio y cada uno tiene su texto', () => {
        expect(examplesFor({ store: true }).kind).toBe('store');
        expect(examplesFor({ store: false }).kind).toBe('services');
        const examples = (texts as unknown as { intelligence: { ask: { examplesBy: Record<string, Record<string, string>> } } }).intelligence.ask.examplesBy;
        for (const kind of ['store', 'services'] as const) {
            expect(EXAMPLE_KEYS[kind].length).toBeGreaterThanOrEqual(3);
            for (const key of EXAMPLE_KEYS[kind]) {
                expect(examples[kind]?.[key]).toBeTypeOf('string');
            }
        }
    });
});

describe('Inteligencia: textos', () => {
    it('tiene texto para cada función de IA y cada motivo de respaldo', () => {
        const missing = [...FEATURES.map((f) => `intelligence.features.${f}`), ...REASONS.map((r) => `intelligence.fallback.${r}`)].filter((k) => !has(k));
        expect(missing).toEqual([]);
    });

    it('todo texto generado se marca: con IA o sin IA', () => {
        void i18n.changeLanguage('es-CO');
        render(
            <TooltipProvider>
                <SourceBadge source="ai" />
                <SourceBadge source="fallback" reason="numbers" />
            </TooltipProvider>,
        );
        expect(screen.getByText('Escrito con IA')).toBeTruthy();
        expect(screen.getByText('Sin IA')).toBeTruthy();
    });
});
