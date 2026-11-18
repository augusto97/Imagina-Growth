import type { BillingStatus, Meter, MeterState, PlanInfo } from './api';

/* Reglas de presentación de «Plan y facturación», sin React (se prueban con Vitest). */

/** Avisos al 80 % y al 100 % (M21). El servidor manda el estado; esto lo recalcula si falta el límite. */
export function meterState(pct: number | null): MeterState {
    if (pct === null) return 'ok';
    if (pct >= 100) return 'critical';
    if (pct >= 80) return 'warning';
    return 'ok';
}

/** Ancho de la barra: nunca pasa del 100 % aunque el uso lo supere. */
export function meterWidth(meter: Pick<Meter, 'value' | 'limit'>): number {
    if (meter.limit === null || meter.limit <= 0) return 0;
    return Math.max(0, Math.min(1, meter.value / meter.limit));
}

/** Medidores que van arriba (con límite del plan) y el resto como conteos. */
export function splitMeters(meters: Meter[]): {
    limited: Meter[];
    counts: Meter[];
} {
    return {
        limited: meters.filter((m) => m.limit !== null),
        counts: meters.filter((m) => m.limit === null),
    };
}

export type Banner = {
    tone: 'warning' | 'critical';
    kind: 'grace' | 'suspended' | 'trial';
    until: string | null;
} | null;

/** Franja de aviso: gracia (todo sigue hasta una fecha) o suspendida (marketing e importaciones en pausa). */
export function billingBanner(status: BillingStatus, graceEndsAt: string | null, trialEndsAt: string | null, now: Date = new Date()): Banner {
    if (status === 'suspended') return { tone: 'critical', kind: 'suspended', until: null };
    if (status === 'grace') return { tone: 'warning', kind: 'grace', until: graceEndsAt };
    if (status === 'trial' && trialEndsAt !== null) {
        const days = (new Date(trialEndsAt).getTime() - now.getTime()) / 86_400_000;
        return days <= 3 ? { tone: 'warning', kind: 'trial', until: trialEndsAt } : null;
    }
    return null;
}

/** Precio del plan en la moneda del espacio (COP si existe, si no USD). */
export function planPrice(plan: PlanInfo, currency: string): { amount: string; currency: string } {
    if (currency === 'COP' && plan.price_cop !== null) return { amount: plan.price_cop, currency: 'COP' };
    return { amount: plan.price_usd, currency: 'USD' };
}

/** Planes comparables: los de la misma métrica (tiendas por pedidos, servicios por contactos), del más barato al más caro. */
export function comparablePlans(plans: PlanInfo[], current: PlanInfo | null): PlanInfo[] {
    const metric = current?.metric;
    const list = metric ? plans.filter((p) => p.metric === metric) : plans;
    return [...list].sort((a, b) => Number(a.price_usd) - Number(b.price_usd));
}
