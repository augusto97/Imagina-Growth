import { CalendarRange, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useSites } from '@/api/queries';
import { Button } from '@/components/ui/button';
import { Input, Select } from '@/components/ui/input';
import { useDebounced } from '@/hooks/useDebounced';
import { useWorkspace } from '@/hooks/useWorkspace';
import type { CompareMode, PeriodInfo } from '../api';
import type { Formatters } from '../format';
import { activeFilterCount, CHANNELS, COMPARE_MODES, DEVICES, RANGE_PRESETS } from '../params';
import type { AnalyticsParams, RangePreset } from '../params';

/** Una sola fila de controles sobre los gráficos: rango, comparación y filtros. */
export function FilterBar({
    params,
    update,
    period,
    fmt,
}: {
    params: AnalyticsParams;
    update: (patch: Partial<AnalyticsParams>) => void;
    period: PeriodInfo | undefined;
    fmt: Formatters;
}) {
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

    useEffect(() => setCity(params.city), [params.city]);

    const siteList = sites.data ?? [];
    const filters = activeFilterCount(params);

    return (
        <div className="igr:mb-4 igr:flex igr:flex-wrap igr:items-center igr:gap-2" role="toolbar" aria-label={t('analytics.filters.label')}>
            <div className="igr:flex igr:items-center igr:gap-1.5">
                <CalendarRange className="igr:size-4 igr:text-muted-foreground" aria-hidden />
                <Select aria-label={t('analytics.filters.range')} className="igr:w-auto" value={params.range} onChange={(e) => update({ range: e.target.value as RangePreset })}>
                    {RANGE_PRESETS.map((r) => (
                        <option key={r} value={r}>
                            {t(`analytics.range.${r}`)}
                        </option>
                    ))}
                </Select>
            </div>
            {params.range === 'custom' ? (
                <div className="igr:flex igr:items-center igr:gap-1">
                    <Input type="date" aria-label={t('analytics.filters.from')} className="igr:w-auto" value={params.from} max={params.to} onChange={(e) => e.target.value && update({ range: 'custom', from: e.target.value, to: params.to })} />
                    <span className="igr:text-muted-foreground">–</span>
                    <Input type="date" aria-label={t('analytics.filters.to')} className="igr:w-auto" value={params.to} min={params.from} onChange={(e) => e.target.value && update({ range: 'custom', from: params.from, to: e.target.value })} />
                </div>
            ) : null}
            <Select aria-label={t('analytics.filters.compare')} className="igr:w-auto" value={params.compare} onChange={(e) => update({ compare: e.target.value as CompareMode })}>
                {COMPARE_MODES.map((c) => (
                    <option key={c} value={c}>
                        {t(`analytics.compare.${c}`)}
                    </option>
                ))}
            </Select>
            {siteList.length > 1 ? (
                <Select aria-label={t('analytics.filters.site')} className="igr:w-auto igr:max-w-48" value={params.site} onChange={(e) => update({ site: e.target.value })}>
                    <option value="">{t('analytics.filters.allSites')}</option>
                    {siteList.map((s) => (
                        <option key={s.id} value={s.id}>
                            {s.name ?? s.host}
                        </option>
                    ))}
                </Select>
            ) : null}
            <Select aria-label={t('analytics.filters.channel')} className="igr:w-auto igr:max-w-48" value={params.channel} onChange={(e) => update({ channel: e.target.value })}>
                <option value="">{t('analytics.filters.allChannels')}</option>
                {CHANNELS.map((c) => (
                    <option key={c} value={c}>
                        {t(`analytics.channel.${c}`)}
                    </option>
                ))}
            </Select>
            <Select aria-label={t('analytics.filters.device')} className="igr:w-auto" value={params.device} onChange={(e) => update({ device: e.target.value })}>
                <option value="">{t('analytics.filters.allDevices')}</option>
                {DEVICES.map((d) => (
                    <option key={d} value={d}>
                        {t(`analytics.device.${d}`)}
                    </option>
                ))}
            </Select>
            <Input aria-label={t('analytics.filters.city')} placeholder={t('analytics.filters.cityPlaceholder')} className="igr:w-36" value={city} onChange={(e) => setCity(e.target.value)} />
            {filters > 0 ? (
                <Button variant="ghost" size="sm" onClick={() => update({ site: '', channel: '', device: '', city: '' })}>
                    <X />
                    {t('analytics.filters.clear', { count: filters })}
                </Button>
            ) : null}
            {period ? (
                <span className="igr:ml-auto igr:text-xs igr:text-muted-foreground igr:tabular-nums">
                    {fmt.date(period.from)} – {fmt.date(period.to)}
                    {period.compare ? ` · ${t('analytics.filters.vs')} ${fmt.date(period.compare.from)} – ${fmt.date(period.compare.to)}` : ''}
                </span>
            ) : null}
        </div>
    );
}
