/* «Hoy», el asistente del vendedor (M18): formas de la API de routes/api/leads.php (leads/today). */

import type { Temperature } from '@/features/leads/types';

export type TodayKind = 'reply' | 'first_contact' | 'task' | 'next_step' | 'stale';

export const TODAY_KINDS: TodayKind[] = ['reply', 'first_contact', 'task', 'next_step', 'stale'];

export interface TodayItem {
    key: string;
    kind: TodayKind;
    since: string | null;
    due_at: string | null;
    days: number | null;
    overdue: boolean;
    lead: {
        id: string;
        name: string | null;
        city: string | null;
        service: string | null;
        stage: string;
        stage_label: string;
        temperature: Temperature;
        brief_temperature: string | null;
        score: number;
        next_step: string | null;
    } | null;
    task: { id: string; kind: 'task' | 'call'; title: string; source: 'user' | 'flow' } | null;
    owner: { id: string; name: string } | null;
}

export interface TodayResponse {
    items: TodayItem[];
    counts: Record<TodayKind, number>;
    stale_days: number;
    owner: string;
    can_see_all: boolean;
}

export type ReachMode = 'text' | 'template' | 'email' | 'none';

export interface Suggestion {
    text: string | null;
    source: 'ai' | 'fallback';
    reason?: string | null;
    reach: { mode: ReachMode; channel: string | null; reason: string | null };
}

export interface SendResult {
    status: 'queued' | 'duplicate' | 'deferred' | 'denied';
    mode: ReachMode;
    channel: string | null;
    until: string | null;
    reason: string | null;
    message?: string;
}
