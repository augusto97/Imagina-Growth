import { CalendarPlus, Undo2 } from 'lucide-react';
import { useState, type SyntheticEvent } from 'react';
import { useTranslation } from 'react-i18next';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input, Select } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { EmptyState, ErrorState, FieldError } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TBody, TD, TH, THead, TR } from '@/components/ui/table';
import { useWorkspace } from '@/hooks/useWorkspace';
import { isApiError } from '@/lib/api';
import { toast } from '@/stores/toast';
import { type HolidayRow, usePlatformHolidays, useResetHoliday, useSetHoliday } from './settings-api';

const COUNTRIES = ['CO', 'MX', 'VE'];

/** Plataforma → Ajustes → Festivos (F17): los de la ley y los que agrega o quita el superadmin (antes `igr:holidays`). */
export function HolidaysPanel() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const thisYear = new Date().getFullYear();
    const [country, setCountry] = useState(COUNTRIES.includes(workspace.country) ? workspace.country : 'CO');
    const [year, setYear] = useState(thisYear);
    const [adding, setAdding] = useState(false);
    const list = usePlatformHolidays(country, year);
    const setHoliday = useSetHoliday();
    const reset = useResetHoliday();
    const fail = (e: unknown) => toast.error(t('errors.title'), isApiError(e) ? e.message : undefined);
    const day = (ymd: string) =>
        new Intl.DateTimeFormat(workspace.locale, { timeZone: 'UTC', weekday: 'short', day: 'numeric', month: 'long' }).format(new Date(`${ymd}T12:00:00Z`));

    const action = (h: HolidayRow) => {
        if (h.origin === 'law') {
            return (
                <Button
                    size="sm"
                    variant="ghost"
                    disabled={setHoliday.isPending}
                    onClick={() =>
                        setHoliday.mutate(
                            { country, date: h.date, is_holiday: false },
                            { onSuccess: () => toast.success(t('platformSettings.holidays.removedToast')), onError: fail },
                        )
                    }
                >
                    {t('platformSettings.holidays.remove')}
                </Button>
            );
        }
        if (h.origin === 'added' || h.origin === 'removed') {
            return (
                <Button
                    size="sm"
                    variant="ghost"
                    disabled={reset.isPending}
                    onClick={() => reset.mutate({ country, date: h.date }, { onSuccess: () => toast.success(t('platformSettings.holidays.restored')), onError: fail })}
                >
                    <Undo2 />
                    {t('platformSettings.holidays.undo')}
                </Button>
            );
        }
        return null;
    };

    return (
        <Card>
            <CardHeader>
                <CardTitle>{t('platformSettings.sections.holidays.title')}</CardTitle>
                <CardDescription>{t('platformSettings.sections.holidays.description')}</CardDescription>
            </CardHeader>
            <CardContent className="igr:space-y-4">
                <div className="igr:flex igr:flex-wrap igr:items-end igr:gap-3">
                    <div className="igr:space-y-1.5">
                        <Label htmlFor="hol-country">{t('platformSettings.holidays.country')}</Label>
                        <Select id="hol-country" value={country} onChange={(e) => setCountry(e.target.value)}>
                            {COUNTRIES.map((c) => (
                                <option key={c} value={c}>
                                    {t(`platformSettings.holidays.countries.${c}`)}
                                </option>
                            ))}
                        </Select>
                    </div>
                    <div className="igr:space-y-1.5">
                        <Label htmlFor="hol-year">{t('platformSettings.holidays.year')}</Label>
                        <Select id="hol-year" value={String(year)} onChange={(e) => setYear(Number(e.target.value))}>
                            {[thisYear - 1, thisYear, thisYear + 1, thisYear + 2].map((y) => (
                                <option key={y} value={y}>
                                    {y}
                                </option>
                            ))}
                        </Select>
                    </div>
                    <Button className="igr:ml-auto" onClick={() => setAdding(true)}>
                        <CalendarPlus />
                        {t('platformSettings.holidays.add')}
                    </Button>
                </div>
                {list.isError ? (
                    <ErrorState error={list.error} onRetry={() => void list.refetch()} />
                ) : list.isPending ? (
                    <Skeleton className="igr:h-64" />
                ) : list.data.holidays.length === 0 ? (
                    <EmptyState icon={CalendarPlus} title={t('platformSettings.holidays.empty')} />
                ) : (
                    <div className="igr:overflow-x-auto">
                        <Table>
                            <THead>
                                <TR>
                                    <TH>{t('platformSettings.holidays.date')}</TH>
                                    <TH>{t('platformSettings.holidays.name')}</TH>
                                    <TH>
                                        <span className="igr:sr-only">{t('platform.accounts.actions')}</span>
                                    </TH>
                                </TR>
                            </THead>
                            <TBody>
                                {list.data.holidays.map((h) => (
                                    <TR key={h.date} className={h.origin === 'removed' ? 'igr:text-muted-foreground' : undefined}>
                                        <TD className="igr:whitespace-nowrap igr:tabular-nums">{day(h.date)}</TD>
                                        <TD>
                                            <span className={h.origin === 'removed' ? 'igr:line-through' : undefined}>{h.name}</span>
                                            {h.origin !== 'law' ? (
                                                <Badge variant={h.origin === 'removed' ? 'outline' : 'default'} className="igr:ml-2">
                                                    {t(`platformSettings.holidays.origin.${h.origin}`)}
                                                </Badge>
                                            ) : null}
                                        </TD>
                                        <TD className="igr:text-right">{action(h)}</TD>
                                    </TR>
                                ))}
                            </TBody>
                        </Table>
                    </div>
                )}
            </CardContent>
            {adding ? <AddHolidayDialog country={country} year={year} onClose={() => setAdding(false)} /> : null}
        </Card>
    );
}

