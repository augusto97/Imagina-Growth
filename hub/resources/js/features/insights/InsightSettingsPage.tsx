import { RotateCcw } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { ErrorState, PageHeader } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TBody, TD, TH, THead, TR } from '@/components/ui/table';
import { useCan, useWorkspace } from '@/hooks/useWorkspace';
import { formatDateTime } from '@/lib/format';
import { isApiError } from '@/lib/api';
import { toast } from '@/stores/toast';
import { type Assumption, type DetectorInfo, useInsightSettings, useUpdateAssumption } from './api';
import { useInsightFormatters } from './components';

/** Supuestos del impacto (editables) y estado de cada detector de inteligencia.md §5.1. */
export function InsightSettingsPage() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const settings = useInsightSettings(workspace.id);

    return (
        <div className="igr:max-w-4xl">
            <PageHeader title={t('insights.settings.title')} description={t('insights.settings.description')} />
            {settings.isError ? (
                <ErrorState error={settings.error} onRetry={() => void settings.refetch()} />
            ) : settings.isPending ? (
                <Skeleton className="igr:h-80" />
            ) : (
                <div className="igr:space-y-4">
                    <Card>
                        <CardHeader>
                            <CardTitle>{t('insights.settings.assumptions')}</CardTitle>
                            <CardDescription>{t('insights.settings.assumptionsHelp')}</CardDescription>
                        </CardHeader>
                        <CardContent className="igr:p-0">
                            <ul className="igr:divide-y">
                                {settings.data.assumptions.map((a) => (
                                    <AssumptionRow key={a.key} assumption={a} />
                                ))}
                            </ul>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardTitle>{t('insights.settings.detectors')}</CardTitle>
                            <CardDescription>{t('insights.settings.detectorsHelp')}</CardDescription>
                        </CardHeader>
                        <CardContent className="igr:p-0">
                            <Table>
                                <THead>
                                    <TR className="igr:hover:bg-transparent">
                                        <TH className="igr:w-10">#</TH>
                                        <TH>{t('insights.settings.detector')}</TH>
                                        <TH>{t('insights.filters.category')}</TH>
                                        <TH>{t('insights.settings.cadence')}</TH>
                                        <TH>{t('insights.settings.lastRun')}</TH>
                                    </TR>
                                </THead>
                                <TBody>
                                    {settings.data.detectors.map((d) => (
                                        <DetectorRow key={d.key} detector={d} />
                                    ))}
                                </TBody>
                            </Table>
                        </CardContent>
                    </Card>
                </div>
            )}
        </div>
    );
}

function AssumptionRow({ assumption: a }: { assumption: Assumption }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const fmt = useInsightFormatters();
    const canManage = useCan('manageMarketing');
    const update = useUpdateAssumption(workspace.id);
    const [draft, setDraft] = useState(String(Math.round(a.value * 1000) / 10));
    const save = (value: number | null) =>
        update.mutate(
            { key: a.key, value },
            {
                onSuccess: (res) => {
                    const next = res.assumptions.find((x) => x.key === a.key);
                    if (next) setDraft(String(Math.round(next.value * 1000) / 10));
                    toast.success(t('insights.formula.saved', { count: res.updated }));
                },
                onError: (err) => toast.error(isApiError(err) ? err.message : t('errors.generic')),
            },
        );
    const id = `assumption-${a.key}`;
    return (
        <li className="igr:flex igr:flex-wrap igr:items-center igr:gap-3 igr:px-4 igr:py-3">
            <div className="igr:min-w-0 igr:flex-1">
                <label htmlFor={id} className="igr:text-[13px] igr:font-medium">
                    {t(`insights.assumption.${a.key}`)}
                </label>
                <p className="igr:text-xs igr:text-muted-foreground">
                    {t('insights.settings.default', { value: fmt.percent(a.default, 1) })}
                    {a.edited ? (
                        <Badge variant="outline" className="igr:ml-2">
                            {t('insights.settings.edited')}
                        </Badge>
                    ) : null}
                </p>
            </div>
            <form
                className="igr:flex igr:items-center igr:gap-1"
                onSubmit={(e) => {
                    e.preventDefault();
                    const pct = Number(draft.replace(',', '.'));
                    if (Number.isFinite(pct)) save(pct / 100);
                }}
            >
                <Input
                    id={id}
                    inputMode="decimal"
                    value={draft}
                    onChange={(e) => setDraft(e.target.value)}
                    disabled={!canManage}
                    className="igr:h-8 igr:w-20 igr:text-right igr:tabular-nums"
                />
                <span className="igr:text-xs igr:text-muted-foreground">%</span>
                {canManage ? (
                    <>
                        <Button type="submit" size="sm" variant="outline" loading={update.isPending}>
                            {t('insights.formula.save')}
                        </Button>
                        {a.edited ? (
                            <Button type="button" size="icon" variant="ghost" onClick={() => save(null)} aria-label={t('insights.formula.reset')}>
                                <RotateCcw />
                            </Button>
                        ) : null}
                    </>
                ) : null}
            </form>
        </li>
    );
}

function DetectorRow({ detector: d }: { detector: DetectorInfo }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const run = d.last_run;
    const status = !d.enabled
        ? t('insights.settings.comingPhase', { phase: d.phase })
        : run === null
          ? t('insights.settings.notYet')
          : t(`insights.settings.run.${run.status}`, { count: run.found ?? 0 });
    return (
        <TR>
            <TD className="igr:tabular-nums igr:text-muted-foreground">{d.number}</TD>
            <TD className="igr:font-medium">{t(`insights.detector.${d.key}`, { defaultValue: d.key })}</TD>
            <TD>{t(`insights.category.${d.category}`)}</TD>
            <TD>{t(`insights.settings.cadences.${d.cadence}`)}</TD>
            <TD>
                <span className={!d.enabled || run?.status === 'skipped' ? 'igr:text-muted-foreground' : run?.status === 'failed' ? 'igr:font-medium' : undefined}>{status}</span>
                {run?.status === 'skipped' && run.reason ? <span className="igr:block igr:text-xs igr:text-muted-foreground">{run.reason}</span> : null}
                {run?.at ? <span className="igr:block igr:text-xs igr:text-muted-foreground">{formatDateTime(run.at, workspace.locale, workspace.timezone)}</span> : null}
            </TD>
        </TR>
    );
}
