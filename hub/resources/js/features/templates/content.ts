import type { TemplateCategory, TemplateChannel, TemplateCheck, WaSlots, WaVariables } from './api';

/* Reglas que el editor muestra en vivo, iguales a las del servidor (TemplateChecks.php). */

const GSM7 = "@£$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";
const GSM7_EXT = '^{}\\[~]|€';

export interface SmsInfo {
    encoding: 'GSM-7' | 'UCS-2';
    length: number;
    segments: number;
}

/** Partes de un SMS: 160/153 caracteres en GSM-7; 70/67 si hay tildes, eñes o emojis. */
export function smsSegments(text: string): SmsInfo {
    let gsm = true;
    let length = 0;
    for (const char of Array.from(text)) {
        if (GSM7_EXT.includes(char)) length += 2;
        else if (GSM7.includes(char)) length += 1;
        else gsm = false;
    }
    if (!gsm) {
        const chars = Array.from(text).length;
        return { encoding: 'UCS-2', length: chars, segments: chars <= 70 ? 1 : Math.ceil(chars / 67) };
    }
    return { encoding: 'GSM-7', length, segments: length <= 160 ? 1 : Math.ceil(length / 153) };
}

function usesVariable(body: string, variable: string): boolean {
    const escaped = variable.replace(/\./g, '\\.');
    return new RegExp(`\\{\\{[^}]*${escaped}`).test(body);
}

export interface DraftForChecks {
    channel: TemplateChannel;
    category: TemplateCategory;
    subject?: string | null;
    preheader?: string | null;
    body?: string | null; // HTML o MJML del correo, o el texto del SMS
}

/** Revisiones rápidas mientras se escribe (el servidor hace la revisión completa al guardar). */
export function liveChecks(draft: DraftForChecks): TemplateCheck[] {
    const out: TemplateCheck[] = [];
    const body = draft.body ?? '';
    if (draft.channel === 'email') {
        const subject = (draft.subject ?? '').trim();
        if (subject === '') out.push({ level: 'error', code: 'subject_missing', message: 'subject_missing' });
        else if (Array.from(subject).length > 80) out.push({ level: 'warning', code: 'subject_long', message: 'subject_long' });
        if (Array.from(draft.preheader ?? '').length > 120) out.push({ level: 'warning', code: 'preheader_long', message: 'preheader_long' });
        if (draft.category === 'marketing' && body.trim() !== '' && !usesVariable(body, 'enlaces.baja')) {
            out.push({ level: 'error', code: 'unsubscribe_missing', message: 'unsubscribe_missing' });
        }
    }
    if (draft.channel === 'sms') {
        if (body.trim() === '') out.push({ level: 'error', code: 'body_missing', message: 'body_missing' });
        const info = smsSegments(body);
        if (info.segments > 3) out.push({ level: 'warning', code: 'sms_long', message: 'sms_long' });
        if (draft.category === 'marketing' && body.trim() !== '' && !/\b(baja|stop|salir)\b/i.test(body)) {
            out.push({ level: 'error', code: 'sms_optout_missing', message: 'sms_optout_missing' });
        }
    }
    return out;
}

/** Inserta texto en la posición del cursor y devuelve el valor y el nuevo cursor. */
export function insertAt(value: string, start: number, end: number, insertion: string): { value: string; caret: number } {
    const from = Math.max(0, Math.min(start, value.length));
    const to = Math.max(from, Math.min(end, value.length));
    return { value: value.slice(0, from) + insertion + value.slice(to), caret: from + insertion.length };
}

/** Ranuras de WhatsApp que todavía no tienen variable. */
export function missingWaSlots(slots: WaSlots, mapping: WaVariables): string[] {
    const missing: string[] = [];
    for (const part of ['header', 'body'] as const) {
        for (const key of slots[part]) {
            if (!(mapping[part]?.[key] ?? '').trim()) missing.push(`${part}.${key}`);
        }
    }
    for (const b of slots.buttons) {
        if (b.needs_value && !(mapping.buttons?.[String(b.index)] ?? '').trim()) missing.push(`buttons.${b.index}`);
    }
    if (slots.header_media && !(mapping.header_media ?? '').trim()) missing.push('header_media');
    return missing;
}

/** Texto de la plantilla de WhatsApp con los parámetros resueltos (vista previa en burbuja). */
export function fillWaText(text: string, values: string[] | Record<string, string>): string {
    return text.replace(/\{\{\s*([A-Za-z0-9_]+)\s*\}\}/g, (_, key: string) => {
        const value = Array.isArray(values) ? values[Number(key) - 1] : values[key];
        return value ?? `{{${key}}}`;
    });
}

/** Correo nuevo en blanco: encabezado, texto, botón y el pie legal obligatorio. */
export const BLANK_EMAIL_MJML = `<mjml>
  <mj-head>
    <mj-attributes>
      <mj-all font-family="Inter, Helvetica, Arial, sans-serif" />
      <mj-text padding="8px 0" />
      <mj-section background-color="#ffffff" />
    </mj-attributes>
  </mj-head>
  <mj-body background-color="#f3f4f6" width="600px">
    <mj-section padding="24px 24px 8px">
      <mj-column>
        <mj-text align="center" font-size="20px" font-weight="700" color="#111827">{{ tienda.nombre }}</mj-text>
      </mj-column>
    </mj-section>
    <mj-section padding="8px 24px">
      <mj-column>
        <mj-text font-size="24px" line-height="32px" font-weight="700" color="#111827">Hola {{ contacto.nombre | default: 'hola' }}</mj-text>
        <mj-text font-size="16px" line-height="24px" color="#111827">Escribe aquí tu mensaje.</mj-text>
        <mj-button href="{{ tienda.url }}" background-color="#111827" color="#ffffff" border-radius="8px" font-size="16px" font-weight="600" inner-padding="14px 28px">Ir a la tienda</mj-button>
      </mj-column>
    </mj-section>
    <mj-section padding="24px 24px 32px">
      <mj-column>
        <mj-divider border-color="#e5e7eb" border-width="1px" padding="0 0 16px" />
        <mj-text align="center" font-size="12px" line-height="18px" color="#6b7280">Recibes este correo porque aceptaste recibir novedades de {{ tienda.nombre }}.<br>{{ tienda.nombre }} · {{ tienda.direccion }} · {{ tienda.contacto }}<br><a href="{{ enlaces.preferencias }}" style="color:#6b7280">Preferencias</a> · <a href="{{ enlaces.baja }}" style="color:#6b7280">Darte de baja</a></mj-text>
      </mj-column>
    </mj-section>
  </mj-body>
</mjml>`;
