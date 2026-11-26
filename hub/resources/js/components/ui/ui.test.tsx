import { cleanup, render, screen, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router';
import { afterEach, describe, expect, it } from 'vitest';
import { LockedPage } from '@/components/layout/LockedPage';
import { locate, SECTIONS } from '@/components/layout/nav';
import { PageContext } from '@/components/layout/pageContext';
import { checklistProgress, withCurrentStep } from './checklist';
import { ChoiceCard } from './choice-card';
import { EmptyState } from './empty-state';
import { ILLUSTRATION_NAMES } from './illustrations';
import { PageHeader } from './page-header';
import { changeTone, sparklinePoints } from './stat-card';
import { Switch } from './switch';

afterEach(cleanup);

describe('Checklist', () => {
    it('cuenta los pasos listos y dice cuál sigue (un bloqueado cuenta como pendiente)', () => {
        expect(checklistProgress([{ status: 'done' }, { status: 'todo' }, { status: 'blocked' }])).toEqual({ done: 1, total: 3, ratio: 1 / 3, next: 1, complete: false });
        expect(checklistProgress([{ status: 'done' }, { status: 'done' }])).toMatchObject({ next: -1, complete: true, ratio: 1 });
        expect(checklistProgress([{ status: 'todo' }, { status: 'current' }])).toMatchObject({ next: 1 });
        expect(checklistProgress([])).toMatchObject({ total: 0, ratio: 0, complete: false });
    });

    it('marca como actual el primer pendiente cuando nadie lo hizo', () => {
        expect(withCurrentStep([{ status: 'done' }, { status: 'todo' }, { status: 'todo' }]).map((s) => s.status)).toEqual(['done', 'current', 'todo']);
        expect(withCurrentStep([{ status: 'todo' }, { status: 'current' }]).map((s) => s.status)).toEqual(['todo', 'current']);
    });
});

describe('StatCard', () => {
    it('el tono de la variación depende de hacia dónde es bueno moverse', () => {
        expect(changeTone(0.12)).toBe('good');
        expect(changeTone(-0.12)).toBe('bad');
        expect(changeTone(0.12, 'down')).toBe('bad');
        expect(changeTone(0.001)).toBe('flat');
        expect(changeTone(0.3, 'neutral')).toBe('flat');
        expect(changeTone(null)).toBeNull();
        expect(changeTone(Number.NaN)).toBeNull();
    });

    it('la minigráfica cabe en su lienzo y una serie plana queda en el medio', () => {
        const points = sparklinePoints([1, 5, 3], 96, 28);
        expect(points).toHaveLength(3);
        expect(points[0]?.[0]).toBe(2);
        expect(points[2]?.[0]).toBe(94);
        expect(points[1]?.[1]).toBe(2);
        expect(points[0]?.[1]).toBe(26);
        expect(sparklinePoints([4, 4]).every(([, y]) => y === 14)).toBe(true);
        expect(sparklinePoints([null, undefined, 2])).toHaveLength(3);
        expect(sparklinePoints([])).toEqual([]);
    });
});

function withPage(path: string, children: React.ReactNode) {
    const base = '/w/ws_1';
    const loc = locate(SECTIONS, path, base);
    return (
        <MemoryRouter initialEntries={[path]}>
            <PageContext.Provider value={loc ? { ...loc, base } : null}>{children}</PageContext.Provider>
        </MemoryRouter>
    );
}

describe('PageHeader v2', () => {
    it('lleva la miga de la sección y, en un detalle, la de la entrada', () => {
        render(withPage('/w/ws_1/contactos/ct_1', <PageHeader title="Laura Gómez" description="Cliente desde 2024" />));
        const crumbs = screen.getByRole('navigation', { name: 'Ubicación' });
        expect(within(crumbs).getByRole('link', { name: 'Clientes' })).toHaveAttribute('href', '/w/ws_1/clientes');
        expect(within(crumbs).getByRole('link', { name: 'Personas' })).toHaveAttribute('href', '/w/ws_1/contactos');
        expect(screen.getByRole('heading', { level: 1, name: 'Laura Gómez' })).toBeInTheDocument();
    });

    it('sin miga en la página de entrada de la sección, en Inicio y cuando se pide', () => {
        const { unmount } = render(withPage('/w/ws_1/clientes', <PageHeader title="Clientes" />));
        expect(screen.queryByRole('navigation', { name: 'Ubicación' })).not.toBeInTheDocument();
        unmount();
        render(withPage('/w/ws_1/segmentos', <PageHeader title="Grupos" breadcrumb={false} />));
        expect(screen.queryByRole('navigation', { name: 'Ubicación' })).not.toBeInTheDocument();
    });
});

describe('bloqueos que enseñan', () => {
    const section = SECTIONS.find((s) => s.key === 'onsite');
    const item = section?.items.find((i) => i.key === 'loyalty');

    it('sin la web conectada dice qué hace, qué falta y lleva a conectarla', () => {
        if (!section || !item) {
            throw new Error('nav');
        }
        render(withPage('/w/ws_1/fidelizacion', <LockedPage section={section} item={item} lock={{ kind: 'site', state: 'none' }} base="/w/ws_1" abilities={['manageSites']} />));
        expect(screen.getByRole('heading', { level: 2, name: 'Conecta tu web para usar Puntos y recompensas' })).toBeInTheDocument();
        expect(screen.getByText('Qué hace falta')).toBeInTheDocument();
        expect(screen.getByRole('link', { name: /Conectar tu web/ })).toHaveAttribute('href', '/w/ws_1/sitios?conectar=1');
    });

    it('fuera del plan nombra el plan; sin permiso, dice a quién pedírselo', () => {
        if (!section || !item) {
            throw new Error('nav');
        }
        render(
            withPage(
                '/w/ws_1/fidelizacion',
                <LockedPage section={section} item={item} lock={{ kind: 'plan', module: 'loyalty', plan: 'Tienda · Crece' }} base="/w/ws_1" abilities={['viewDashboards']} />,
            ),
        );
        expect(screen.getByText('Disponible en el plan Tienda · Crece')).toBeInTheDocument();
        expect(screen.queryByRole('link', { name: /Ver planes/ })).not.toBeInTheDocument();
        expect(screen.getByText('Pídele a quien administra la cuenta que cambie el plan.')).toBeInTheDocument();
    });
});

describe('EmptyState v2', () => {
    it('cada ilustración se dibuja y es decorativa', () => {
        for (const name of ILLUSTRATION_NAMES) {
            const { container, unmount } = render(<EmptyState illustration={name} title={name} />);
            const svg = container.querySelector('svg');
            expect(svg, name).not.toBeNull();
            expect(svg).toHaveAttribute('aria-hidden', 'true');
            expect(svg?.childElementCount, name).toBeGreaterThan(2);
            unmount();
        }
    });

    it('muestra «Cómo funciona» con sus pasos numerados', () => {
        render(<EmptyState illustration="messages" title="Activa tu primera automatización" steps={['Elige una', 'Revisa el mensaje', 'Actívala']} />);
        expect(screen.getByText('Cómo funciona')).toBeInTheDocument();
        expect(screen.getAllByRole('listitem')).toHaveLength(3);
    });
});

describe('Switch y ChoiceCard', () => {
    it('el interruptor se anuncia como switch con su estado y cambia al hacer clic', () => {
        const changes: boolean[] = [];
        render(<Switch aria-label="Prender carritos" checked={false} onCheckedChange={(v) => changes.push(v)} />);
        const sw = screen.getByRole('switch', { name: 'Prender carritos' });
        expect(sw.getAttribute('aria-checked')).toBe('false');
        sw.click();
        expect(changes).toEqual([true]);
    });

    it('la tarjeta para elegir es un radio nativo con su nombre', () => {
        const picked: string[] = [];
        render(
            <fieldset>
                <legend>Tipo</legend>
                <ChoiceCard name="tipo" value="store" checked onChange={(v) => picked.push(v)} title="Tienda" description="Vendes productos" />
                <ChoiceCard name="tipo" value="services" checked={false} onChange={(v) => picked.push(v)} title="Servicios" />
            </fieldset>,
        );
        const radios = screen.getAllByRole('radio');
        expect(radios).toHaveLength(2);
        expect((screen.getByRole('radio', { name: /Tienda/ }) as HTMLInputElement).checked).toBe(true);
        screen.getByRole('radio', { name: /Servicios/ }).click();
        expect(picked).toEqual(['services']);
    });
});
