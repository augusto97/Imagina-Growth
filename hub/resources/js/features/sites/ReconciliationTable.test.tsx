import { render, screen, within } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import type { Reconciliation } from '@/api/types';
import { TooltipProvider } from '@/components/ui/tooltip';
import { ReconciliationTable } from './ReconciliationTable';

const base = {
    available: true as const,
    currency: 'COP',
    from: '2024-11-18T05:00:00+00:00',
    until: '2026-11-18T10:05:00+00:00',
};

function renderTable(rec: Reconciliation) {
    return render(
        <TooltipProvider>
            <ReconciliationTable reconciliation={rec} currency="COP" locale="es-CO" timezone="America/Bogota" />
        </TooltipProvider>,
    );
}

describe('ReconciliationTable', () => {
    it('muestra que todo coincide al 100 %', () => {
        renderTable({
            ...base,
            ok: true,
            rows: [
                { status: 'completed', wc_count: 8120, hub_count: 8120, wc_total: '1532000000.00', hub_total: '1532000000.00', ok: true },
                { status: 'processing', wc_count: 950, hub_count: 950, wc_total: '180500000.00', hub_total: '180500000.00', ok: true },
            ],
            totals: { wc_count: 9070, hub_count: 9070, wc_total: '1712500000.00', hub_total: '1712500000.00', wc_refunded: '0.00', hub_refunded: '0.00', refunds_ok: true },
        });
        expect(screen.getByText('Coincide al 100 %')).toBeInTheDocument();
        const completed = screen.getByTestId('rec-completed');
        expect(within(completed).getByText('Completado')).toBeInTheDocument();
        expect(within(completed).getAllByText('8.120')).toHaveLength(2);
        expect(within(completed).getByLabelText('Coincide')).toBeInTheDocument();
        expect(screen.getByText('Reembolsos')).toBeInTheDocument();
    });

    it('marca el estado que no coincide', () => {
        renderTable({
            ...base,
            ok: false,
            rows: [{ status: 'on-hold', wc_count: 12, hub_count: 11, wc_total: '900000.00', hub_total: '850000.00', ok: false }],
            totals: { wc_count: 12, hub_count: 11, wc_total: '900000.00', hub_total: '850000.00' },
        });
        expect(screen.getByText('Hay diferencias')).toBeInTheDocument();
        const row = screen.getByTestId('rec-on-hold');
        expect(within(row).getByText('En espera')).toBeInTheDocument();
        expect(within(row).getByLabelText('No coincide')).toBeInTheDocument();
        expect(within(row).getByText('11')).toHaveClass('igr:text-destructive');
    });

    it('explica cuándo aparece si todavía no hay datos', () => {
        renderTable({ available: false });
        expect(screen.getByText(/Aparece cuando el plugin termina la importación/)).toBeInTheDocument();
    });
});
