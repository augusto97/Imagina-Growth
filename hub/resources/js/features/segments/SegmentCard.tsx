import { ArrowDownRight, ArrowUpRight, Mail, Megaphone, MessageCircle, Minus, Workflow } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { IconTile } from '@/components/ui/icon-tile';
import { Sparkline } from '@/components/ui/stat-card';
import { useWorkspace } from '@/hooks/useWorkspace';
import { cn } from '@/lib/cn';
import { formatMoney, formatNumber } from '@/lib/format';
import { explain, lookOf, trendTone } from './look';
import type { SegmentSummary } from './types';

/** Un grupo dinámico que todavía no se calcula: sus cifras aún no existen (nada de ceros que parecen datos). */
export const computing = (s: Pick<SegmentSummary, 'last_computed_at' | 'kind'>) => s.last_computed_at === null && s.kind === 'dynamic';

/**
 * Tarjeta de un grupo de clientes: qué es en palabras (y, en los RFM, qué hacer), su tamaño con la tendencia de 30 días,
 * a cuántos se les puede escribir por WhatsApp y por correo, su valor (con tienda) y las acciones directas. Toda la
 * tarjeta abre el grupo; los botones van por encima del enlace.
 */
export function SegmentCard({
    segment,
    showValue,
    onUse,
}: {
    segment: SegmentSummary;
    showValue: boolean;
    /** null: el rol no puede crear campañas ni automatizaciones. */
    onUse: ((mode: 'campaign' | 'flow') => void) | null;
}) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const look = lookOf(segment);
    const { what, todo } = explain(t, segment);
    const pending = computing(segment);
    const tone = trendTone(segment.trend?.change);
    const Arrow = tone === 'up' ? ArrowUpRight : tone === 'down' ? ArrowDownRight : Minus;

    return (
        <Card className="igr:relative igr:flex igr:flex-col igr:p-4 igr:transition-shadow igr:focus-within:shadow-md igr:hover:shadow-md">
            <div className="igr:flex igr:items-start igr:gap-3">
                <IconTile icon={look.icon} tone={look.tone} />
                <div className="igr:min-w-0 igr:flex-1">
                    <Link
                        to={`/w/${workspace.id}/segmentos/${segment.id}`}
                        className="igr:font-semibold igr:tracking-tight igr:text-foreground igr:outline-none igr:after:absolute igr:after:inset-0 igr:after:rounded-xl igr:focus-visible:after:ring-3 igr:focus-visible:after:ring-ring/30"
                    >
                        {segment.name}
                    </Link>
                    {what ? <p className="igr:mt-0.5 igr:line-clamp-2 igr:text-support igr:text-muted-foreground">{what}</p> : null}
                </div>
                {segment.kind === 'static' ? <Badge variant="outline">{t('segments.badges.static')}</Badge> : null}
            </div>

            <div className="igr:mt-4 igr:flex igr:items-end igr:justify-between igr:gap-3">
                <div className="igr:min-w-0">
                    {pending ? (
                        <p className="igr:text-support igr:text-muted-foreground">{t('segments.computing')}</p>
                    ) : (
                        <>
                            <p className="igr:text-figure igr:leading-none igr:font-semibold igr:tabular-nums">{formatNumber(segment.member_count, workspace.locale)}</p>
                            <p className="igr:mt-1.5 igr:flex igr:items-center igr:gap-1.5 igr:text-caption igr:text-muted-foreground">
                                {t('segments.list.people', { count: segment.member_count })}
                                {tone !== null ? (
                                    <span
                                        className={cn(
                                            'igr:inline-flex igr:items-center igr:gap-0.5 igr:font-medium igr:tabular-nums',
                                            tone === 'up' && 'igr:text-success',
                                            tone === 'down' && 'igr:text-destructive',
                                        )}
                                    >
                                        <Arrow className="igr:size-3.5" aria-hidden />
                                        {t(tone === 'flat' ? 'segments.list.trendFlat' : 'segments.list.trend', {
                                            change: `${(segment.trend?.change ?? 0) > 0 ? '+' : '−'}${formatNumber(Math.abs(segment.trend?.change ?? 0), workspace.locale)}`,
                                            days: segment.trend?.days ?? 30,
                                        })}
                                    </span>
                                ) : null}
                            </p>
                        </>
                    )}
                </div>
                {!pending && (segment.trend?.points.length ?? 0) > 1 ? <Sparkline values={segment.trend?.points ?? []} className="igr:h-8 igr:w-24" /> : null}
            </div>

            {!pending ? (
                <p className="igr:mt-3 igr:flex igr:flex-wrap igr:items-center igr:gap-x-3 igr:gap-y-1 igr:text-caption igr:text-muted-foreground">
                    <span className="igr:inline-flex igr:items-center igr:gap-1" title={t('segments.list.reachWhatsapp')}>
                        <MessageCircle className="igr:size-3.5" aria-hidden />
                        <span className="igr:sr-only">{t('segments.list.reachWhatsapp')}:</span>
                        <span className="igr:tabular-nums">{formatNumber(segment.channel_counts.whatsapp ?? 0, workspace.locale)}</span>
                    </span>
                    <span className="igr:inline-flex igr:items-center igr:gap-1" title={t('segments.list.reachEmail')}>
                        <Mail className="igr:size-3.5" aria-hidden />
                        <span className="igr:sr-only">{t('segments.list.reachEmail')}:</span>
                        <span className="igr:tabular-nums">{formatNumber(segment.channel_counts.email ?? 0, workspace.locale)}</span>
                    </span>
                    {showValue ? (
                        <span className="igr:tabular-nums">{t('segments.list.value', { value: formatMoney(segment.revenue_ws, workspace.currency, workspace.locale) })}</span>
                    ) : null}
                </p>
            ) : null}

            {todo ? <p className="igr:mt-3 igr:rounded-lg igr:bg-muted/60 igr:px-3 igr:py-2 igr:text-caption igr:text-muted-foreground">{t('segments.list.todo', { action: todo })}</p> : null}

            {onUse ? (
                <div className="igr:relative igr:z-10 igr:mt-auto igr:flex igr:flex-wrap igr:gap-2 igr:pt-4">
                    <Button size="sm" variant="outline" onClick={() => onUse('campaign')} disabled={pending || segment.member_count === 0}>
                        <Megaphone />
                        {t('segments.list.sendCampaign')}
                    </Button>
                    <Button size="sm" variant="ghost" onClick={() => onUse('flow')}>
                        <Workflow />
                        {t('segments.list.automate')}
                    </Button>
                </div>
            ) : null}
        </Card>
    );
}
