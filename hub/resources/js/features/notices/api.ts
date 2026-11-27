import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { wsPath } from '@/api/queries';
import { api } from '@/lib/api';
import { pollInterval } from '@/lib/realtime';

/* Avisos (lote 0.4.0, hub/routes/api/notices.php, docs/avisos.md). */

export interface NoticeItem {
    id: string;
    kind: string;
    title: string;
    body: string | null;
    url: string | null;
    read: boolean;
    created_at: string | null;
}

export type NoticeGroup = 'appointments' | 'leads' | 'conversations' | 'connections';
export type NoticeChannel = 'in_app' | 'email' | 'push' | 'whatsapp';

export interface NoticePreferencesData {
    settings: Record<NoticeGroup, Partial<Record<NoticeChannel, boolean>> & { scope?: 'all' | 'mine' }>;
    channels: Record<NoticeGroup, NoticeChannel[]>;
    can_choose_scope: boolean;
    phone: string | null;
    /** El WhatsApp de su ficha de vendedor, enmascarado, si no guardó uno aquí. */
    phone_hint: string | null;
    manager: boolean;
}

/** Un renglón de la sección «Avisos» de una cita o un lead. */
export interface NoticeLogRow {
    id: string;
    audience: 'customer' | 'team';
    event: string;
    channel: 'whatsapp' | 'email' | 'in_app' | 'push' | null;
    recipient: string | null;
    status: 'queued' | 'sent' | 'deferred' | 'denied' | 'skipped' | 'failed';
    reason: string | null;
    detail: string | null;
    message_status: string | null;
    created_at: string | null;
}

/** «Así avisamos» de la agenda. */
export interface BookingNoticeSetup {
    customer: {
        whatsapp: 'none' | 'no_template' | 'ready' | 'unofficial';
        whatsapp_number: string | null;
        email: 'own' | 'platform' | 'platform_off' | 'plan';
        email_from: string | null;
        reply_to: string | null;
        ask_email: boolean;
        require_email: boolean;
        reminders: number[];
    };
    team: {
        platform_mail: boolean;
        push: boolean;
        people: { name: string; scope: 'all' | 'mine'; channels: NoticeChannel[] }[];
    };
    fixes: string[];
}

export const noticeKeys = {
    all: (ws: string) => ['ws', ws, 'notices'] as const,
    list: (ws: string) => ['ws', ws, 'notices', 'list'] as const,
    unread: (ws: string) => ['ws', ws, 'notices', 'unread'] as const,
    preferences: (ws: string) => ['ws', ws, 'notices', 'preferences'] as const,
    appointment: (ws: string, id: string) => ['ws', ws, 'notices', 'appointment', id] as const,
    lead: (ws: string, id: string) => ['ws', ws, 'notices', 'lead', id] as const,
    bookingSetup: (ws: string) => ['ws', ws, 'notices', 'booking-setup'] as const,
};

/** Cuántos avisos sin leer: cada minuto sin tiempo real, cada 5 con Reverb conectado (el canal avisa de lo nuevo). */
export function useUnreadNotices(ws: string) {
    return useQuery({
        queryKey: noticeKeys.unread(ws),
        queryFn: ({ signal }) => api.get<{ unread: number }>(`${wsPath(ws)}/notices/unread`, signal),
        refetchInterval: () => pollInterval(60_000, 300_000),
        refetchOnWindowFocus: true,
        staleTime: 15_000,
    });
}

export function useNotices(ws: string, enabled: boolean) {
    return useQuery({
        queryKey: noticeKeys.list(ws),
        queryFn: ({ signal }) => api.get<{ data: NoticeItem[]; unread: number }>(`${wsPath(ws)}/notices?limit=40`, signal),
        enabled,
        placeholderData: keepPreviousData,
    });
}

export function useMarkNoticesRead(ws: string) {
    const client = useQueryClient();
    return useMutation({
        mutationFn: (ids: string[] | null) => api.post<{ unread: number }>(`${wsPath(ws)}/notices/read`, ids === null ? {} : { ids }),
        onSuccess: (data) => {
            client.setQueryData(noticeKeys.unread(ws), data);
            void client.invalidateQueries({ queryKey: noticeKeys.list(ws) });
        },
    });
}

export function useNoticePreferences(ws: string, enabled = true) {
    return useQuery({
        queryKey: noticeKeys.preferences(ws),
        queryFn: ({ signal }) => api.get<NoticePreferencesData>(`${wsPath(ws)}/notices/preferences`, signal),
        enabled,
    });
}

export function useSaveNoticePreferences(ws: string) {
    const client = useQueryClient();
    return useMutation({
        mutationFn: (body: { settings?: Partial<NoticePreferencesData['settings']>; phone?: string | null }) =>
            api.put<NoticePreferencesData>(`${wsPath(ws)}/notices/preferences`, body),
        onSuccess: (data) => {
            client.setQueryData(noticeKeys.preferences(ws), data);
            void client.invalidateQueries({ queryKey: noticeKeys.bookingSetup(ws) });
        },
    });
}

export function useAppointmentNotices(ws: string, id: string | null) {
    return useQuery({
        queryKey: noticeKeys.appointment(ws, id ?? ''),
        queryFn: ({ signal }) => api.get<{ data: NoticeLogRow[] }>(`${wsPath(ws)}/bookings/appointments/${id ?? ''}/notices`, signal),
        enabled: id !== null,
    });
}

export function useLeadNotices(ws: string, id: string | null) {
    return useQuery({
        queryKey: noticeKeys.lead(ws, id ?? ''),
        queryFn: ({ signal }) => api.get<{ data: NoticeLogRow[] }>(`${wsPath(ws)}/leads/${id ?? ''}/notices`, signal),
        enabled: id !== null,
    });
}

export function useBookingNoticeSetup(ws: string) {
    return useQuery({
        queryKey: noticeKeys.bookingSetup(ws),
        queryFn: ({ signal }) => api.get<BookingNoticeSetup>(`${wsPath(ws)}/bookings/notices/setup`, signal),
    });
}
