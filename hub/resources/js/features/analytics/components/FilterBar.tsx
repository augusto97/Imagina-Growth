import { CalendarRange, SlidersHorizontal, X } from 'lucide-react';
import { useEffect, useState, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { useSites } from '@/api/queries';
import { Button } from '@/components/ui/button';
import { Input, Select } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Sheet, SheetBody, SheetContent, SheetDescription, SheetFooter, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { useDebounced } from '@/hooks/useDebounced';
import { PHONE_QUERY, useMedia } from '@/hooks/useMedia';
import { useWorkspace } from '@/hooks/useWorkspace';
import type { CompareMode, PeriodInfo } from '../api';
import type { Formatters } from '../format';
import { activeFilterCount, CHANNELS, COMPARE_MODES, DEVICES, RANGE_PRESETS } from '../params';
import type { AnalyticsParams, RangePreset } from '../params';

type Update = (patch: Partial<AnalyticsParams>) => void;

/**
 * Los controles de los tableros y del Resumen, iguales en todas las pantallas de Resultados: periodo, comparación y,
 * donde aplican, sitio, canal de llegada, dispositivo y ciudad. En el celular el periodo y la comparación quedan en una
 * fila y los demás filtros se abren en un panel inferior («Filtros»), al alcance del pulgar.
 */
export function FilterBar({
    params,
    update,
    period,
    fmt,
    periodOnly = false,
}: {
    params: AnalyticsParams;
    update: Update;
    period: PeriodInfo | undefined;
    fmt: Formatters;
    /** Solo periodo y comparación (p. ej. el Resumen y mensajería, donde sitio, canal de llegada y ciudad no aplican). */
    periodOnly?: boolean;
}) {
    const { t } = useTranslation();
    const phone = useMedia(PHONE_QUERY);
    const [open, setOpen] = useState(false);
    const filters = activeFilterCount(params);

    return (
        <div className="igr:mb-4">
            <div className="igr:flex igr:flex-wrap igr:items-center igr:gap-2" role="toolbar" aria-label={t('analytics.filters.label')}>
                <div className="igr:flex igr:min-w-0 igr:items-center igr:gap-1.5 igr:max-md:flex-1">
                    <CalendarRange className="igr:size-4 igr:shrink-0 igr:text-muted-foreground" aria-hidden />
                    <Select aria-label={t('analytics.filters.range')} className="igr:w-auto igr:max-md:flex-1" value={params.range} onChange={(e) => update({ range: e.target.value as RangePreset })}>
                        {RANGE_PRESETS.map((r) => (
                            <option key={r} value={r}>
                                {t(`analytics.range.${r}`)}
                            </option>
                        ))}
                    </Select>
                </div>
                <Select
                    aria-label={t('analytics.filters.compare')}
                    className="igr:w-auto igr:max-md:min-w-0 igr:max-md:flex-1"
                    value={params.compare}
                    onChange={(e) => update({ compare: e.target.value as CompareMode })}
                >
                    {COMPARE_MODES.map((c) => (
                        <option key={c} value={c}>
                            {t(`analytics.compare.${phone ? `${c}Short` : c}`)}
                        </option>
                    ))}
                </Select>
                {params.range === 'custom' ? <CustomRange params={params} update={update} /> : null}
                {periodOnly ? null : phone ? (
                    <Button variant="outline" onClick={() => setOpen(true)} aria-haspopup="dialog">
                        <SlidersHorizontal aria-hidden />
                        {filters > 0 ? t('analytics.filters.openCount', { count: filters }) : t('analytics.filters.open')}
                    </Button>
                ) : (
                    <MoreFilters params={params} update={update} />
                )}
                {!periodOnly && filters > 0 && !phone ? (
                    <Button variant="ghost" size="sm" onClick={() => update({ site: '', channel: '', device: '', city: '' })}>
                        <X />
                        {t('analytics.filters.clear', { count: filters })}
                    </Button>
                ) : null}
                {/* Siempre está (vacía mientras carga): en el celular ocupa su propia línea y no debe empujar el tablero al llegar. */}
                <span className="igr:min-h-8 igr:text-xs igr:text-muted-foreground igr:tabular-nums igr:max-md:w-full igr:md:ml-auto igr:md:min-h-0">
                    {period ? (
                        <>
                            {fmt.date(period.from)} – {fmt.date(period.to)}
                            {period.compare ? ` · ${t('analytics.filters.vs')} ${fmt.date(period.compare.from)} – ${fmt.date(period.compare.to)}` : ''}
                        </>
                    ) : null}
                </span>
            </div>
            {phone && !periodOnly ? (
                <Sheet open={open} onOpenChange={setOpen}>
                    <SheetContent side="bottom">
                        <SheetHeader>
                            <SheetTitle>{t('analytics.filters.sheetTitle')}</SheetTitle>
                            <SheetDescription>{t('analytics.filters.sheetDescription')}</SheetDescription>
                        </SheetHeader>
                        <SheetBody className="igr:space-y-3">
                            <MoreFilters params={params} update={update} stacked />
                        </SheetBody>
                        <SheetFooter>
                            {filters > 0 ? (
                                <Button variant="ghost" onClick={() => update({ site: '', channel: '', device: '', city: '' })}>
                                    {t('analytics.filters.clear', { count: filters })}
                                </Button>
                            ) : null}
                            <Button onClick={() => setOpen(false)}>{t('analytics.filters.done')}</Button>
                        </SheetFooter>
                    </SheetContent>
                </Sheet>
            ) : null}
        </div>
    );
}

function CustomRange({ params, update }: { params: AnalyticsParams; update: Update }) {
    const { t } = useTranslation();
    return (
        <div className="igr:flex igr:items-center igr:gap-1 igr:max-md:w-full">
            <Input
                type="date"
                aria-label={t('analytics.filters.from')}
                className="igr:w-auto igr:max-md:flex-1"
                value={params.from}
                max={params.to}
                onChange={(e) => e.target.value && update({ range: 'custom', from: e.target.value, to: params.to })}
            />
            <span className="igr:text-muted-foreground">–</span>
            <Input
                type="date"
                aria-label={t('analytics.filters.to')}
                className="igr:w-auto igr:max-md:flex-1"
                value={params.to}
                min={params.from}
                onChange={(e) => e.target.value && update({ range: 'custom', from: params.from, to: e.target.value })}
            />
        </div>
    );
}

/** Sitio (si hay más de uno), canal de llegada, dispositivo y ciudad: en línea en el escritorio, apilados en el panel del celular. */
function MoreFilters({ params, update, stacked = false }: { params: AnalyticsParams; update: Update; stacked?: boolean }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const sites = useSites(workspace.id);
    const [city, setCity] = useState(params.city);
    const debouncedCity = useDebounced(city, 400);

    useEffect(() => {
        if (debouncedCity.trim() !== params.city) {
            update({ city: debouncedCity.trim() });
        }
        // Solo reacciona al texto ya estabilizado.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [debouncedCity]);

    // Si la URL cambia por fuera (p. ej. «Quitar filtros»), el campo se sincroniza.
    const [syncedCity, setSyncedCity] = useState(params.city);
    if (params.city !== syncedCity) {
        setSyncedCity(params.city);
        if (params.city !== debouncedCity.trim()) {
            setCity(params.city);
        }
    }
    const siteList = sites.data ?? [];
    const field = (id: string, label: string, control: ReactNode) =>
        stacked ? (
            <div key={id} className="igr:space-y-1.5">
                <Label htmlFor={id}>{label}</Label>
                {control}
            </div>
        ) : (
            control
        );

    return (
        <>
            {siteList.length > 1
                ? field(
                      'flt-site',
                      t('analytics.filters.site'),
                      <Select id="flt-site" aria-label={t('analytics.filters.site')} className={stacked ? undefined : 'igr:w-auto igr:max-w-48'} value={params.site} onChange={(e) => update({ site: e.target.value })}>
                          <option value="">{t('analytics.filters.allSites')}</option>
                          {siteList.map((s) => (
                              <option key={s.id} value={s.id}>
                                  {s.name ?? s.host}
                              </option>
                          ))}
                      </Select>,
                  )
                : null}
            {field(
                'flt-channel',
                t('analytics.filters.channel'),
                <Select id="flt-channel" aria-label={t('analytics.filters.channel')} className={stacked ? undefined : 'igr:w-auto igr:max-w-48'} value={params.channel} onChange={(e) => update({ channel: e.target.value })}>
                    <option value="">{t('analytics.filters.allChannels')}</option>
                    {CHANNELS.map((c) => (
                        <option key={c} value={c}>
                            {t(`analytics.channel.${c}`)}
                        </option>
                    ))}
                </Select>,
            )}
            {field(
                'flt-device',
                t('analytics.filters.device'),
                <Select id="flt-device" aria-label={t('analytics.filters.device')} className={stacked ? undefined : 'igr:w-auto'} value={params.device} onChange={(e) => update({ device: e.target.value })}>
                    <option value="">{t('analytics.filters.allDevices')}</option>
                    {DEVICES.map((d) => (
                        <option key={d} value={d}>
                            {t(`analytics.device.${d}`)}
                        </option>
                    ))}
                </Select>,
            )}
            {field(
                'flt-city',
                t('analytics.filters.city'),
                <Input
                    id="flt-city"
                    aria-label={t('analytics.filters.city')}
                    placeholder={t('analytics.filters.cityPlaceholder')}
                    className={stacked ? undefined : 'igr:w-36'}
                    value={city}
                    onChange={(e) => setCity(e.target.value)}
                />,
            )}
        </>
    );
}
