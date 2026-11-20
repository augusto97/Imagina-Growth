import { ExternalLink, RotateCcw } from 'lucide-react';
import { useState, type SyntheticEvent } from 'react';
import { useTranslation } from 'react-i18next';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ErrorState } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TBody, TD, TH, THead, TR } from '@/components/ui/table';
import { useWorkspace } from '@/hooks/useWorkspace';
import { isApiError } from '@/lib/api';
import { toast } from '@/stores/toast';
import { type WaCategory, type WaRatesData, useSaveWaRates, useSeedWaRates, useWaRates } from './settings-api';
import { parseWaRate } from './settings-model';

/** Plataforma → Ajustes → Tarifas de WhatsApp (F17): la tabla de Meta por mercado y tipo de mensaje, editable con su fecha. */
export function WaRatesPanel() {
    const rates = useWaRates();
    if (rates.isError) return <ErrorState error={rates.error} onRetry={() => void rates.refetch()} />;
    if (rates.isPending) return <Skeleton className="igr:h-96" />;
    return <WaRatesForm key={`${rates.data.effective_from ?? ''}-${rates.dataUpdatedAt}`} data={rates.data} />;
}

function WaRatesForm({ data }: { data: WaRatesData }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const save = useSaveWaRates();
    const seed = useSeedWaRates();
    const fmt = (n: number | null) => (n === null ? '' : new Intl.NumberFormat(workspace.locale, { maximumFractionDigits: 5, useGrouping: false }).format(n));
    const [values, setValues] = useState<Record<string, Record<string, string>>>(() =>
        Object.fromEntries(data.markets.map((m) => [m, Object.fromEntries(data.categories.map((c) => [c, fmt(data.rates[m]?.[c] ?? null)]))])),
    );
    const [from, setFrom] = useState(new Date().toISOString().slice(0, 10));
    const [error, setError] = useState<string | null>(null);
    const day = (ymd: string | null) =>
        ymd ? new Intl.DateTimeFormat(workspace.locale, { timeZone: 'UTC', day: 'numeric', month: 'long', year: 'numeric' }).format(new Date(`${ymd}T12:00:00Z`)) : '—';

    const submit = (e: SyntheticEvent) => {
        e.preventDefault();
        const payload: Record<string, Record<WaCategory, number>> = {};
        for (const market of data.markets) {
            const row = {} as Record<WaCategory, number>;
            for (const category of data.categories) {
                const n = parseWaRate(values[market]?.[category] ?? '');
                if (n === null) {
                    setError(t('platformSettings.waRates.invalid'));
                    return;
                }
                row[category] = n;
            }
            payload[market] = row;
        }
        setError(null);
        save.mutate(
            { effective_from: from, rates: payload },
            {
                onSuccess: () => toast.success(t('platformSettings.waRates.saved')),
                onError: (err) => setError(isApiError(err) ? err.message : t('errors.title')),
            },
        );
    };

    return (
        <form onSubmit={submit} noValidate>
            <Card>
                <CardHeader>
                    <CardTitle className="igr:flex igr:flex-wrap igr:items-center igr:gap-2">
                        {t('platformSettings.sections.wa_rates.title')}
                        {data.stale ? <Badge variant="warning">{t('platformSettings.waRates.staleBadge')}</Badge> : null}
                    </CardTitle>
                    <CardDescription>{t('platformSettings.sections.wa_rates.description')}</CardDescription>
                </CardHeader>
                <CardContent className="igr:space-y-4">
                    <div className="igr:space-y-1 igr:text-[13px]">
                        <p>{t('platformSettings.waRates.effective', { date: day(data.effective_from) })}</p>
                        {data.stale ? <p className="igr:text-warning">{t('platformSettings.waRates.stale', { days: data.stale_after_days })}</p> : null}
                        <a
                            href={data.source_url}
                            target="_blank"
                            rel="noreferrer"
                            className="igr:inline-flex igr:items-center igr:gap-1 igr:text-xs igr:text-primary igr:underline-offset-4 igr:hover:underline"
                        >
                            {t('platformSettings.waRates.source')}
                            <ExternalLink className="igr:size-3" />
                        </a>
                    </div>
                    <div className="igr:overflow-x-auto">
                        <Table>
                            <THead>
                                <TR>
                                    <TH>{t('platformSettings.waRates.market')}</TH>
                                    {data.categories.map((c) => (
                                        <TH key={c} className="igr:min-w-28">
                                            {t(`platformSettings.waRates.categories.${c}`)}
                                        </TH>
                                    ))}
                                </TR>
                            </THead>
                            <TBody>
                                {data.markets.map((m) => (
                                    <TR key={m}>
                                        <TD className="igr:min-w-40 igr:font-medium">{t(`platformSettings.waRates.markets.${m}`, { defaultValue: m })}</TD>
                                        {data.categories.map((c) => (
                                            <TD key={c}>
                                                <Input
                                                    aria-label={`${t(`platformSettings.waRates.markets.${m}`, { defaultValue: m })} · ${t(`platformSettings.waRates.categories.${c}`)}`}
                                                    inputMode="decimal"
                                                    className="igr:w-24 igr:tabular-nums"
                                                    value={values[m]?.[c] ?? ''}
                                                    onChange={(e) => setValues((v) => ({ ...v, [m]: { ...(v[m] ?? {}), [c]: e.target.value } }))}
                                                />
                                            </TD>
                                        ))}
                                    </TR>
                                ))}
                            </TBody>
                        </Table>
                    </div>
                    <p className="igr:text-xs igr:text-muted-foreground">{t('platformSettings.waRates.unit')}</p>
                    <div className="igr:max-w-xs igr:space-y-1.5">
                        <Label htmlFor="wa-from">{t('platformSettings.waRates.from')}</Label>
                        <Input id="wa-from" type="date" required value={from} onChange={(e) => setFrom(e.target.value)} />
                        <p className="igr:text-xs igr:text-muted-foreground">{t('platformSettings.waRates.fromHelp')}</p>
                    </div>
                    {error ? <p className="igr:text-sm igr:text-destructive">{error}</p> : null}
                </CardContent>
                <CardFooter className="igr:flex-wrap igr:justify-between">
                    <Button
                        type="button"
                        variant="ghost"
                        loading={seed.isPending}
                        title={t('platformSettings.waRates.seedHelp', { date: day(data.bundled_from) })}
                        onClick={() =>
                            seed.mutate(undefined, {
                                onSuccess: () => toast.success(t('platformSettings.waRates.seeded')),
                                onError: (e) => toast.error(t('errors.title'), isApiError(e) ? e.message : undefined),
                            })
                        }
                    >
                        <RotateCcw />
                        {t('platformSettings.waRates.seed')}
                    </Button>
                    <Button type="submit" loading={save.isPending}>
                        {t('platformSettings.waRates.save')}
                    </Button>
                </CardFooter>
            </Card>
        </form>
    );
}
