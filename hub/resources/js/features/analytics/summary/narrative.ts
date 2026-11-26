import type { ServicesResults } from '@/features/services/types';
import type { SummaryData } from '../api';
import type { Conclusion } from '../conclusions';
import type { Formatters } from '../format';

/*
 * La frase que explica el Resumen de Resultados (rediseño v2: «lo que vendiste y lo que generó la plataforma… con una
 * frase que lo explica»). Sin IA: frases fijas con las cifras que ya calculó el servidor, en el orden en que se leen
 * (ventas, dinero generado, de dónde salió). Cada una es una llave de `analytics.summary.say.<key>`.
 */

/** El nombre de una fuente de contactos: el que manda el servidor (ya traducido) o, si llega la clave cruda, el nuestro. */
export function leadSourceName(row: { channel: string; label: string }, fallback: (channel: string) => string): string {
    const label = row.label !== '' && row.label !== row.channel ? row.label : fallback(row.channel);
    return label.charAt(0).toLocaleUpperCase('es') + label.slice(1);
}

/** Por debajo de este cambio (en valor absoluto) se dice «igual que antes». */
const FLAT = 0.005;

function changeOf(now: number, before: number | null | undefined): number | null {
    return before !== null && before !== undefined && before > 0 ? (now - before) / before : null;
}

function direction(change: number): 'Up' | 'Down' | 'Flat' {
    return Math.abs(change) < FLAT ? 'Flat' : change > 0 ? 'Up' : 'Down';
}

/** Tienda: cuánto vendió, cuánto generó la plataforma, qué estrategia y por qué canal. */
export function storeNarrative(data: SummaryData, fmt: Formatters, channelName: (channel: string) => string): Conclusion[] {
    const out: Conclusion[] = [];
    const revenue = data.sales.totals.revenue ?? 0;
    const salesChange = changeOf(revenue, data.sales.compare?.revenue);
    out.push(
        salesChange === null
            ? { key: 'sales', values: { amount: fmt.money(revenue) } }
            : { key: `sales${direction(salesChange)}`, values: { amount: fmt.money(revenue), change: fmt.percent(Math.abs(salesChange), 1) } },
    );

    const g = data.generated;
    if (g.total <= 0) {
        out.push({ key: 'generatedNone', values: {} });
        return out;
    }
    out.push({ key: 'generated', values: { amount: fmt.money(g.total), share: fmt.percent(g.share_pct / 100, 1) } });
    const top = [...data.by_strategy].sort((a, b) => b.revenue - a.revenue)[0];
    const channel = [...data.by_channel].sort((a, b) => b.revenue - a.revenue)[0];
    if (top && top.revenue > 0) {
        out.push(
            channel && channel.channel !== 'other'
                ? { key: 'topVia', values: { name: top.name, amount: fmt.money(top.revenue), channel: channelName(channel.channel) } }
                : { key: 'top', values: { name: top.name, amount: fmt.money(top.revenue) } },
        );
    }
    const generatedChange = changeOf(g.total, g.previous?.total);
    if (generatedChange !== null) {
        out.push({ key: `generated${direction(generatedChange)}`, values: { change: fmt.percent(Math.abs(generatedChange), 1) } });
    }
    return out;
}

/** La etapa del embudo de leads donde más se pierden (sin contar «perdido»). */
export function servicesDrop(stages: ServicesResults['leads']['funnel']['stages'], fmt: Formatters): Conclusion | null {
    const path = stages.filter((s) => s.kind !== 'lost');
    let worst: { from: string; to: string; rate: number } | null = null;
    for (let i = 1; i < path.length; i++) {
        const before = path[i - 1];
        const stage = path[i];
        if (!before || !stage || before.leads <= 0) {
            continue;
        }
        const rate = stage.leads / before.leads;
        if (worst === null || rate < worst.rate) {
            worst = { from: before.label, to: stage.label, rate };
        }
    }
    return worst === null ? null : { key: 'servicesDrop', values: { from: worst.from, to: worst.to, rate: fmt.percent(worst.rate, 0) } };
}

/** Servicios: cuántos contactos llegaron, cuántos se ganaron y cuánto trajo la plataforma (y por dónde). */
export function servicesNarrative(r: ServicesResults, fmt: Formatters): Conclusion[] {
    const out: Conclusion[] = [];
    const leads = r.kpis.leads;
    const leadsChange = changeOf(leads.value ?? 0, leads.previous);
    out.push(
        leadsChange === null
            ? { key: 'leads', values: { count: leads.value ?? 0, formatted: fmt.number(leads.value ?? 0) } }
            : { key: `leads${direction(leadsChange)}`, values: { count: leads.value ?? 0, formatted: fmt.number(leads.value ?? 0), change: fmt.percent(Math.abs(leadsChange), 0) } },
    );
    const wonValue = Number(r.won.value);
    out.push(r.won.count > 0 ? { key: 'won', values: { count: r.won.count, formatted: fmt.number(r.won.count), amount: fmt.money(wonValue) } } : { key: 'wonNone', values: {} });
    const platform = Number(r.attribution.platform_value);
    if (platform > 0) {
        const top = [...r.attribution.groups].filter((g) => g.key !== 'other').sort((a, b) => Number(b.value) - Number(a.value))[0];
        out.push(top ? { key: 'platformVia', values: { amount: fmt.money(platform), source: top.label.toLowerCase() } } : { key: 'platform', values: { amount: fmt.money(platform) } });
    }
    const drop = servicesDrop(r.leads.funnel.stages, fmt);
    if (drop) {
        out.push(drop);
    }
    return out;
}
