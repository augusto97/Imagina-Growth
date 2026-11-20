import { useTranslation } from 'react-i18next';
import { formatMoney } from '@/lib/format';

/*
 * Costo estimado de los mensajes y su retorno (docs/auditoria-2026-11.md F11). Lo usan el tablero de Mensajería, el
 * informe de cada campaña y la analítica de cada flujo. El hub lo calcula en USD (tarifas de Meta, SES y Twilio) y lo
 * pasa a la moneda del espacio de trabajo con la tasa del día; sin tasa, llega solo en USD.
 */

export interface CostMeta {
    currency: string;
    estimated: true;
    fx: { rate: number | null; date: string | null; source: string | null; manual: boolean; stale: boolean };
    /** La fuente libre de tasas pide citarla con su enlace donde se muestran. */
    attribution_url: string | null;
}

export interface CostFields {
    /** En la moneda del espacio de trabajo; null sin tasa de cambio. */
    cost: number | null;
    cost_usd: number;
    /** Ventas atribuidas ÷ costo. */
    roi: number | null;
    cost_per_sale: number | null;
}

/** Dólares con los centavos que hacen falta (un mensaje cuesta fracciones de centavo). */
export function formatUsd(value: number, locale: string): string {
    const digits = value > 0 && value < 1 ? 4 : 2;
    try {
        return new Intl.NumberFormat(locale, { style: 'currency', currency: 'USD', minimumFractionDigits: 2, maximumFractionDigits: digits }).format(value);
    } catch {
        return `USD ${value.toFixed(digits)}`;
    }
}

/** El costo en la moneda del espacio de trabajo o, sin tasa de cambio, en dólares. */
export function formatCost(row: Pick<CostFields, 'cost' | 'cost_usd'>, currency: string, locale: string): string {
    return row.cost !== null ? formatMoney(row.cost, currency, locale) : formatUsd(row.cost_usd, locale);
}

/** Retorno como «12,5×» (cuántas veces las ventas cubren el costo). */
export function formatRoi(roi: number | null | undefined, locale: string): string {
    if (roi === null || roi === undefined) {
        return '—';
    }
    return `${new Intl.NumberFormat(locale, { maximumFractionDigits: roi < 10 ? 1 : 0 }).format(roi)}×`;
}

/** Costo por venta: en la moneda del espacio de trabajo; sin tasa o sin ventas, «—». */
export function formatCostPerSale(row: Pick<CostFields, 'cost_per_sale'>, currency: string, locale: string): string {
    return row.cost_per_sale === null ? '—' : formatMoney(row.cost_per_sale, currency, locale);
}

/** Aclara que el costo es estimado, con qué tasa y de dónde sale (con el enlace que pide la fuente). */
export function CostNote({ meta, locale, className }: { meta: CostMeta; locale: string; className?: string }) {
    const { t } = useTranslation();
    const date = meta.fx.date
        ? new Intl.DateTimeFormat(locale, { timeZone: 'UTC', day: 'numeric', month: 'short', year: 'numeric' }).format(new Date(`${meta.fx.date}T12:00:00Z`))
        : '';
    const rate = meta.fx.rate !== null ? new Intl.NumberFormat(locale, { maximumFractionDigits: meta.fx.rate >= 100 ? 2 : 4 }).format(meta.fx.rate) : '';
    let text: string;
    if (meta.currency === 'USD') {
        text = t('analytics.cost.noteUsd');
    } else if (meta.fx.rate === null) {
        text = t('analytics.cost.noRate', { currency: meta.currency });
    } else {
        text = t(meta.fx.manual ? 'analytics.cost.noteManual' : 'analytics.cost.note', { currency: meta.currency, rate, date });
    }

    return (
        <p className={className ?? 'igr:text-xs igr:text-muted-foreground'}>
            {text}
            {meta.fx.stale && meta.fx.rate !== null ? ` ${t('analytics.cost.stale')}` : ''}
            {meta.attribution_url ? (
                <>
                    {' '}
                    <a href={meta.attribution_url} target="_blank" rel="noreferrer" className="igr:underline igr:underline-offset-2">
                        {t('analytics.cost.attribution')}
                    </a>
                </>
            ) : null}
        </p>
    );
}
