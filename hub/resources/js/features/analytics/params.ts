import { useCallback, useMemo } from 'react';
import { useSearchParams } from 'react-router';
import type { CompareMode } from './api';

/*
 * Estado de los tableros en la URL (se puede compartir y sobrevive a recargas):
 * ?range=30d|7d|90d|12m|month|last_month|custom&from=&to=&compare=&site=&channel=&device=&city=
 */

export const RANGE_PRESETS = ['7d', '30d', '90d', '12m', 'month', 'last_month', 'custom'] as const;
export type RangePreset = (typeof RANGE_PRESETS)[number];
export const COMPARE_MODES: CompareMode[] = ['previous', 'year', 'none'];
export const DEVICES = ['mobile', 'desktop', 'tablet'] as const;

/** Canales de inteligencia.md §2 (los mismos de ChannelClassifier). */
export const CHANNELS = [
    'organic_search',
    'paid_google',
    'paid_meta',
    'paid_tiktok',
    'paid_other',
    'organic_social',
    'ai_assistants',
    'referral',
    'email',
    'whatsapp',
    'sms',
    'igr_email',
    'igr_whatsapp',
    'igr_sms',
    'affiliate',
    'referral_program',
    'direct',
    'unknown',
] as const;

export interface AnalyticsParams {
    range: RangePreset;
    from: string;
    to: string;
    compare: CompareMode;
    site: string;
    channel: string;
    device: string;
    city: string;
}

/** Fecha de hoy (Y-m-d) en la zona del espacio de trabajo. */
export function todayIn(timeZone: string, now: Date = new Date()): string {
    try {
        return new Intl.DateTimeFormat('en-CA', { timeZone, year: 'numeric', month: '2-digit', day: '2-digit' }).format(now);
    } catch {
        return now.toISOString().slice(0, 10);
    }
}

function shift(ymd: string, days: number): string {
    const d = new Date(`${ymd}T12:00:00Z`);
    d.setUTCDate(d.getUTCDate() + days);
    return d.toISOString().slice(0, 10);
}

/** Rango [from, to] (ambos incluidos) de un preset, en la zona del espacio de trabajo. */
export function presetRange(preset: Exclude<RangePreset, 'custom'>, timeZone: string, now: Date = new Date()): { from: string; to: string } {
    const today = todayIn(timeZone, now);
    switch (preset) {
        case '7d':
            return { from: shift(today, -6), to: today };
        case '90d':
            return { from: shift(today, -89), to: today };
        case '12m':
            return { from: shift(today, -364), to: today };
        case 'month':
            return { from: `${today.slice(0, 8)}01`, to: today };
        case 'last_month': {
            const firstThis = `${today.slice(0, 8)}01`;
            const lastPrev = shift(firstThis, -1);
            return { from: `${lastPrev.slice(0, 8)}01`, to: lastPrev };
        }
        default:
            return { from: shift(today, -29), to: today };
    }
}

const isYmd = (v: string | null): v is string => v !== null && /^\d{4}-\d{2}-\d{2}$/.test(v);

export function readParams(search: URLSearchParams, timeZone: string, now: Date = new Date()): AnalyticsParams {
    const rawRange = search.get('range');
    const from = search.get('from');
    const to = search.get('to');
    const custom = isYmd(from) && isYmd(to) && from <= to;
    const range: RangePreset = rawRange === 'custom' ? (custom ? 'custom' : '30d') : (RANGE_PRESETS as readonly string[]).includes(rawRange ?? '') ? (rawRange as RangePreset) : custom ? 'custom' : '30d';
    const dates = range === 'custom' && custom ? { from, to } : presetRange(range === 'custom' ? '30d' : range, timeZone, now);
    const compare = search.get('compare');
    return {
        range,
        ...dates,
        compare: compare === 'year' || compare === 'none' ? compare : 'previous',
        site: search.get('site') ?? '',
        channel: search.get('channel') ?? '',
        device: (DEVICES as readonly string[]).includes(search.get('device') ?? '') ? (search.get('device') ?? '') : '',
        city: search.get('city') ?? '',
    };
}

/** Query string que entiende la API (AnalyticsController::period y Filters::fromRequest). */
export function apiQuery(p: AnalyticsParams): string {
    const q = new URLSearchParams({ from: p.from, to: p.to, compare: p.compare });
    for (const key of ['site', 'channel', 'device', 'city'] as const) {
        if (p[key] !== '') {
            q.set(key, p[key]);
        }
    }
    return q.toString();
}

export function activeFilterCount(p: AnalyticsParams): number {
    return (['site', 'channel', 'device', 'city'] as const).filter((k) => p[k] !== '').length;
}

export function useAnalyticsParams(timeZone: string) {
    const [search, setSearch] = useSearchParams();
    const params = useMemo(() => readParams(search, timeZone), [search, timeZone]);
    const update = useCallback(
        (patch: Partial<AnalyticsParams>) => {
            setSearch(
                (prev) => {
                    const next = new URLSearchParams(prev);
                    for (const [key, value] of Object.entries(patch) as [string, string | undefined][]) {
                        if (value === '' || value === undefined) {
                            next.delete(key);
                        } else {
                            next.set(key, value);
                        }
                    }
                    if (patch.range !== undefined && patch.range !== 'custom') {
                        next.delete('from');
                        next.delete('to');
                    }
                    if (next.get('range') === '30d') {
                        next.delete('range');
                    }
                    if (next.get('compare') === 'previous') {
                        next.delete('compare');
                    }
                    return next;
                },
                { replace: true },
            );
        },
        [setSearch],
    );
    return { params, update, query: apiQuery(params), search: search.toString() };
}
