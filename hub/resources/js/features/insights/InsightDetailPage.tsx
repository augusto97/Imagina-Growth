import { ArrowLeft, Check, EyeOff, RotateCcw } from 'lucide-react';
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useParams } from 'react-router';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { ErrorState } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TBody, TD, TH, THead, TR } from '@/components/ui/table';
import { useCan, useWorkspace } from '@/hooks/useWorkspace';
import { formatDate, formatDateTime, formatMoney, formatNumber, formatPercent } from '@/lib/format';
import { toast } from '@/stores/toast';
import { useInsight, useUpdateInsight } from './api';
import { ImpactFormula, InsightActionButton } from './components';
import { SeverityBadge } from './severity';

/** Formato de una cifra de los hechos según su nombre (las cifras salen calculadas del detector). */
function useFactFormatter() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const money = /(revenue|aov|amount|impact|total|price|value_ws)/;
    const ratio = /(rate|share|conversion|drop|difference|assumption)/;
    const format = (key: string, value: unknown): ReactNode => {
        if (value === null || value === undefined || value === '') {
            return '—';
        }
        if (typeof value === 'boolean') {
            return value ? t('insights.yes') : t('insights.no');
        }
        if (typeof value === 'number') {
            if (money.test(key)) return formatMoney(value, workspace.currency, workspace.locale);
            if (ratio.test(key)) return formatPercent(value, workspace.locale, 1);
            return formatNumber(Math.round(value * 100) / 100, workspace.locale);
        }
        if (typeof value === 'string') {
            return /^\d{4}-\d{2}-\d{2}$/.test(value) ? formatDate(value, workspace.locale, 'UTC') : value;
        }
        if (Array.isArray(value)) {
            const rows = value.filter((v): v is Record<string, unknown> => typeof v === 'object' && v !== null);
            if (rows.length === 0) return value.map(String).join(', ');
            const cols = Object.keys(rows[0] ?? {}).filter((c) => !c.endsWith('_id'));
            return (
                <Table>
                    <THead>
                        <TR className="igr:hover:bg-transparent">
                            {cols.map((c) => (
                                <TH key={c}>{t(`insights.facts.${c}`, { defaultValue: c })}</TH>
                            ))}
                        </TR>
                    </THead>
                    <TBody>
                        {rows.map((r, i) => (
                            <TR key={i}>
                                {cols.map((c) => (
                                    <TD key={c} className="igr:tabular-nums">
                                        {format(c, r[c])}
                                    </TD>
                                ))}
                            </TR>
                        ))}
                    </TBody>
                </Table>
            );
        }
        if (typeof value === 'object') {
            return <FactList facts={value as Record<string, unknown>} format={format} />;
        }
        return '—';
    };
    return format;
}

function FactList({ facts, format }: { facts: Record<string, unknown>; format: (key: string, value: unknown) => ReactNode }) {
    const { t } = useTranslation();
    const entries = Object.entries(facts).filter(([k]) => !k.endsWith('_id'));
    return (
        <dl className="igr:grid igr:gap-x-4 igr:gap-y-2 igr:text-[13px] igr:sm:grid-cols-[180px_1fr]">
            {entries.map(([key, value]) => (
                <div key={key} className="igr:contents">
                    <dt className="igr:text-muted-foreground">{t(`insights.facts.${key}`, { defaultValue: key })}</dt>
                    <dd className="igr:min-w-0 igr:tabular-nums">{format(key, value)}</dd>
                </div>
            ))}
        </dl>
    );
}

