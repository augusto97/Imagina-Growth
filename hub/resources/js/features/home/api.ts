import { queryOptions, useQuery } from '@tanstack/react-query';
import { wsPath } from '@/api/queries';
import { api } from '@/lib/api';
import type { Home, HomeKpis, HomeMoney, KpiKey, TodayItem } from './types';

/* El Inicio nuevo: GET /api/w/{ws}/home. Lo caro viene en caché del servidor; aquí se refresca cada minuto. */

export const homeKeys = {
    home: (ws: string) => ['ws', ws, 'home'] as const,
};

/** El Inicio (también para precargarlo al pasar el puntero por el menú). */
export function homeQuery(ws: string) {
    return queryOptions({ queryKey: homeKeys.home(ws), queryFn: ({ signal }) => api.get<Home>(`${wsPath(ws)}/home`, signal), staleTime: 30_000 });
}

export function useHome(ws: string, enabled = true) {
    return useQuery({ ...homeQuery(ws), refetchInterval: 60_000, enabled });
}

// ── Lógica pura ─────────────────────────────────────────────────────────────

/** Qué muestra la tarjeta héroe: el dinero generado, o el potencial cuando todavía no hay (o es muy poco). */
export function heroMode(home: Pick<Home, 'money' | 'potential'>): 'money' | 'potential' | 'empty' {
    if (!home.money.low) {
        return 'money';
    }
    return home.potential !== null ? 'potential' : Number(home.money.total) > 0 ? 'money' : 'empty';
}

/** La tendencia del dinero generado frente al mes anterior. */
export function moneyTrend(money: Pick<HomeMoney, 'change_pct'>): 'up' | 'down' | 'flat' | null {
    if (money.change_pct === null) {
        return null;
    }
    return money.change_pct > 0 ? 'up' : money.change_pct < 0 ? 'down' : 'flat';
}

/** Las acciones de «Para hoy» que bloquean el resto (conectar la web o un canal) van destacadas. */
export function isBlocking(item: Pick<TodayItem, 'kind'>): boolean {
    return item.kind === 'connect_site' || item.kind === 'connect_channel' || item.kind === 'import_failed';
}

/**
 * Adónde lleva una acción de «Para hoy». Mientras el asistente no termine, conectar la web o un canal abre su paso del
 * asistente (con la espera en vivo y lo que desbloquea), no la pantalla de configuración.
 */
export function todayLink(item: Pick<TodayItem, 'kind' | 'to'>, setupOpen: boolean): string {
    if (setupOpen && item.kind === 'connect_site') {
        return 'configurar?paso=connect';
    }
    if (setupOpen && item.kind === 'connect_channel') {
        return 'configurar?paso=channels';
    }
    return item.to;
}

/** Hacia dónde es bueno que se mueva un KPI (la primera respuesta, cuanto menos mejor). */
export function kpiBetter(key: KpiKey): 'up' | 'down' {
    return key === 'first_response_minutes' ? 'down' : 'up';
}

/** La serie de 30 días de un KPI para su minigráfica, o undefined si el servidor no la manda. */
export function kpiSeries(kpis: Pick<HomeKpis, 'series' | 'series_keys'>, key: KpiKey): number[] | undefined {
    if (!kpis.series_keys.includes(key)) {
        return undefined;
    }
    const values = kpis.series.map((p) => p[key] ?? 0);
    // Una línea plana en cero no dice nada: sin minigráfica hasta que haya datos.
    return values.some((v) => v !== 0) ? values : undefined;
}
