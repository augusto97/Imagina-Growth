import type { Vertical } from '@/features/strategies/types';
import type { TemplateCategory, TemplateDetail, TemplateStatus } from '@/features/templates/api';

/* Galería de plantillas por objetivo y vertical (modulos.md M08 «Galería»): GET /api/w/{ws}/templates/gallery. */

export type GalleryGoal = 'welcome' | 'promotion' | 'news' | 'reminder' | 'event_invite' | 'seasonal' | 'survey' | 'review' | 'follow_up';
export type GalleryChannel = 'email' | 'whatsapp';

export interface WaButtonProposal {
    type: 'QUICK_REPLY' | 'URL';
    text: string;
    /** Los de URL van por un enlace rastreado. */
    tracked?: boolean;
}

/** Lo que se envía a revisión de Meta: nombre, categoría, cuerpo con {{1}}, {{2}}…, pie y botones. */
export interface WaProposal {
    name: string;
    category: 'marketing' | 'utility';
    body: string;
    footer: string | null;
    buttons: WaButtonProposal[];
}

export interface GalleryEntry {
    key: string;
    name: string;
    /** Número del flujo de la biblioteca que la usa; null en las propias de la galería (gal_* y wa_gal_*). */
    flow: number | null;
    channel: GalleryChannel;
    category: TemplateCategory;
    description: string;
    goal: GalleryGoal;
    /** `all` o las verticales para las que se sugiere. */
    verticals: Array<Vertical | 'all'>;
    subject: string | null;
    preheader: string | null;
    /** Texto del correo o el cuerpo de WhatsApp con variables Liquid. */
    text: string | null;
    whatsapp: WaProposal | null;
    has_industry_copy: boolean;
    /** La copia más antigua ya instalada (la que usan los flujos); `linked` = enlazada con una aprobada en Meta. */
    installed: { id: string; status: TemplateStatus; linked: boolean } | null;
}

export interface GalleryResponse {
    goals: GalleryGoal[];
    verticals: Vertical[];
    /** La vertical filtrada (la del espacio de trabajo por defecto); null con `vertical=all`. */
    vertical: Vertical | null;
    industry: string | null;
    whatsapp_connected: boolean;
    data: GalleryEntry[];
}

export interface GalleryFilters {
    goal?: GalleryGoal;
    vertical?: Vertical | 'all';
    channel?: GalleryChannel;
    industry?: string | null;
}

export type WaSubmissionStatus = 'not_connected' | 'submitted' | 'linked' | 'ready_to_submit' | 'failed';

export interface WaSubmission {
    status: WaSubmissionStatus;
    /** Por qué no se envió o lo que respondió Meta. */
    message?: string;
    wa_template_id?: string;
    wa_status?: string;
}

/** POST templates/library/{clave}: la plantilla instalada (201 nueva, 200 ya estaba) y, si es de WhatsApp, el envío a Meta. */
export type GalleryInstallResult = TemplateDetail & { whatsapp_submission: WaSubmission | null };

export interface GalleryInstallInput {
    key: string;
    industry?: string | null;
    /** Otra copia aunque ya exista una. */
    copy?: boolean;
    /** WhatsApp: enviarla a revisión de Meta si hay un número oficial activo (sí por defecto). */
    submit?: boolean;
    /** WhatsApp: la cuenta (`cha_…`) si hay más de un número. */
    account?: string;
}

/** Vista previa de una de WhatsApp de la biblioteca: el texto con los datos de ejemplo y la propuesta para Meta. */
export interface WaLibraryPreview {
    ok: boolean;
    text?: string;
    message?: string;
    whatsapp?: WaProposal;
}
