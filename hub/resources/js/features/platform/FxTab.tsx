import { Pencil, RefreshCw, Undo2 } from 'lucide-react';
import { useState, type SyntheticEvent } from 'react';
import { useTranslation } from 'react-i18next';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ErrorState } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TBody, TD, TH, THead, TR } from '@/components/ui/table';
import { useWorkspace } from '@/hooks/useWorkspace';
import { isApiError } from '@/lib/api';
import { formatDateTime } from '@/lib/format';
import { toast } from '@/stores/toast';
import { type FxCurrency, parseRate, usePlatformFx, useRefreshFx, useRemoveFxOverride, useSetFxOverride } from './fx-api';

/**
 * Plataforma → Tasas de cambio (F11): con qué tasa se pasa a la moneda de cada espacio de trabajo el costo estimado
 * de los mensajes. Se actualizan solas cada día; el superadmin puede fijar una a mano (p. ej. la oficial del BCV).
 */
export function FxTab() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const fx = usePlatformFx();
    const refresh = useRefreshFx();
    const remove = useRemoveFxOverride();
    const [editing, setEditing] = useState<FxCurrency | null>(null);

    if (fx.isError) return <ErrorState error={fx.error} onRetry={() => void fx.refetch()} />;
    if (fx.isPending) return <Skeleton className="igr:h-64" />;
    const data = fx.data;
    const locale = workspace.locale;
    const rate = (v: number | null | undefined) => (v === null || v === undefined ? '—' : new Intl.NumberFormat(locale, { maximumFractionDigits: v >= 100 ? 2 : 4 }).format(v));
    const day = (ymd: string | null | undefined) =>
        ymd ? new Intl.DateTimeFormat(locale, { timeZone: 'UTC', day: 'numeric', month: 'short', year: 'numeric' }).format(new Date(`${ymd.slice(0, 10)}T12:00:00Z`)) : '—';
    const last = data.last_update;

    return (
        <div className="igr:space-y-4">
            <Card>
                <CardHeader className="igr:flex igr:flex-row igr:items-start igr:justify-between igr:gap-3">
                    <div className="igr:space-y-1.5">
                        <CardTitle>{t('platform.fx.title')}</CardTitle>
                        <CardDescription>{t('platform.fx.description', { days: data.stale_after_days })}</CardDescription>
                    </div>
                    <Button
                        variant="outline"
                        size="sm"
                        disabled={refresh.isPending}
                        onClick={() =>
                            refresh.mutate(undefined, {
                                onSuccess: () => toast.success(t('platform.fx.refreshed')),
                                onError: (e) => toast.error(t('platform.fx.refreshFailed'), isApiError(e) ? e.message : undefined),
                            })
                        }
                    >
                        <RefreshCw className="igr:size-4" />
                        {t('platform.fx.refresh')}
                    </Button>
                </CardHeader>
                <CardContent className="igr:space-y-1 igr:text-sm">
                    {last === null ? (
                        <p className="igr:text-muted-foreground">{t('platform.fx.neverUpdated')}</p>
                    ) : last.ok ? (
                        <p className="igr:text-muted-foreground">{t('platform.fx.lastOk', { at: formatDateTime(last.at, locale), date: day(last.date) })}</p>
                    ) : (
                        <p className="igr:text-destructive">{t('platform.fx.lastError', { at: formatDateTime(last.at, locale), error: last.error ?? '' })}</p>
                    )}
                    <p className="igr:text-xs igr:text-muted-foreground">
                        {t('platform.fx.source')}{' '}
                        <a href={data.attribution_url} target="_blank" rel="noreferrer" className="igr:underline igr:underline-offset-2">
                            {t('analytics.cost.attribution')}
                        </a>
                    </p>
                </CardContent>
            </Card>
            <Card>
                <CardContent className="igr:overflow-x-auto igr:pt-4">
                    <Table>
                        <THead>
                            <TR>
                                <TH>{t('platform.fx.currency')}</TH>
                                <TH className="igr:text-right">{t('platform.fx.current')}</TH>
                                <TH>{t('platform.fx.from')}</TH>
                                <TH className="igr:text-right">{t('platform.fx.auto')}</TH>
                                <TH>
                                    <span className="igr:sr-only">{t('platform.accounts.actions')}</span>
                                </TH>
                            </TR>
                        </THead>
                        <TBody>
                            {data.currencies.map((c) => (
                                <TR key={c.currency}>
                                    <TD className="igr:font-medium">
                                        {c.currency}
                                        {c.manual ? (
                                            <Badge variant="secondary" className="igr:ml-2">
                                                {t('platform.fx.manual')}
                                            </Badge>
                                        ) : null}
                                        {c.stale ? (
                                            <Badge variant="warning" className="igr:ml-2">
                                                {t('platform.fx.stale')}
                                            </Badge>
                                        ) : null}
                                        {c.override?.note ? <span className="igr:block igr:text-xs igr:text-muted-foreground">{c.override.note}</span> : null}
                                    </TD>
                                    <TD className="igr:text-right igr:tabular-nums">
                                        {c.rate === null ? t('platform.fx.none') : t('platform.fx.perUsd', { rate: rate(c.rate), currency: c.currency })}
                                    </TD>
                                    <TD>{day(c.date)}</TD>
                                    <TD className="igr:text-right igr:tabular-nums igr:text-muted-foreground">{c.auto ? `${rate(c.auto.rate)} · ${day(c.auto.date)}` : '—'}</TD>
                                    <TD className="igr:text-right">
                                        <div className="igr:flex igr:justify-end igr:gap-1">
                                            <Button variant="ghost" size="sm" onClick={() => setEditing(c)} aria-label={t('platform.fx.setManual', { currency: c.currency })}>
                                                <Pencil className="igr:size-4" />
                                            </Button>
                                            {c.override ? (
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    disabled={remove.isPending}
                                                    aria-label={t('platform.fx.useAuto', { currency: c.currency })}
                                                    onClick={() =>
                                                        remove.mutate(c.currency, {
                                                            onSuccess: () => toast.success(t('platform.fx.removed', { currency: c.currency })),
                                                            onError: (e) => toast.error(t('errors.title'), isApiError(e) ? e.message : undefined),
                                                        })
                                                    }
                                                >
                                                    <Undo2 className="igr:size-4" />
                                                </Button>
                                            ) : null}
                                        </div>
                                    </TD>
                                </TR>
                            ))}
                        </TBody>
                    </Table>
                    <p className="igr:mt-3 igr:text-xs igr:text-muted-foreground">{t('platform.fx.vesHelp')}</p>
                </CardContent>
            </Card>
            {editing !== null ? <OverrideDialog currency={editing} onClose={() => setEditing(null)} /> : null}
        </div>
    );
}

