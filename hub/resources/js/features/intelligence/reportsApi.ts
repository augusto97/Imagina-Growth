import { useQuery } from '@tanstack/react-query';
import { wsPath } from '@/api/queries';
import type { ReportSummary } from '@/features/agency/api';
import { api } from '@/lib/api';

/*
 * Informes (Resultados → Informes): el mensual con marca (M22, rutas `reports…` de routes/api/agency.php) y el semanal
 * del analista (rutas `ai/reports…`). Las mutaciones (generar, enviar) son las de features/agency/api.ts y
 * features/intelligence/api.ts; esta lista usa la misma llave que la de la agencia, así se refresca sola.
 */

export interface Recipients {
    count: number;
    /** Correos enmascarados (d***@g*****.co). */
    masked: string[];
}

export interface MonthlyReportsIndex {
    reports: ReportSummary[];
    can_manage: boolean;
    last_month: string;
    recipients: { monthly: Recipients; weekly: Recipients };
}

/** Los hechos del informe mensual (App\Domain\Agency\Reports\MonthlyReportFacts): lo único que puede citar el resumen. */
export interface MonthlyFacts {
    negocio: string;
    modo: 'tienda' | 'servicios';
    mes: string;
    periodo: { desde: string; hasta: string; mes: string };
    moneda: string;
    dinero_generado: {
        total: number;
        pedidos: number;
        ventas_tienda: number;
        participacion_pct: number;
        mes_anterior: number;
        cambio_vs_anterior_pct: number | null;
        por_modulo: { clave: string; modulo: string; ventas: number; pedidos: number; participacion_pct: number }[];
    };
    kpis: { clave: string; nombre: string; valor: number; unidad: 'money' | 'count'; anterior: number; cambio_pct: number | null }[];
    hallazgos: { titulo: string; severidad: string; impacto: number | null }[];
    predicciones?: { clientes_en_riesgo_alto: number; valor_futuro_12m: number; compras_esperadas_30_dias: number };
}

export type MonthlyReportDetail = ReportSummary & { summary?: string | null; facts?: MonthlyFacts };

export function useMonthlyReports(ws: string) {
    return useQuery({ queryKey: ['ws', ws, 'reports'], queryFn: ({ signal }) => api.get<MonthlyReportsIndex>(`${wsPath(ws)}/reports`, signal) });
}

export function useMonthlyReport(ws: string, id: string | null) {
    return useQuery({
        queryKey: ['ws', ws, 'reports', 'one', id ?? ''],
        enabled: id !== null,
        queryFn: async ({ signal }) => (await api.get<{ report: MonthlyReportDetail }>(`${wsPath(ws)}/reports/${encodeURIComponent(id ?? '')}`, signal)).report,
    });
}

/** Lo que va a llegar el día 1 (los hechos del mes anterior, sin guardarlo ni escribir el resumen). */
export function useMonthlyPreview(ws: string, enabled: boolean) {
    return useQuery({
        queryKey: ['ws', ws, 'reports', 'preview'],
        enabled,
        staleTime: 5 * 60_000,
        queryFn: ({ signal }) => api.get<{ period: string; facts: MonthlyFacts }>(`${wsPath(ws)}/reports/preview`, signal),
    });
}
