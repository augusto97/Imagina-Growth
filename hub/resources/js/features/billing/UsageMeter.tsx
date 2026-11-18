import { CircleCheck, OctagonAlert, TriangleAlert } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { cn } from '@/lib/cn';
import { formatMoney, formatNumber } from '@/lib/format';
import type { Meter, MeterState } from './api';
import { meterWidth } from './model';

/*
 * Medidor de uso contra el límite del plan. El estado (80 % / 100 %) va con ícono
 * y texto, nunca solo con color; las cifras usan los colores de texto.
 */

export function formatMeterValue(metric: string, value: number, locale: string): string {
    return metric === 'ai_cost_usd' ? formatMoney(value, 'USD', locale) : formatNumber(value, locale);
}

export function MeterStatus({ state, className, label }: { state: MeterState; className?: string; label?: string }) {
    const { t } = useTranslation();
    const Icon = state === 'critical' ? OctagonAlert : state === 'warning' ? TriangleAlert : CircleCheck;
    const tone = state === 'critical' ? 'igr:text-destructive' : state === 'warning' ? 'igr:text-warning' : 'igr:text-muted-foreground';
    return (
        <span className={cn('igr:inline-flex igr:items-center igr:gap-1 igr:text-xs igr:text-foreground', className)}>
            <Icon aria-hidden className={cn('igr:size-3.5', tone)} />
            {label ?? t(`billing.meter.${state}`)}
        </span>
    );
}

export function UsageMeter({ meter, locale }: { meter: Meter; locale: string }) {
    const { t } = useTranslation();
    const width = meterWidth(meter);
    const fill = meter.state === 'critical' ? 'igr:bg-destructive' : meter.state === 'warning' ? 'igr:bg-warning' : 'igr:bg-primary';
    const label = t(`billing.metric.${meter.metric}`);
    const of = t('billing.meter.of', {
        value: formatMeterValue(meter.metric, meter.value, locale),
        limit: formatMeterValue(meter.metric, meter.limit ?? 0, locale),
    });

    return (
        <div className="igr:space-y-1.5" data-testid={`meter-${meter.metric}`}>
            <div className="igr:flex igr:items-baseline igr:justify-between igr:gap-3 igr:text-sm">
                <span className="igr:font-medium">{label}</span>
                <span className="igr:tabular-nums igr:text-muted-foreground">
                    {of}
                    {meter.pct !== null ? <span className="igr:ml-1.5 igr:text-foreground">{t('billing.meter.pct', { pct: meter.pct })}</span> : null}
                </span>
            </div>
            <div
                role="meter"
                aria-label={label}
                aria-valuemin={0}
                aria-valuemax={meter.limit ?? 0}
                aria-valuenow={meter.value}
                aria-valuetext={`${of} · ${t(`billing.meter.${meter.state}`)}`}
                className="igr:relative igr:h-2 igr:w-full igr:overflow-hidden igr:rounded-full igr:bg-muted"
            >
                <div className={cn('igr:h-full igr:rounded-full igr:transition-[width] igr:duration-500', fill)} style={{ width: `${Math.round(width * 1000) / 10}%` }} />
                {/* Marca del aviso al 80 %. */}
                <span aria-hidden className="igr:absolute igr:inset-y-0 igr:left-[80%] igr:w-0.5 igr:bg-background" />
            </div>
            <MeterStatus state={meter.state} />
        </div>
    );
}
