import { ArrowRight, CircleDollarSign } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { formatPercent } from '@/lib/format';
import type { AttributedRevenue } from '../api';

/**
 * «Todo módulo muestra el dinero que generó» (CLAUDE.md §3.7): lo que vendieron los flujos, campañas, la bandeja, el
 * agente, los widgets y los referidos en el periodo, con la misma regla que el reporte mensual de la agencia.
 */
export function MoneyGeneratedCard({ data, money, base }: { data: AttributedRevenue | undefined; money: (v: number) => string; base: string }) {
    const { t, i18n } = useTranslation();

    return (
        <Card>
            <CardHeader>
                <CardTitle className="igr:flex igr:items-center igr:gap-1.5">
                    <CircleDollarSign className="igr:size-4 igr:text-muted-foreground" />
                    {t('home.attributedTitle')}
                </CardTitle>
            </CardHeader>
            <CardContent>
                {!data ? (
                    <Skeleton className="igr:h-16" />
                ) : (
                    <>
                        <p className="igr:text-2xl igr:font-semibold igr:tabular-nums">{money(data.revenue)}</p>
                        {data.orders > 0 ? (
                            <>
                                <p className="igr:mt-1 igr:text-xs igr:text-muted-foreground">
                                    {t('home.attributedSummary', { count: data.orders, share: formatPercent(data.share_pct / 100, i18n.language, 1) })}
                                </p>
                                <ul className="igr:mt-3 igr:space-y-1 igr:text-[13px]">
                                    {data.modules.map((m) => (
                                        <li key={m.key} className="igr:flex igr:justify-between igr:gap-2">
                                            <span className="igr:text-muted-foreground">{t(`agency.modules.${m.key}`)}</span>
                                            <span className="igr:tabular-nums">{money(m.revenue)}</span>
                                        </li>
                                    ))}
                                </ul>
                            </>
                        ) : (
                            <>
                                <p className="igr:mt-1 igr:text-xs igr:text-muted-foreground">{t('home.attributedEmpty')}</p>
                                <Button asChild size="sm" variant="outline" className="igr:mt-3">
                                    <Link to={`${base}/flujos`}>
                                        {t('home.attributedStart')}
                                        <ArrowRight className="igr:size-3.5" />
                                    </Link>
                                </Button>
                            </>
                        )}
                    </>
                )}
            </CardContent>
        </Card>
    );
}
