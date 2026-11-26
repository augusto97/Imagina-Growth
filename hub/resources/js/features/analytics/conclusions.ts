import type { ChannelsData, CouponsData, CustomersData, FunnelData, GeographyData, HdyhauData, MessagingData, ProductsData, SearchesData, ServicesData } from './api';
import type { Formatters } from './format';

/*
 * La conclusión de cada gráfica de los tableros (rediseño v2: «cada gráfica con un título que dice la conclusión y no
 * solo el dato»). Funciones puras: reciben los datos ya calculados por el servidor y devuelven la llave del texto
 * (`analytics.conclusion.<key>`) con sus valores ya formateados, o null si no hay suficiente para concluir (entonces la
 * gráfica lleva su título de siempre). Nunca inventan cifras: todo sale de la respuesta del tablero.
 */

export interface Conclusion {
    key: string;
    values: Record<string, string | number>;
}

/** Por debajo de este cambio (en valor absoluto) se dice «igual que antes». */
const FLAT = 0.005;

function share(part: number, whole: number): number | null {
    return whole > 0 ? part / whole : null;
}

/**
 * Una métrica del periodo frente a la comparación («Ventas: 19,8 % menos que el periodo anterior») o, sin comparación,
 * su mejor día, semana o mes. `format` da la cifra con su unidad.
 */
export function trendConclusion(
    metric: string,
    current: number | undefined,
    previous: number | undefined | null,
    series: { date: string; value: number }[],
    granularity: 'day' | 'week' | 'month',
    format: (v: number) => string,
    fmt: Formatters,
): Conclusion | null {
    const now = current ?? 0;
    if (previous !== null && previous !== undefined && previous > 0) {
        const change = (now - previous) / previous;
        if (Math.abs(change) < FLAT) {
            return { key: 'trendFlat', values: { metric, amount: format(now) } };
        }
        return { key: change > 0 ? 'trendUp' : 'trendDown', values: { metric, amount: format(now), change: fmt.percent(Math.abs(change), 1) } };
    }
    const best = series.reduce<{ date: string; value: number } | null>((top, row) => (top === null || row.value > top.value ? row : top), null);
    if (best === null || best.value <= 0) {
        return null;
    }
    return { key: `trendBest_${granularity}`, values: { metric, amount: format(best.value), date: fmt.bucket(best.date, granularity) } };
}

/** El paso del embudo donde más gente se queda (la tasa de paso más baja). */
export function funnelDrop(steps: FunnelData['steps'], label: (step: string) => string, fmt: Formatters): Conclusion | null {
    let worst: { from: string; to: string; rate: number } | null = null;
    for (let i = 1; i < steps.length; i++) {
        const before = steps[i - 1];
        const step = steps[i];
        if (!before || !step || before.value <= 0) {
            continue;
        }
        if (worst === null || step.rate_from_previous < worst.rate) {
            worst = { from: before.step, to: step.step, rate: step.rate_from_previous };
        }
    }
    if (worst === null) {
        return null;
    }
    return { key: 'funnelDrop', values: { from: label(worst.from), to: label(worst.to), rate: fmt.percent(worst.rate, 1), lost: fmt.percent(1 - worst.rate, 0) } };
}

/** El canal que más vende y qué parte de las ventas trae. */
export function channelsTop(channels: ChannelsData['channels'], label: (channel: string) => string, fmt: Formatters): Conclusion | null {
    const total = channels.reduce((sum, c) => sum + c.revenue, 0);
    const top = [...channels].sort((a, b) => b.revenue - a.revenue)[0];
    if (!top || top.revenue <= 0) {
        return null;
    }
    return { key: 'channelsTop', values: { channel: label(top.channel), share: fmt.percent(share(top.revenue, total), 0), amount: fmt.money(top.revenue) } };
}

/** El grupo de clientes (RFM) que más deja. */
export function rfmTop(segments: CustomersData['rfm']['segments'], label: (segment: string) => string, fmt: Formatters): Conclusion | null {
    const top = [...segments].sort((a, b) => b.revenue_share - a.revenue_share)[0];
    if (!top || top.customers <= 0) {
        return null;
    }
    return { key: 'rfmTop', values: { segment: label(top.segment), customers: fmt.number(top.customers), share: fmt.percent(top.revenue_share, 0) } };
}

/** Cuántos vuelven al mes siguiente de su primera compra (promedio de las cohortes con dato). */
export function cohortsReturn(cohorts: CustomersData['cohorts'], fmt: Formatters): Conclusion | null {
    const firstMonth = cohorts.map((c) => c.retention[0]).filter((v): v is number => typeof v === 'number');
    if (firstMonth.length === 0) {
        return null;
    }
    const avg = firstMonth.reduce((sum, v) => sum + v, 0) / firstMonth.length;
    return { key: 'cohortsReturn', values: { rate: fmt.percent(avg, 1) } };
}

/** Qué parte de las ventas viene de clientes que vuelven. */
export function returningShare(rows: CustomersData['new_vs_returning'], fmt: Formatters): Conclusion | null {
    const returning = rows.reduce((sum, r) => sum + r.returning_revenue, 0);
    const total = rows.reduce((sum, r) => sum + r.new_revenue + r.returning_revenue, 0);
    const s = share(returning, total);
    return s === null ? null : { key: 'returningShare', values: { share: fmt.percent(s, 0) } };
}

