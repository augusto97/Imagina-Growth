import { useQuery } from '@tanstack/react-query';
import { wsPath } from '@/api/queries';
import { api } from '@/lib/api';
import type { ResultsRange, ServicesResults } from './types';

/* Resultados de un negocio sin tienda (modulos.md M18). Ruta en hub/routes/api/services.php; caché de 2 min en el servidor. */

export const servicesKeys = {
    results: (ws: string, range: ResultsRange) => ['ws', ws, 'services', 'results', range] as const,
};

function query(range: ResultsRange): string {
    return 'days' in range ? `days=${range.days}` : `from=${encodeURIComponent(range.from)}&to=${encodeURIComponent(range.to)}`;
}

/** Permiso `viewDashboards`. Sin periodo, los últimos 30 días. */
export function useServicesResults(ws: string, range: ResultsRange = { days: 30 }, enabled = true) {
    return useQuery({
        queryKey: servicesKeys.results(ws, range),
        queryFn: ({ signal }) => api.get<ServicesResults>(`${wsPath(ws)}/services/results?${query(range)}`, signal),
        staleTime: 60_000,
        enabled,
    });
}
