import { CircleCheck, CircleDashed, Info } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Select } from '@/components/ui/input';
import { formatMoney, formatNumber, formatPercent } from '@/lib/format';
import { useWorkspace } from '@/hooks/useWorkspace';
import type { FlowAnalytics } from '../types';

/** Resultados del flujo en fichas (sin gráfico): entraron, convirtieron, ingresos e incremento frente al control. */
export function ResultsBar({ data, days, onDays, loading }: { data: FlowAnalytics | undefined; days: number; onDays: (d: number) => void; loading: boolean }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const s = data?.summary;
    const u = data?.uplift;
    const pct = (v: number | undefined | null) => (v === undefined || v === null ? '—' : formatPercent(v, workspace.locale, 1));
    const points = (v: number | undefined) => (v === undefined ? '—' : `${v >= 0 ? '+' : ''}${(v * 100).toFixed(1).replace('.', ',')}`);

    return (
        <div className="igr:flex igr:flex-wrap igr:items-stretch igr:gap-2 igr:border-b igr:bg-card igr:px-3 igr:py-2" aria-busy={loading}>
            <Select aria-label={t('flows.analytics.period')} className="igr:w-40 igr:self-center" value={days} onChange={(e) => onDays(Number(e.target.value))}>
                {[7, 30, 90].map((d) => (
                    <option key={d} value={d}>
                        {t('flows.analytics.days', { count: d })}
                    </option>
                ))}
                <option value={0}>{t('flows.analytics.all')}</option>
            </Select>
            <Tile label={t('flows.analytics.entered')} value={s ? formatNumber(s.entered, workspace.locale) : '—'} />
            <Tile label={t('flows.analytics.live')} value={s ? formatNumber(s.live, workspace.locale) : '—'} />
            <Tile label={t('flows.analytics.converted')} value={s ? formatNumber(s.converted, workspace.locale) : '—'} sub={s ? `${t('flows.analytics.rate')}: ${pct(s.conversion_rate)}` : undefined} />
            <Tile label={t('flows.analytics.goalRevenue')} value={s ? formatMoney(s.goal_revenue, workspace.currency, workspace.locale) : '—'} />
            <Tile label={t('flows.analytics.attributedRevenue')} value={s ? formatMoney(s.attributed_revenue, workspace.currency, workspace.locale) : '—'} />
            <div className="igr:min-w-56 igr:flex-1 igr:rounded-md igr:border igr:px-3 igr:py-1.5">
                <div className="igr:text-[11px] igr:text-muted-foreground">{t('flows.analytics.uplift')}</div>
                {u?.available ? (
                    <div className="igr:text-[13px]">
                        <span className="igr:font-semibold igr:tabular-nums">{t('flows.analytics.upliftValue', { diff: points(u.difference), low: points(u.ci_low), high: points(u.ci_high) })}</span>
                        <span className="igr:ml-2 igr:inline-flex igr:items-center igr:gap-1 igr:text-xs igr:text-muted-foreground">
                            {u.significant ? <CircleCheck className="igr:size-3.5 igr:text-success" aria-hidden /> : <CircleDashed className="igr:size-3.5" aria-hidden />}
                            {u.significant ? t('flows.analytics.significant') : t('flows.analytics.notSignificant')}
                        </span>
                    </div>
                ) : (
                    <p className="igr:flex igr:items-start igr:gap-1 igr:text-xs igr:text-muted-foreground">
                        <Info className="igr:mt-px igr:size-3.5 igr:shrink-0" aria-hidden />
                        {u?.reason === 'small_sample' ? t('flows.analytics.smallSample', { treatment: u.treatment_n ?? 0, holdout: u.holdout_n ?? 0 }) : t('flows.analytics.noHoldout')}
                    </p>
                )}
            </div>
        </div>
    );
}

function Tile({ label, value, sub }: { label: string; value: string; sub?: string | undefined }) {
    return (
        <div className="igr:min-w-28 igr:rounded-md igr:border igr:px-3 igr:py-1.5">
            <div className="igr:text-[11px] igr:text-muted-foreground">{label}</div>
            <div className="igr:text-[15px] igr:font-semibold igr:tabular-nums">{value}</div>
            {sub ? <div className="igr:text-[11px] igr:text-muted-foreground">{sub}</div> : null}
        </div>
    );
}