export function productsTop(products: ProductsData['products'], fmt: Formatters): Conclusion | null {
    const top = [...products].sort((a, b) => b.revenue - a.revenue)[0];
    return top && top.revenue > 0 ? { key: 'productsTop', values: { name: top.name, amount: fmt.money(top.revenue) } } : null;
}

export function viewedNotSold(rows: ProductsData['viewed_not_sold'], fmt: Formatters): Conclusion | null {
    return rows.length > 0 ? { key: 'viewedNotSold', values: { count: rows.length, formatted: fmt.number(rows.length) } } : null;
}

export function entryTop(rows: ProductsData['entry_products'], fmt: Formatters): Conclusion | null {
    const top = [...rows].sort((a, b) => b.avg_ltv - a.avg_ltv)[0];
    return top ? { key: 'entryTop', values: { name: top.name, ltv: fmt.money(top.avg_ltv) } } : null;
}

export function pairsTop(rows: ProductsData['bought_together'], fmt: Formatters): Conclusion | null {
    const top = [...rows].sort((a, b) => b.orders - a.orders)[0];
    return top ? { key: 'pairsTop', values: { a: top.a.name, b: top.b.name, orders: fmt.number(top.orders) } } : null;
}

export function searchesZero(data: SearchesData, fmt: Formatters): Conclusion | null {
    if (data.total_searches <= 0) {
        return null;
    }
    const top = data.terms[0];
    return data.zero_result_share > 0
        ? { key: 'searchesZero', values: { share: fmt.percent(data.zero_result_share, 0) } }
        : top
          ? { key: 'searchesTop', values: { term: top.query } }
          : null;
}

export function zeroTop(rows: SearchesData['zero_results']): Conclusion | null {
    const top = [...rows].sort((a, b) => b.searches - a.searches)[0];
    return top ? { key: 'zeroTop', values: { term: top.query } } : null;
}

export function regionTop(regions: GeographyData['regions'], label: (region: string) => string, fmt: Formatters): Conclusion | null {
    const total = regions.reduce((sum, r) => sum + r.revenue, 0);
    const top = [...regions].sort((a, b) => b.revenue - a.revenue)[0];
    return top && top.revenue > 0 ? { key: 'regionTop', values: { region: label(top.region), share: fmt.percent(share(top.revenue, total), 0) } } : null;
}

export function cityTop(cities: GeographyData['cities'], fmt: Formatters): Conclusion | null {
    const top = [...cities].sort((a, b) => b.revenue - a.revenue)[0];
    return top && top.revenue > 0 && top.city !== '' ? { key: 'cityTop', values: { city: top.city, amount: fmt.money(top.revenue) } } : null;
}

export function couponsShare(data: CouponsData, fmt: Formatters): Conclusion | null {
    if (data.orders <= 0) {
        return null;
    }
    return data.orders_with_coupon > 0
        ? { key: 'couponsShare', values: { share: fmt.percent(data.coupon_share, 0), discount: fmt.money(data.discount) } }
        : { key: 'couponsNone', values: {} };
}

export function couponsTop(codes: CouponsData['codes'], fmt: Formatters): Conclusion | null {
    const top = [...codes].sort((a, b) => b.orders - a.orders)[0];
    return top ? { key: 'couponsTop', values: { code: top.code, orders: fmt.number(top.orders) } } : null;
}

/** Lo que vendieron los mensajes y, si hay costo, cuántas veces se pagaron. */
export function messagingRevenue(totals: MessagingData['totals'], fmt: Formatters): Conclusion | null {
    if (totals.sent <= 0) {
        return null;
    }
    if (totals.revenue > 0 && typeof totals.roi === 'number' && totals.roi > 0) {
        return { key: 'messagingRoi', values: { amount: fmt.money(totals.revenue), roi: fmt.decimal(totals.roi, 1) } };
    }
    if (totals.revenue > 0) {
        return { key: 'messagingRevenue', values: { amount: fmt.money(totals.revenue), sent: fmt.number(totals.sent) } };
    }
    return { key: 'messagingNoSales', values: { sent: fmt.number(totals.sent) } };
}

export function hdyhauTop(answers: HdyhauData['answers'], label: (answer: string) => string, fmt: Formatters): Conclusion | null {
    const top = [...answers].sort((a, b) => b.responses - a.responses)[0];
    return top ? { key: 'hdyhauTop', values: { answer: label(top.answer), share: fmt.percent(top.share, 0) } } : null;
}

export function servicesTopForm(forms: ServicesData['forms'], fmt: Formatters): Conclusion | null {
    const top = [...forms].sort((a, b) => b.submissions - a.submissions)[0];
    return top && top.submissions > 0 ? { key: 'servicesTopForm', values: { form: top.form || top.page, count: fmt.number(top.submissions) } } : null;
}

export function servicesTopPage(pages: ServicesData['pages'], fmt: Formatters): Conclusion | null {
    const best = [...pages].filter((p) => p.views >= 20).sort((a, b) => b.form_rate - a.form_rate)[0];
    return best && best.form_rate > 0 ? { key: 'servicesTopPage', values: { page: best.path, rate: fmt.percent(best.form_rate, 1) } } : null;
}