function AddHolidayDialog({ country, year, onClose }: { country: string; year: number; onClose: () => void }) {
    const { t } = useTranslation();
    const save = useSetHoliday();
    const [date, setDate] = useState(`${year}-01-01`);
    const [name, setName] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});

    const submit = (e: SyntheticEvent) => {
        e.preventDefault();
        save.mutate(
            { country, date, is_holiday: true, name },
            {
                onSuccess: () => {
                    toast.success(t('platformSettings.holidays.added'));
                    onClose();
                },
                onError: (err) => setErrors(isApiError(err) ? { ...err.fieldErrors, form: err.message } : { form: t('errors.title') }),
            },
        );
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent>
                <form onSubmit={submit}>
                    <DialogHeader>
                        <DialogTitle>{t('platformSettings.holidays.addTitle')}</DialogTitle>
                        <DialogDescription>{t('platformSettings.holidays.addHelp')}</DialogDescription>
                    </DialogHeader>
                    <div className="igr:grid igr:gap-4 igr:py-4">
                        <div className="igr:space-y-1.5">
                            <Label htmlFor="hol-date">{t('platformSettings.holidays.date')}</Label>
                            <Input id="hol-date" type="date" required value={date} onChange={(e) => setDate(e.target.value)} />
                            <FieldError message={errors.date} />
                        </div>
                        <div className="igr:space-y-1.5">
                            <Label htmlFor="hol-name">{t('platformSettings.holidays.name')}</Label>
                            <Input
                                id="hol-name"
                                required
                                maxLength={120}
                                value={name}
                                placeholder={t('platformSettings.holidays.namePlaceholder')}
                                onChange={(e) => setName(e.target.value)}
                            />
                            <FieldError message={errors.name} />
                        </div>
                        {errors.form && !errors.name && !errors.date ? <p className="igr:text-sm igr:text-destructive">{errors.form}</p> : null}
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={onClose}>
                            {t('common.cancel')}
                        </Button>
                        <Button type="submit" loading={save.isPending}>
                            {t('common.save')}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
