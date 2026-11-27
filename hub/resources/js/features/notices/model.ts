import { create } from 'zustand';
import type { NoticeLogRow } from './api';

/*
 * Centro de avisos (lote 0.4.0, docs/avisos.md): si la campana está abierta y lógica pura de las secciones «Avisos».
 * Este archivo lo importa el marco de la app (la barra lateral): sin textos de un módulo aquí.
 */

interface NoticeCenterState {
    open: boolean;
    setOpen: (open: boolean) => void;
}

export const useNoticeCenter = create<NoticeCenterState>((set) => ({
    open: false,
    setOpen: (open) => set({ open }),
}));

/** Lo que dice el globo de la campana: nada sin avisos, el número hasta 9 y «9+» después. */
export function unreadBadge(count: number | null | undefined): string | null {
    if (count === null || count === undefined || count <= 0) return null;
    return count > 9 ? '9+' : String(count);
}

export type NoticeTone = 'success' | 'info' | 'warning' | 'destructive';

/** El color del estado de un aviso: salió, en camino, aplazado o no salió. */
export function noticeTone(status: NoticeLogRow['status']): NoticeTone {
    switch (status) {
        case 'sent':
            return 'success';
        case 'queued':
            return 'info';
        case 'deferred':
        case 'skipped':
            return 'warning';
        default:
            return 'destructive';
    }
}

/**
 * Dónde se arregla un aviso que no salió (ruta dentro del espacio de trabajo), o null si no hay nada que hacer en el
 * panel (p. ej. el cliente no dejó su WhatsApp). `platformAdmin`: el correo de la plataforma solo lo arregla quien la
 * administra.
 */
export function reasonFix(reason: string | null, channel: NoticeLogRow['channel'], platformAdmin: boolean): string | null {
    switch (reason) {
        case 'no_whatsapp_channel':
            return 'conexiones?tarjeta=whatsapp';
        case 'no_whatsapp_template':
            return 'agenda?pestana=pagina';
        case 'no_email':
            return 'agenda?pestana=pagina';
        case 'channel_unavailable':
            return channel === 'whatsapp' ? 'conexiones?tarjeta=whatsapp' : 'conexiones?tarjeta=email';
        case 'plan_no_platform_email':
            return 'facturacion';
        case 'platform_mail_off':
        case 'platform_mail':
            return platformAdmin ? 'plataforma?pestana=ajustes&seccion=mail' : null;
        case 'no_phone':
            return null;
        default:
            return null;
    }
}

/** Los renglones de la sección «Avisos» separados: al cliente y al equipo, en orden. */
export function splitByAudience(rows: NoticeLogRow[]): { customer: NoticeLogRow[]; team: NoticeLogRow[] } {
    return { customer: rows.filter((r) => r.audience === 'customer'), team: rows.filter((r) => r.audience === 'team') };
}

/** ¿Hubo algún aviso al cliente que salió (o está en camino)? */
export function customerReached(rows: NoticeLogRow[]): boolean {
    return rows.some((r) => r.audience === 'customer' && (r.status === 'sent' || r.status === 'queued') && r.reason !== 'in_chat');
}
