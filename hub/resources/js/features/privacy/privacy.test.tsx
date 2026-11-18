import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { ConfirmDeleteDialog } from './ConfirmDeleteDialog';
import { dueTone } from './DataRequestsTable';

describe('borrado de datos', () => {
    it('solo se confirma escribiendo BORRAR', () => {
        const onConfirm = vi.fn();
        render(<ConfirmDeleteDialog open onOpenChange={() => undefined} onConfirm={onConfirm} loading={false} name="Ana" />);
        const button = screen.getByRole('button', { name: 'Borrar definitivamente' });
        expect(button).toBeDisabled();
        fireEvent.change(screen.getByLabelText('Escribe BORRAR para confirmar'), { target: { value: 'borrar' } });
        expect(button).toBeDisabled();
        fireEvent.change(screen.getByLabelText('Escribe BORRAR para confirmar'), { target: { value: 'BORRAR' } });
        expect(button).toBeEnabled();
        fireEvent.click(button);
        expect(onConfirm).toHaveBeenCalledWith('BORRAR');
    });

    it('marca las solicitudes vencidas o por vencer', () => {
        const now = new Date('2026-11-18T12:00:00Z');
        expect(dueTone({ status: 'pending', due_at: '2026-11-17T12:00:00Z' }, now)).toBe('overdue');
        expect(dueTone({ status: 'pending', due_at: '2026-11-20T12:00:00Z' }, now)).toBe('soon');
        expect(dueTone({ status: 'pending', due_at: '2026-12-10T12:00:00Z' }, now)).toBe('ok');
        expect(dueTone({ status: 'completed', due_at: '2026-11-17T12:00:00Z' }, now)).toBeNull();
    });
});
