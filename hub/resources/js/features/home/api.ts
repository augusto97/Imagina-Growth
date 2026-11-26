import { useQuery } from '@tanstack/react-query';
import { wsPath } from '@/api/queries';
import { api } from '@/lib/api';
import type { Home, HomeMoney, TodayItem } from './types';

/* El Inicio nuevo: GET /api/w/{ws}/home. Lo caro viene en caché del servidor; aquí se refresca cada minuto. */

export const homeKeys = {
    home: (ws: string) => ['ws', ws, 'home'] as const,
};

export function useHome(ws: string, enabled = true) {
    return useQuery({
        queryKey: homeKeys.home(ws),
        queryFn: ({ signal }) => api.get<Home>(`${wsPath(ws)}/home`, signal),
        staleTime: 30_000,
        refetchInterval: 60_000,
        enabled,
    });
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
