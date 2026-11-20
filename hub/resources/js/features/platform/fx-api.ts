import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';

/* Tasas de cambio contra el dólar (F11, solo superadmin). Rutas en hub/routes/platform.php (`fx`). */

export interface FxCurrency {
    currency: string;
    /** La que rige hoy: la manual si hay, si no la última automática. */
    rate: number | null;
    date: string | null;
    manual: boolean;
    stale: boolean;
    auto: { rate: number; date: string; source: string } | null;
    override: { rate: number; effective_from: string; note: string | null; updated_at: string | null } | null;
}

export interface FxOverview {
    base: 'USD';
    source: string;
    attribution_url: string;
    stale_after_days: number;
    last_update: { ok: boolean; at: string; date: string | null; stored: number; missing: string[]; error: string | null } | null;
    currencies: FxCurrency[];
}

const key = ['platform', 'fx'];

export function usePlatformFx(enabled = true) {
    return useQuery({ queryKey: key, queryFn: () => api.get<FxOverview>('/api/platform/fx'), enabled });
}

function useSetData() {
    const client = useQueryClient();
    return (data: FxOverview) => client.setQueryData(key, data);
}

export function useSetFxOverride() {
    const set = useSetData();
    return useMutation({
        mutationFn: (v: { currency: string; rate: number; effective_from?: string; note?: string }) =>
            api.put<FxOverview>(`/api/platform/fx/${v.currency}`, { rate: v.rate, effective_from: v.effective_from || undefined, note: v.note || undefined }),
        onSuccess: set,
    });
}

export function useRemoveFxOverride() {
    const set = useSetData();
    return useMutation({ mutationFn: (currency: string) => api.delete<FxOverview>(`/api/platform/fx/${currency}`), onSuccess: set });
}

export function useRefreshFx() {
    const client = useQueryClient();
    return useMutation({
        mutationFn: () => api.post<FxOverview>('/api/platform/fx/refresh'),
        onSuccess: (data) => client.setQueryData(key, data),
        onSettled: () => void client.invalidateQueries({ queryKey: key }),
    });
}

/** Una tasa escrita por la persona («3.950,5» o «3950.5»), o null si no es un número positivo. */
export function parseRate(input: string): number | null {
    const clean = input.trim().replace(/\s/g, '');
    if (clean === '') {
        return null;
    }
    // Con coma decimal (es-CO): los puntos son miles.
    const normalized = clean.includes(',') ? clean.replace(/\./g, '').replace(',', '.') : clean;
    const value = Number(normalized);
    return Number.isFinite(value) && value > 0 ? value : null;
}
