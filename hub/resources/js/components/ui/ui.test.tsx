import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { LockedPage } from '@/components/layout/LockedPage';
import { locate, SECTIONS } from '@/components/layout/nav';
import { PageContext } from '@/components/layout/pageContext';
import { avatarToneIndex, initialsOf } from './avatar';
import { checklistProgress, withCurrentStep } from './checklist';
import { Chip, ChipGroup } from './chip';
import { ConfirmDialog } from './confirm-dialog';
import { CopyButton, CopyField } from './copy-field';
import { DeviceFrame } from './device-frame';
import { EmptyState } from './empty-state';
import { ILLUSTRATION_NAMES } from './illustrations';
import { Checkbox } from './input';
import { PageHeader } from './page-header';
import { nextSegment, SegmentedControl } from './segmented';
import { changeTone, sparklinePoints, StatList, StatRow } from './stat-card';

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

describe('StatList', () => {
    it('una fila por cifra con su nombre, la cifra y la variación en texto', () => {
        render(
            <StatList label="Cifras del periodo">
                <StatRow label="Ventas" value="$ 143 M" change={-0.198} changeLabel="-19,8 %" />
                <StatRow label="Pedidos" value="273" />
            </StatList>,
        );
        const list = screen.getByRole('list', { name: 'Cifras del periodo' });
        const rows = within(list).getAllByRole('listitem');
        expect(rows).toHaveLength(2);
        expect(within(rows[0] as HTMLElement).getByText('$ 143 M')).toBeInTheDocument();
        expect(within(rows[0] as HTMLElement).getByText('-19,8 %')).toHaveClass('igr:text-destructive');
        expect(within(rows[1] as HTMLElement).queryByText('-19,8 %')).not.toBeInTheDocument();
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

describe('CopyField y CopyButton', () => {
    it('copia el valor y lo confirma para el lector de pantalla', async () => {
        const writeText = vi.fn().mockResolvedValue(undefined);
        Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true });
        render(<CopyField label="Enlace" value="https://growth.imagina.cloud/p/sonrisa" />);
        expect(screen.getByLabelText('Enlace')).toHaveValue('https://growth.imagina.cloud/p/sonrisa');
        fireEvent.click(screen.getByRole('button', { name: 'Copiar enlace' }));
        expect(writeText).toHaveBeenCalledWith('https://growth.imagina.cloud/p/sonrisa');
        expect(await screen.findByText('Copiado')).toBeInTheDocument();
    });

    it('con texto, el botón dice lo que copia', () => {
        render(<CopyButton value="ABC">Copiar el código</CopyButton>);
        expect(screen.getByRole('button', { name: /Copiar el código/ })).toBeInTheDocument();
    });
});

describe('SegmentedControl', () => {
    const options = [
        { value: 'desktop', label: 'Escritorio' },
        { value: 'mobile', label: 'Celular' },
    ] as const;

    it('es un grupo de radio: la opción elegida es la única que recibe el foco con Tab', () => {
        const onChange = vi.fn();
        render(<SegmentedControl label="Vista previa en" value="desktop" onChange={onChange} options={options} />);
        const group = screen.getByRole('radiogroup', { name: 'Vista previa en' });
        const [desk, mobile] = within(group).getAllByRole('radio');
        expect(desk).toHaveAttribute('aria-checked', 'true');
        expect(desk).toHaveAttribute('tabindex', '0');
        expect(mobile).toHaveAttribute('tabindex', '-1');
        if (mobile) fireEvent.click(mobile);
        expect(onChange).toHaveBeenCalledWith('mobile');
    });

    it('las flechas cambian de opción y dan la vuelta', () => {
        expect(nextSegment(options, 'desktop', 'ArrowRight')).toBe('mobile');
        expect(nextSegment(options, 'mobile', 'ArrowRight')).toBe('desktop');
        expect(nextSegment(options, 'desktop', 'ArrowLeft')).toBe('mobile');
        expect(nextSegment(options, 'desktop', 'Enter')).toBe('desktop');
    });
});

describe('DeviceFrame', () => {
    it('la vista previa es una región con nombre que se recorre con el teclado', () => {
        render(
            <DeviceFrame device="mobile" label="Vista previa de la página">
                <p>Hola</p>
            </DeviceFrame>,
        );
        const region = screen.getByRole('region', { name: 'Vista previa de la página' });
        expect(region).toHaveAttribute('tabindex', '0');
        expect(within(region).getByText('Hola')).toBeInTheDocument();
    });
});

describe('ConfirmDialog', () => {
    it('pregunta por la acción, el foco empieza en Cancelar y el botón repite la acción', () => {
        const onConfirm = vi.fn();
        render(<ConfirmDialog open onOpenChange={() => undefined} title="¿Archivar la página?" description="El enlace deja de funcionar." confirmLabel="Archivar" tone="danger" onConfirm={onConfirm} />);
        const dialog = screen.getByRole('alertdialog', { name: '¿Archivar la página?' });
        expect(within(dialog).getByRole('button', { name: 'Cancelar' })).toHaveFocus();
        fireEvent.click(within(dialog).getByRole('button', { name: 'Archivar' }));
        expect(onConfirm).toHaveBeenCalledOnce();
    });
});

describe('Avatar, Chip, SegmentedControl y Checkbox (frente de Clientes)', () => {
    it('las iniciales toman la primera y la última palabra; el tono es estable', () => {
        expect(initialsOf('Ana María Ruiz')).toBe('AR');
        expect(initialsOf('  óscar ')).toBe('Ó');
        expect(initialsOf('')).toBe('·');
        expect(initialsOf(null)).toBe('·');
        expect(avatarToneIndex('ct_01')).toBe(avatarToneIndex('ct_01'));
        expect(avatarToneIndex('')).toBe(0);
    });

    it('el chip dice si está prendido y muestra su conteo', () => {
        render(
            <ChipGroup label="Filtrar">
                <Chip pressed count={3}>
                    Citas
                </Chip>
                <Chip pressed={false}>Pedidos</Chip>
            </ChipGroup>,
        );
        expect(screen.getByRole('group', { name: 'Filtrar' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /Citas/ })).toHaveAttribute('aria-pressed', 'true');
        expect(screen.getByRole('button', { name: 'Pedidos' })).toHaveAttribute('aria-pressed', 'false');
        expect(screen.getByText('3')).toBeInTheDocument();
    });

    it('el control segmentado marca la opción elegida', () => {
        render(
            <SegmentedControl
                label="Vista"
                value="week"
                onChange={() => undefined}
                options={[
                    { value: 'day', label: 'Día' },
                    { value: 'week', label: 'Semana' },
                ]}
            />,
        );
        expect(screen.getByRole('radiogroup', { name: 'Vista' })).toBeInTheDocument();
        expect(screen.getByRole('radio', { name: 'Semana' })).toHaveAttribute('aria-checked', 'true');
        expect(screen.getByRole('radio', { name: 'Día' })).toHaveAttribute('tabindex', '-1');
    });

    it('la casilla «algunas» queda indeterminada', () => {
        render(<Checkbox aria-label="Elegir todas" indeterminate checked={false} onChange={() => undefined} />);
        const box = screen.getByRole('checkbox', { name: 'Elegir todas' });
        expect((box as HTMLInputElement).indeterminate).toBe(true);
    });
});
