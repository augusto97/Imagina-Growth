import { useTranslation } from 'react-i18next';
import type { CompareMode, DashboardDataMap, DashboardResponse, DashboardSlug, MetricDefinition } from '../api';
import { csvFilename, downloadCsv } from '../csv';
import type { Formatters } from '../format';
import { csvColumns } from '../components/DataTable';
import type { Column } from '../components/DataTable';

export interface DashboardProps<S extends DashboardSlug> {
    response: DashboardResponse<DashboardDataMap[S]>;
    data: DashboardDataMap[S];
    fmt: Formatters;
    /** Ruta base del espacio de trabajo: /w/:workspaceId */
    base: string;
    catalog: Record<string, MetricDefinition> | undefined;
}

export function compareModeOf(response: DashboardResponse<unknown>): CompareMode | null {
    return response.period.compare?.mode ?? null;
}

/** Nombres de canales, dispositivos y métricas (con el valor crudo si no hay texto). */
export function useLabels() {
    const { t } = useTranslation();
    return {
        channel: (key: string) => t(`analytics.channel.${key || 'unknown'}`, { defaultValue: key || t('analytics.channel.unknown') }),
        device: (key: string) => t(`analytics.device.${key || 'unknown'}`, { defaultValue: key }),
        metric: (key: string) => t(`analytics.metric.${key}`, { defaultValue: key }),
        orNone: (value: string) => (value === '' ? t('analytics.none') : value),
    };
}

/** Exporta una tabla con el nombre del tablero y el rango de fechas. */
export function useExporter(response: DashboardResponse<unknown>) {
    const { t } = useTranslation();
    return <T,>(name: string, columns: Column<T>[], rows: T[]) => () =>
        downloadCsv(csvFilename([t(`analytics.dashboards.${response.dashboard}`), name, response.period.from, response.period.to]), csvColumns(columns), rows);
}