function OverrideDialog({ currency, onClose }: { currency: FxCurrency; onClose: () => void }) {
    const { t } = useTranslation();
    const save = useSetFxOverride();
    const [value, setValue] = useState(currency.override ? String(currency.override.rate) : currency.rate !== null ? String(currency.rate) : '');
    const [from, setFrom] = useState(currency.override?.effective_from ?? new Date().toISOString().slice(0, 10));
    const [note, setNote] = useState(currency.override?.note ?? '');
    const [error, setError] = useState<string | null>(null);

    const submit = (e: SyntheticEvent) => {
        e.preventDefault();
        const rate = parseRate(value);
        if (rate === null) {
            setError(t('platform.fx.invalidRate'));
            return;
        }
        save.mutate(
            { currency: currency.currency, rate, effective_from: from, note },
            {
                onSuccess: () => {
                    toast.success(t('platform.fx.saved', { currency: currency.currency }));
                    onClose();
                },
                onError: (err) => setError(isApiError(err) ? err.message : t('errors.title')),
            },
        );
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent>
                <form onSubmit={submit}>
                    <DialogHeader>
                        <DialogTitle>{t('platform.fx.dialogTitle', { currency: currency.currency })}</DialogTitle>
                        <DialogDescription>{t('platform.fx.dialogHelp')}</DialogDescription>
                    </DialogHeader>
                    <div className="igr:grid igr:gap-4 igr:py-4">
                        <div className="igr:space-y-1.5">
                            <Label htmlFor="fx-rate">{t('platform.fx.rateLabel', { currency: currency.currency })}</Label>
                            <Input id="fx-rate" inputMode="decimal" required value={value} onChange={(e) => setValue(e.target.value)} />
                        </div>
                        <div className="igr:space-y-1.5">
                            <Label htmlFor="fx-from">{t('platform.fx.fromLabel')}</Label>
                            <Input id="fx-from" type="date" required value={from} onChange={(e) => setFrom(e.target.value)} />
                        </div>
                        <div className="igr:space-y-1.5">
                            <Label htmlFor="fx-note">{t('platform.fx.noteLabel')}</Label>
                            <Input id="fx-note" maxLength={190} value={note} onChange={(e) => setNote(e.target.value)} placeholder={t('platform.fx.notePlaceholder')} />
                        </div>
                        {error ? <p className="igr:text-sm igr:text-destructive">{error}</p> : null}
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={onClose}>
                            {t('common.cancel')}
                        </Button>
                        <Button type="submit" disabled={save.isPending}>
                            {t('common.save')}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
