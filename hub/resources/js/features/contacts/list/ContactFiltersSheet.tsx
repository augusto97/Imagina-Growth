import { useState, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import { Input, Select } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Sheet, SheetBody, SheetContent, SheetDescription, SheetFooter, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { formatNumber } from '@/lib/format';
import { useWorkspace } from '@/hooks/useWorkspace';
import type { ContactFilters, ContactSort } from '../api';
import type { Business } from '../business';
import { DEFAULT_FILTERS } from '../filters';
import { CONSENT_CHANNELS, LIFECYCLE_STAGES, RFM_SEGMENTS } from '../labels';

export interface GroupOption {
    id: string;
    name: string;
}

/**
 * «Filtros» de la lista de personas, en un cajón (derecha en el escritorio, abajo en el celular): cada cambio se aplica
 * al momento y el botón de abajo dice cuántas personas quedan. Los campos de texto se aplican al salir o con Enter.
 */
export function ContactFiltersSheet({
    open,
    onOpenChange,
    filters,
    onChange,
    business,
    sorts,
    groups,
    total,
    side,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    filters: ContactFilters;
    onChange: (patch: Partial<ContactFilters>) => void;
    business: Business;
    /** En el celular el orden va aquí (arriba no cabe). */
    sorts: ContactSort[] | null;
    groups: GroupOption[] | null;
    total: number | null;
    side: 'right' | 'bottom';
}) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const [city, setCity] = useState(filters.city);
    const [tag, setTag] = useState(filters.tag);
    const text = (key: 'city' | 'tag', value: string) => {
        if (value.trim() !== filters[key]) {
            onChange({ [key]: value.trim() });
        }
    };

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent side={side} aria-describedby="contacts-filters-help">
                <SheetHeader>
                    <SheetTitle>{t('contacts.filtersPanel.title')}</SheetTitle>
                    <SheetDescription id="contacts-filters-help">{t('contacts.filtersPanel.description')}</SheetDescription>
                </SheetHeader>
                <SheetBody className="igr:space-y-4">
                    {sorts !== null ? (
                        <Field id="cf-sort" label={t('contacts.sort.label')}>
                            <Select id="cf-sort" value={sorts.includes(filters.sort) ? filters.sort : 'last_seen'} onChange={(e) => onChange({ sort: e.target.value as ContactSort })}>
                                {sorts.map((s) => (
                                    <option key={s} value={s}>
                                        {t(`contacts.sort.${s}`)}
                                    </option>
                                ))}
                            </Select>
                        </Field>
                    ) : null}
                    {groups !== null ? (
                        <Field id="cf-segment" label={t('contacts.filtersPanel.segment')}>
                            <Select id="cf-segment" value={filters.segment} onChange={(e) => onChange({ segment: e.target.value })}>
                                <option value="">{t('contacts.filtersPanel.anySegment')}</option>
                                {groups.map((g) => (
                                    <option key={g.id} value={g.id}>
                                        {g.name}
                                    </option>
                                ))}
                            </Select>
                        </Field>
                    ) : null}
                    <Field id="cf-consent" label={t('contacts.filtersPanel.consent')} help={t('contacts.filtersPanel.consentHelp')}>
                        <Select id="cf-consent" value={filters.consent} onChange={(e) => onChange({ consent: e.target.value as ContactFilters['consent'] })}>
                            <option value="">{t('contacts.filtersPanel.anyConsent')}</option>
                            {CONSENT_CHANNELS.map((c) => (
                                <option key={c} value={c}>
                                    {t('contacts.filters.consentTo', { channel: t(`contacts.consentChannels.${c}`) })}
                                </option>
                            ))}
                        </Select>
                    </Field>
                    {business.services ? (
                        <Field id="cf-temperature" label={t('contacts.filtersPanel.temperature')} help={t('contacts.filtersPanel.temperatureHelp')}>
                            <Select id="cf-temperature" value={filters.temperature} onChange={(e) => onChange({ temperature: e.target.value as ContactFilters['temperature'] })}>
                                <option value="">{t('contacts.filtersPanel.anyTemperature')}</option>
                                {(['hot', 'warm', 'cold'] as const).map((k) => (
                                    <option key={k} value={k}>
                                        {t(`contacts.temperature.${k}`)}
                                    </option>
                                ))}
                            </Select>
                        </Field>
                    ) : null}
                    {business.store ? (
                        <>
                            <Field id="cf-buyers" label={t('contacts.filters.buyers')}>
                                <Select id="cf-buyers" value={filters.buyers} onChange={(e) => onChange({ buyers: e.target.value as ContactFilters['buyers'] })}>
                                    <option value="">{t('contacts.filters.anyBuyer')}</option>
                                    <option value="1">{t('contacts.filters.buyersOnly')}</option>
                                    <option value="0">{t('contacts.filters.nonBuyers')}</option>
                                </Select>
                            </Field>
                            <Field id="cf-rfm" label={t('contacts.filtersPanel.rfm')} help={t('contacts.filtersPanel.rfmHelp')}>
                                <Select id="cf-rfm" value={filters.rfm_segment} onChange={(e) => onChange({ rfm_segment: e.target.value })}>
                                    <option value="">{t('contacts.filters.anyRfm')}</option>
                                    {RFM_SEGMENTS.map((s) => (
                                        <option key={s} value={s}>
                                            {t(`contacts.rfm.${s}`)}
                                        </option>
                                    ))}
                                </Select>
                            </Field>
                        </>
                    ) : null}
                    <Field id="cf-city" label={t('contacts.filters.city')}>
                        <Input
                            id="cf-city"
                            value={city}
                            maxLength={120}
                            placeholder={t('contacts.filtersPanel.cityPlaceholder')}
                            onChange={(e) => setCity(e.target.value)}
                            onBlur={() => text('city', city)}
                            onKeyDown={(e) => e.key === 'Enter' && text('city', city)}
                        />
                    </Field>
                    <Field id="cf-stage" label={t('contacts.filtersPanel.stage')}>
                        <Select id="cf-stage" value={filters.stage} onChange={(e) => onChange({ stage: e.target.value })}>
                            <option value="">{t('contacts.filters.anyStage')}</option>
                            {LIFECYCLE_STAGES.map((s) => (
                                <option key={s} value={s}>
                                    {t(`stages.${s}`)}
                                </option>
                            ))}
                        </Select>
                    </Field>
                    <Field id="cf-tag" label={t('contacts.filters.tag')}>
                        <Input
                            id="cf-tag"
                            value={tag}
                            maxLength={40}
                            placeholder={t('contacts.filtersPanel.tagPlaceholder')}
                            onChange={(e) => setTag(e.target.value)}
                            onBlur={() => text('tag', tag)}
                            onKeyDown={(e) => e.key === 'Enter' && text('tag', tag)}
                        />
                    </Field>
                </SheetBody>
                <SheetFooter className="igr:justify-between">
                    <Button
                        variant="ghost"
                        onClick={() => {
                            setCity('');
                            setTag('');
                            onChange({ ...DEFAULT_FILTERS, q: filters.q, sort: filters.sort });
                        }}
                    >
                        {t('contacts.filters.clear')}
                    </Button>
                    <Button onClick={() => onOpenChange(false)}>
                        {total === null ? t('contacts.filtersPanel.done') : t('contacts.filtersPanel.see', { count: total, formatted: formatNumber(total, workspace.locale) })}
                    </Button>
                </SheetFooter>
            </SheetContent>
        </Sheet>
    );
}

function Field({ id, label, help, children }: { id: string; label: string; help?: string; children: ReactNode }) {
    return (
        <div className="igr:space-y-1.5">
            <Label htmlFor={id}>{label}</Label>
            {children}
            {help ? <p className="igr:text-caption igr:text-muted-foreground">{help}</p> : null}
        </div>
    );
}