export function InsightDetailPage() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const { insightId = '' } = useParams();
    const insight = useInsight(workspace.id, insightId);
    const update = useUpdateInsight(workspace.id);
    const canManage = useCan('manageMarketing');
    const format = useFactFormatter();
    const base = `/w/${workspace.id}`;

    const setStatus = (status: 'dismissed' | 'actioned' | 'seen') =>
        update.mutate(
            { id: insightId, status },
            {
                onSuccess: () => toast.success(t(`insights.toast.${status}`)),
                onError: () => toast.error(t('errors.generic')),
            },
        );

    if (insight.isError) {
        return <ErrorState error={insight.error} onRetry={() => void insight.refetch()} />;
    }
    if (insight.isPending) {
        return (
            <div className="igr:space-y-3">
                <Skeleton className="igr:h-8 igr:w-2/3" />
                <Skeleton className="igr:h-40" />
                <Skeleton className="igr:h-56" />
            </div>
        );
    }

    const i = insight.data;
    const open = i.status === 'new' || i.status === 'seen';
    const action = i.action;

    return (
        <div className="igr:max-w-4xl">
            <Button asChild variant="ghost" size="sm" className="igr:mb-3 igr:-ml-2">
                <Link to={`${base}/hallazgos`}>
                    <ArrowLeft />
                    {t('insights.back')}
                </Link>
            </Button>
            <div className="igr:flex igr:flex-wrap igr:items-center igr:gap-2">
                <SeverityBadge severity={i.severity} />
                <Badge variant="outline">{t(`insights.status.${i.status}`)}</Badge>
                <span className="igr:text-xs igr:text-muted-foreground">
                    {i.number ? `#${i.number} · ` : ''}
                    {t(`insights.category.${i.category}`)} · {t(`insights.detector.${i.detector}`, { defaultValue: i.detector })}
                </span>
            </div>
            <h1 className="igr:mt-2 igr:text-xl igr:font-semibold igr:tracking-tight">{i.title}</h1>
            <p className="igr:mt-1 igr:text-xs igr:text-muted-foreground">
                {i.period_start && i.period_end ? `${t('insights.period', { from: formatDate(i.period_start, workspace.locale, 'UTC'), to: formatDate(i.period_end, workspace.locale, 'UTC') })} · ` : ''}
                {t('insights.detectedAt', { date: formatDateTime(i.created_at, workspace.locale, workspace.timezone) })}
            </p>

            <div className="igr:mt-5 igr:grid igr:gap-4 igr:md:grid-cols-[1fr_340px]">
                <Card>
                    <CardHeader>
                        <CardTitle>{t('insights.whatToDo')}</CardTitle>
                    </CardHeader>
                    <CardContent className="igr:space-y-3">
                        {action ? (
                            <>
                                <p className="igr:text-[13px]">{action.label}</p>
                                {open || !action.kind || action.kind === 'link' ? <InsightActionButton insight={i} /> : null}
                                {open && (action.kind === 'activate_flow' || action.kind === 'create_campaign') ? (
                                    <p className="igr:text-xs igr:text-muted-foreground">{t(`insights.oneClick.${action.kind}`)}</p>
                                ) : null}
                            </>
                        ) : (
                            <p className="igr:text-[13px] igr:text-muted-foreground">{t('insights.noAction')}</p>
                        )}
                        {canManage ? (
                            <div className="igr:flex igr:flex-wrap igr:gap-2 igr:border-t igr:pt-3">
                                {open ? (
                                    <>
                                        <Button size="sm" variant="outline" loading={update.isPending && update.variables.status === 'actioned'} onClick={() => setStatus('actioned')}>
                                            <Check />
                                            {t('insights.markActioned')}
                                        </Button>
                                        <Button size="sm" variant="ghost" loading={update.isPending && update.variables.status === 'dismissed'} onClick={() => setStatus('dismissed')}>
                                            <EyeOff />
                                            {t('insights.dismiss')}
                                        </Button>
                                    </>
                                ) : i.status !== 'expired' ? (
                                    <Button size="sm" variant="outline" loading={update.isPending} onClick={() => setStatus('seen')}>
                                        <RotateCcw />
                                        {t('insights.reopen')}
                                    </Button>
                                ) : null}
                            </div>
                        ) : null}
                        {canManage && open ? <p className="igr:text-xs igr:text-muted-foreground">{t('insights.dismissHelp')}</p> : null}
                    </CardContent>
                </Card>
                <Card>
                    <CardHeader>
                        <CardTitle>{t('insights.impact')}</CardTitle>
                        <CardDescription>{t('insights.impactHelp')}</CardDescription>
                    </CardHeader>
                    <CardContent className="igr:space-y-3">
                        <p className="igr:text-2xl igr:font-semibold igr:tabular-nums">{i.impact === null ? '—' : formatMoney(i.impact, workspace.currency, workspace.locale)}</p>
                        {i.formula ? <ImpactFormula insight={i} /> : null}
                        {i.assumptions.length > 0 ? (
                            <ul className="igr:space-y-1 igr:text-xs igr:text-muted-foreground">
                                {i.assumptions.map((a, k) => (
                                    <li key={k}>{a}</li>
                                ))}
                            </ul>
                        ) : null}
                        {i.formula?.terms.some((term) => term.assumption) ? <p className="igr:text-xs igr:text-muted-foreground">{t('insights.formula.help')}</p> : null}
                    </CardContent>
                </Card>
            </div>

            <Card className="igr:mt-4">
                <CardHeader>
                    <CardTitle>{t('insights.factsTitle')}</CardTitle>
                    <CardDescription>{t('insights.factsHelp')}</CardDescription>
                </CardHeader>
                <CardContent>
                    <FactList facts={i.facts} format={format} />
                </CardContent>
            </Card>
        </div>
    );
}
