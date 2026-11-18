import type { AgentSettings, ChatMessage, SandboxResult, TurnStatus } from './types';

/** Palabras de traspaso escritas separadas por comas → lista limpia, sin repetir y sin vacías. */
export function parseKeywords(text: string): string[] {
    const seen = new Set<string>();
    const out: string[] = [];
    for (const raw of text.split(',')) {
        const word = raw.trim().replace(/\s+/g, ' ').slice(0, 40);
        const key = word.toLowerCase();
        if (word !== '' && !seen.has(key)) {
            seen.add(key);
            out.push(word);
        }
    }
    return out.slice(0, 30);
}

/** Lo que se manda al guardar: descuentos sin datos si están apagados y monto mínimo como número o null. */
export function settingsPayload(s: AgentSettings): AgentSettings {
    const min = s.discount_rules.min_subtotal;
    const minNumber = min === null || min === '' ? null : Number(min);
    return {
        ...s,
        scope: s.scope?.trim() || null,
        tone: s.tone?.trim() || null,
        discount_rules: s.discount_rules.enabled
            ? { enabled: true, coupon_code: s.discount_rules.coupon_code?.trim() || null, min_subtotal: Number.isFinite(minNumber) ? minNumber : null, description: s.discount_rules.description?.trim() || null }
            : { enabled: false, coupon_code: null, min_subtotal: null, description: null },
    };
}

/** Tono del estado de un turno para la insignia. */
export function statusTone(status: TurnStatus): 'success' | 'warning' | 'destructive' | 'muted' {
    switch (status) {
        case 'replied':
            return 'success';
        case 'suggested':
            return 'muted';
        case 'handoff':
            return 'warning';
        default:
            return 'destructive';
    }
}

/** Variante de la insignia del estado. */
export function statusBadge(status: TurnStatus): 'success' | 'secondary' | 'warning' | 'destructive' {
    const tone = statusTone(status);
    return tone === 'muted' ? 'secondary' : tone;
}

/** Conversación de prueba después de una respuesta: el agente responde o avisa que pasaría a una persona. */
export function appendSandboxReply(history: ChatMessage[], result: SandboxResult, handoffText: string): ChatMessage[] {
    const text = result.reply && result.reply.trim() !== '' ? result.reply : handoffText;
    return [...history, { role: 'assistant', content: text }];
}

/** Motivo de traspaso como clave de i18n (los de reglas llegan como «intent:complaint»). */
export function reasonKey(reason: string | null): string | null {
    if (reason === null || reason === '') return null;
    const known = ['keyword', 'business_hours', 'window_closed', 'unverified_numbers', 'rate_limit', 'optout', 'no_text', 'refusal', 'empty_reply', 'ai_unavailable', 'send_denied'];
    if (known.includes(reason)) return reason;
    if (reason.startsWith('intent:')) return 'intent';
    return null;
}
