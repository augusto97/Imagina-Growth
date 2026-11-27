/*
 * Lo que puede mandar cada número (ChannelCapabilities del hub, M07) dicho en palabras: qué no hace un WhatsApp no
 * oficial (Imagina Whats, Zender) y cómo sale lo que no puede (opciones numeradas, plantillas como texto, la imagen del
 * encabezado aparte o sin ella). Lógica pura, con pruebas en capabilities.test.ts.
 */

export interface ChannelCapabilities {
    official: boolean;
    marketing: boolean;
    templates: boolean;
    free_text: boolean;
    media: boolean;
    media_types?: string[];
    buttons: boolean;
    lists?: boolean;
    template_buttons?: boolean;
    template_media_header?: boolean;
    location?: boolean;
    contact_card?: boolean;
    reactions?: boolean;
    window_24h?: boolean;
    max_text?: number;
    options_as?: 'buttons' | 'numbered';
    rate_per_second: number;
}

/** Lo mínimo que hace falta para explicar un número: el de la API de canales, de la bandeja o de los flujos. */
export type CapabilitySummary = Pick<ChannelCapabilities, 'buttons' | 'templates' | 'media'> & { marketing?: boolean };

/** Llave de texto de `channels.capabilities.*`. */
export type CapabilityNote = 'noMarketing' | 'numbered' | 'templatesAsText' | 'mediaApart' | 'noMedia';

/**
 * Lo que hace distinto este número de WhatsApp, en el orden en que se explica. Un WhatsApp oficial no tiene notas.
 */
export function capabilityNotes(caps: CapabilitySummary): CapabilityNote[] {
    const notes: CapabilityNote[] = [];
    if (caps.marketing === false) notes.push('noMarketing');
    if (!caps.buttons) notes.push('numbered');
    if (!caps.templates) {
        notes.push('templatesAsText');
        notes.push(caps.media ? 'mediaApart' : 'noMedia');
    }
    return notes;
}

/** Lo que pasa con un paso o una plantilla de WhatsApp de marketing por este número. */
export function marketingBlocked(caps: CapabilitySummary | null | undefined, kind: string): boolean {
    return caps !== null && caps !== undefined && caps.marketing === false && kind === 'marketing';
}
