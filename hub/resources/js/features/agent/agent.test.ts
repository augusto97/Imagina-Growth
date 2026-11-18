import { describe, expect, it } from 'vitest';
import { appendSandboxReply, parseKeywords, reasonKey, settingsPayload, statusTone } from './logic';
import type { AgentSettings } from './types';

const base: AgentSettings = {
    enabled: true,
    default_mode: 'assist',
    scope: '  pinturas ',
    tone: '',
    policies: { shipping: '', returns: '', payment_methods: '', hours: '', other: '' },
    handoff_rules: { keywords: [], intents: ['complaint'], hours: { mode: 'always', days: [1, 2, 3, 4, 5], start: '08:00', end: '18:00' } },
    discount_rules: { enabled: false, coupon_code: 'WA10', min_subtotal: '100000', description: '10%' },
    max_steps: 6,
};

describe('agente de ventas', () => {
    it('limpia las palabras de traspaso: sin vacías, sin repetir y con tope', () => {
        expect(parseKeywords(' asesor, Humano ,, asesor,  hablar   con alguien ')).toEqual(['asesor', 'Humano', 'hablar con alguien']);
        expect(parseKeywords(Array.from({ length: 40 }, (_, i) => `p${i}`).join(','))).toHaveLength(30);
    });

    it('al guardar, un descuento apagado no lleva datos y el mínimo es número', () => {
        const off = settingsPayload(base);
        expect(off.discount_rules).toEqual({ enabled: false, coupon_code: null, min_subtotal: null, description: null });
        expect(off.scope).toBe('pinturas');
        expect(off.tone).toBeNull();
        const on = settingsPayload({ ...base, discount_rules: { ...base.discount_rules, enabled: true } });
        expect(on.discount_rules).toEqual({ enabled: true, coupon_code: 'WA10', min_subtotal: 100000, description: '10%' });
    });

    it('la prueba muestra la respuesta o el aviso de traspaso', () => {
        const history = [{ role: 'user' as const, content: '¿precio?' }];
        const base = { id: 'agt_1', status: 'suggested' as const, handoff_reason: null, flagged: false, tool_calls: [], validation: null };
        expect(appendSandboxReply(history, { ...base, reply: 'Está en $141.000.' }, 'pasa')[1]).toEqual({ role: 'assistant', content: 'Está en $141.000.' });
        expect(appendSandboxReply(history, { ...base, status: 'handoff', reply: null }, 'Pasaría a una persona')[1]?.content).toBe('Pasaría a una persona');
    });

    it('traduce estados y motivos', () => {
        expect(statusTone('replied')).toBe('success');
        expect(statusTone('handoff')).toBe('warning');
        expect(statusTone('failed')).toBe('destructive');
        expect(reasonKey('intent:complaint')).toBe('intent');
        expect(reasonKey('queja por pedido')).toBeNull();
        expect(reasonKey('window_closed')).toBe('window_closed');
    });
});
