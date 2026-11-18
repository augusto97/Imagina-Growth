import { ShoppingBag } from 'lucide-react';
import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Bar, BarChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { formatMoney, formatNumber } from '@/lib/format';
import { cn } from '@/lib/cn';
import type { MonthlyPoint } from '../api';
import { compactNumber, fillMonths, monthLabel, trimLeadingEmpty, type MonthBar } from './chart';

type Metric = 'revenue' | 'orders';

/*
 * Compras pagadas por mes (últimos 24). Una sola serie: sin leyenda, el título
 * la nombra. Columnas de 24 px como máximo, esquinas redondeadas arriba y
 * cuadradas en la base, rejilla tenue y tooltip por columna (skill dataviz).
 */
export function MonthlyChart({ monthly, currency, locale }: { monthly: MonthlyPoint[]; currency: string; locale: string }) {
    const { t } = useTranslation();
    const [metric, setMetric] = useState<Metric>('revenue');
    const [now] = useState(() => new Date());
    const bars = useMemo(() => trimLeadingEmpty(fillMonths(monthly, now)), [monthly, now]);
    const hasData = bars.some((b) => b.orders > 0);
    const format = (v: number) => (metric === 'revenue' ? formatMoney(v, currency, locale) : formatNumber(v, locale));

    return (
        <Card>
            <CardHeader className="igr:flex igr:flex-row igr:items-center igr:justify-between igr:gap-2 igr:space-y-0">
                <CardTitle className="igr:text-sm">{metric === 'revenue' ? t('contacts.profile.chart.titleRevenue') : t('contacts.profile.chart.titleOrders')}</CardTitle>
                <div role="radiogroup" aria-label={t('contacts.profile.chart.metric')} className="igr:flex igr:rounded-md igr:border igr:p-0.5 igr:text-xs">
                    {(['revenue', 'orders'] as const).map((m) => (
                        <button
                            key={m}
                            type="button"
                            role="radio"
                            aria-checked={metric === m}
                            onClick={() => setMetric(m)}
                            className={cn(
                                'igr:rounded igr:px-2 igr:py-0.5 igr:outline-none igr:focus-visible:ring-2 igr:focus-visible:ring-ring',
                                metric === m ? 'igr:bg-muted igr:font-medium igr:text-foreground' : 'igr:text-muted-foreground igr:hover:text-foreground',
                            )}
                        >
                            {m === 'revenue' ? t('contacts.profile.chart.revenue') : t('contacts.profile.chart.orders')}
                        </button>
                    ))}
                </div>
            </CardHeader>
            <CardContent>
                {!hasData ? (
                    <div className="igr:flex igr:h-48 igr:flex-col igr:items-center igr:justify-center igr:gap-2 igr:text-center igr:text-sm igr:text-muted-foreground">
                        <ShoppingBag className="igr:size-5" />
                        <p>{t('contacts.profile.chart.empty')}</p>
                    </div>
                ) : (
                    <>
                        <div className="igr:h-52" aria-hidden="true">
                            <ResponsiveContainer width="100%" height="100%">
                                <BarChart data={bars} margin={{ top: 8, right: 4, bottom: 0, left: 0 }}>
                                    <CartesianGrid vertical={false} stroke="var(--border)" strokeWidth={1} />
                                    <XAxis
                                        dataKey="month"
                                        tickLine={false}
                                        axisLine={{ stroke: 'var(--border)' }}
                                        tick={{ fill: 'var(--muted-foreground)', fontSize: 11 }}
                                        tickFormatter={(m: string, i: number) => monthLabel(m, locale, i === 0 || m.endsWith('-01'))}
                                        interval="preserveStartEnd"
                                        minTickGap={12}
                                    />
                                    <YAxis
                                        width={48}
                                        tickLine={false}
                                        axisLine={false}
                                        allowDecimals={false}
                                        tick={{ fill: 'var(--muted-foreground)', fontSize: 11 }}
                                        tickFormatter={(v: number) => compactNumber(v, locale)}
                                    />
                                    <Tooltip
                                        cursor={{ fill: 'var(--muted)', opacity: 0.6 }}
                                        isAnimationActive={false}
                                        content={({ active, payload }) => {
                                            const bar = active ? (payload[0]?.payload as MonthBar | undefined) : undefined;
                                            if (!bar) {
                                                return null;
                                            }
                                            return (
                                                <div className="igr:rounded-md igr:border igr:bg-popover igr:px-2.5 igr:py-1.5 igr:text-xs igr:shadow-md">
                                                    <p className="igr:font-medium igr:text-foreground">{monthLabel(bar.month, locale, true)}</p>
                                                    <p className="igr:tabular-nums igr:text-muted-foreground">
                                                        {formatMoney(bar.revenue, currency, locale)} · {t('contacts.profile.chart.ordersCount', { count: bar.orders })}
                                                    </p>
                                                </div>
                                            );
                                        }}
                                    />
                                    <Bar dataKey={metric} fill="var(--primary)" maxBarSize={24} radius={[4, 4, 0, 0]} isAnimationActive={false} />
                                </BarChart>
                            </ResponsiveContainer>
                        </div>
                        <table className="igr:sr-only">
                            <caption>{metric === 'revenue' ? t('contacts.profile.chart.titleRevenue') : t('contacts.profile.chart.titleOrders')}</caption>
                            <tbody>
                                {bars.map((b) => (
                                    <tr key={b.month}>
                                        <th scope="row">{monthLabel(b.month, locale, true)}</th>
                                        <td>{format(b[metric])}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </>
                )}
            </CardContent>
        </Card>
    );
}
