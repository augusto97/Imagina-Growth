import { describe, expect, it } from 'vitest';
import type { MessagingCounts } from '../api';
import { messagingRates } from './MessagingDashboard';

const counts = (c: Partial<MessagingCounts>): MessagingCounts => ({
    total: 0,
    sent: 0,
    delivered: 0,
    read: 0,
    opened: 0,
    clicked: 0,
    replied: 0,
    unsubscribed: 0,
    complained: 0,
    bounced: 0,
    failed: 0,
    orders: 0,
    revenue: 0,
    cost: null,
    cost_usd: 0,
    roi: null,
    cost_per_sale: null,
    ...c,
});

describe('tasas de mensajería', () => {
    it('calcula las tasas sobre lo entregado', () => {
        const r = messagingRates(counts({ total: 110, sent: 100, delivered: 80, read: 40, clicked: 8, unsubscribed: 2, bounced: 5, failed: 5 }));
        expect(r.delivery).toBe(0.8);
        expect(r.read).toBe(0.5);
        expect(r.click).toBe(0.1);
        expect(r.unsubscribe).toBe(0.025);
        expect(r.problems).toBeCloseTo(10 / 110);
    });

    it('sin entregas confirmadas usa lo enviado, y sin envíos no inventa tasas', () => {
        expect(messagingRates(counts({ total: 10, sent: 10, clicked: 2 })).click).toBe(0.2);
        const none = messagingRates(counts({}));
        expect(none.delivery).toBeNull();
        expect(none.click).toBeNull();
    });
});
