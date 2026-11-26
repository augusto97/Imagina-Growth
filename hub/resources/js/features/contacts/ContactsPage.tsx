import { useQuery } from '@tanstack/react-query';
import { ChevronLeft, ChevronRight, Download, FileUp, Globe, Search, SlidersHorizontal, UsersRound, X } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useSearchParams } from 'react-router';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Chip, ChipGroup } from '@/components/ui/chip';
import { Input, Select } from '@/components/ui/input';
import { EmptyState, ErrorState, PageHeader } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { useDebounced } from '@/hooks/useDebounced';
import { PHONE_QUERY, useMedia } from '@/hooks/useMedia';
import { useCan, useWorkspace } from '@/hooks/useWorkspace';
import { formatNumber } from '@/lib/format';
import { toast } from '@/stores/toast';
import { segmentsQuery } from '@/features/segments/api';
import { useContactAbilities } from './abilities';
import { type ContactFilters, type ContactSelection, type ContactSort, contactsExportUrl, useContactList } from './api';
import { useBusiness, usePersonTerms } from './business';
import { DEFAULT_FILTERS, PANEL_FILTERS, type PanelFilter, activeFilterCount, hasActiveFilters, quickActive, quickFilters, readFilters, toggleQuick, writeFilters } from './filters';
import { AddToGroupDialog } from './list/AddToGroupDialog';
import { ContactFiltersSheet } from './list/ContactFiltersSheet';
import { ContactRows } from './list/ContactRows';

const STORE_SORTS: ContactSort[] = ['last_seen', 'last_order', 'revenue', 'orders', 'created'];
const SERVICE_SORTS: ContactSort[] = ['last_seen', 'created', 'lead_score'];

/**
 * Clientes → Personas (modulos.md M03): búsqueda al instante, filtros rápidos a la vista y el resto en «Filtros», las
 * columnas que importan según el negocio (compras en una tienda; lead y cita en servicios), y acciones masivas
 * claras: agregar a un grupo y exportar, para las elegidas o para todas las que cumplen los filtros.
 */
export function ContactsPage() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const can = useContactAbilities();
    const canMarket = useCan('manageMarketing');
    const canSeeGroups = useCan('viewAllContacts');
    const canGroup = canMarket && canSeeGroups;
    const canConnect = useCan('manageSites');
    const business = useBusiness();
    const terms = usePersonTerms();
    const phone = useMedia(PHONE_QUERY);
    const [params, setParams] = useSearchParams();
    const filters = readFilters(params);
    const contacts = useContactList(workspace.id, filters, true, business.services);
    const segments = useQuery({ ...segmentsQuery(workspace.id), enabled: canSeeGroups });
    const meta = contacts.data?.meta;
    const total = meta?.total ?? null;
    const pages = meta ? Math.max(1, Math.ceil(meta.total / meta.per_page)) : 1;
    const [sheet, setSheet] = useState(false);
    const [groupDialog, setGroupDialog] = useState(false);

    // La búsqueda se escribe al instante y llega a la URL con un pequeño retraso.
    const [q, setQ] = useState(filters.q);
    const debounced = useDebounced(q, 250);
    useEffect(() => {
        const current = readFilters(params);
        if (debounced.trim() !== current.q) {
            setParams(writeFilters({ ...current, q: debounced.trim(), page: 1 }), { replace: true });
        }
        // Solo reacciona a lo escrito; params se lee fresco dentro.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [debounced]);

    const apply = (next: ContactFilters) => setParams(writeFilters(next), { replace: true });
    const update = (patch: Partial<ContactFilters>) => apply({ ...filters, page: 1, ...patch });
    const clear = () => {
        setQ('');
        apply({ ...DEFAULT_FILTERS, sort: filters.sort });
    };

    // Las elegidas valen para la vista actual: cambiar un filtro o de página las suelta.
    const viewKey = params.toString();
    const [selection, setSelection] = useState<{ key: string; ids: Set<string>; all: boolean }>({ key: viewKey, ids: new Set(), all: false });
    const chosen = selection.key === viewKey ? selection : { key: viewKey, ids: new Set<string>(), all: false };
    const rows = contacts.data?.data ?? [];
    const toggle = (id: string) => {
        const ids = new Set(chosen.ids);
        if (ids.has(id)) {
            ids.delete(id);
        } else {
            ids.add(id);
        }
        setSelection({ key: viewKey, ids, all: false });
    };
    const togglePage = () => {
        const every = rows.length > 0 && rows.every((r) => chosen.ids.has(r.id));
        setSelection({ key: viewKey, ids: every ? new Set() : new Set(rows.map((r) => r.id)), all: false });
    };
    const clearSelection = () => setSelection({ key: viewKey, ids: new Set(), all: false });
    const selectedCount = chosen.all && total !== null ? total : chosen.ids.size;
    const bulkSelection: ContactSelection = chosen.all ? { all: true, filters } : { ids: [...chosen.ids] };

    const groups = useMemo(() => (segments.data ?? []).map((s) => ({ id: s.id, name: s.name })), [segments.data]);
    const staticGroups = useMemo(() => (segments.data ?? []).filter((s) => s.kind === 'static' && !s.is_system).map((s) => ({ id: s.id, name: s.name })), [segments.data]);
    const quick = quickFilters(business.store, business.services);
    const sorts = business.servicesOnly ? SERVICE_SORTS : STORE_SORTS;
    const filtered = hasActiveFilters(filters);
    const panelCount = activeFilterCount(filters);
    const bulkEnabled = canGroup || can.export;
    const siteMissing = workspace.connections?.site === 'none' || workspace.connections?.site === 'pending';

    // Lo que está activo y no se ve en un chip rápido: un chip que se puede quitar.
    const extraFilters = PANEL_FILTERS.filter((key) => filters[key] !== '' && !quick.some((qf) => qf !== 'all' && quickActive({ ...DEFAULT_FILTERS, [key]: filters[key] }, qf)));
    const filterLabel = (key: PanelFilter): string => {
        const value = filters[key];
        switch (key) {
            case 'segment':
                return groups.find((g) => g.id === value)?.name ?? t('contacts.filtersPanel.segment');
            case 'stage':
                return t(`stages.${value}`, { defaultValue: value });
            case 'rfm_segment':
                return t(`contacts.rfm.${value}`, { defaultValue: value });
            case 'consent':
                return t('contacts.filters.consentTo', { channel: t(`contacts.consentChannels.${value}`) });
            case 'buyers':
                return t(value === '1' ? 'contacts.filters.buyersOnly' : 'contacts.filters.nonBuyers');
            case 'temperature':
                return t(`contacts.temperature.${value}`);
            default:
                return value;
        }
    };

    const description = business.servicesOnly ? t('contacts.list.descriptionServices', { many: terms.many }) : t('contacts.list.descriptionStore');

    return (
        <>
            <PageHeader
                title={t('nav.contacts')}
                description={description}
                meta={
                    total !== null && !filtered ? (
                        <Badge variant="secondary" className="igr:tabular-nums">
                            {t('contacts.count', { count: total, formatted: formatNumber(total, workspace.locale) })}
                        </Badge>
                    ) : undefined
                }
                actions={
                    can.edit ? (
                        <Button variant="outline" asChild>
                            <Link to={`/w/${workspace.id}/contactos/importar`}>
                                <FileUp />
                                {t('contacts.list.import')}
                            </Link>
                        </Button>
                    ) : undefined
                }
            />

            <div className="igr:mb-4 igr:space-y-3">
                <div className="igr:flex igr:flex-wrap igr:items-center igr:gap-2">
                    <div className="igr:relative igr:min-w-0 igr:flex-1 igr:basis-64 igr:sm:max-w-md">
                        <Search className="igr:pointer-events-none igr:absolute igr:top-2.5 igr:left-3 igr:size-4 igr:text-muted-foreground" aria-hidden />
                        <Input
                            type="search"
                            className="igr:pl-9"
                            placeholder={t('contacts.search')}
                            aria-label={t('contacts.search')}
                            title={t('contacts.searchHelp')}
                            value={q}
                            onChange={(e) => setQ(e.target.value)}
                        />
                    </div>
                    <Button variant="outline" onClick={() => setSheet(true)} aria-haspopup="dialog">
                        <SlidersHorizontal />
                        {t('contacts.list.filters')}
                        {panelCount > 0 ? (
                            <Badge variant="brand" className="igr:tabular-nums">
                                {panelCount}
                            </Badge>
                        ) : null}
                    </Button>
                    <label className="igr:flex igr:items-center igr:gap-2 igr:max-sm:hidden igr:sm:ml-auto">
                        <span className="igr:text-support igr:text-muted-foreground igr:max-sm:sr-only">{t('contacts.sort.label')}</span>
                        <Select
                            className="igr:w-auto"
                            aria-label={t('contacts.sort.label')}
                            value={sorts.includes(filters.sort) ? filters.sort : 'last_seen'}
                            onChange={(e) => update({ sort: e.target.value as ContactSort })}
                        >
                            {sorts.map((s) => (
                                <option key={s} value={s}>
                                    {t(`contacts.sort.${s}`)}
                                </option>
                            ))}
                        </Select>
                    </label>
                </div>
                <ChipGroup label={t('contacts.list.quickLabel')} scroll>
                    {quick.map((qf) => (
                        <Chip key={qf} pressed={quickActive(filters, qf)} onClick={() => apply(toggleQuick(filters, qf))}>
                            {t(`contacts.quick.${qf}`)}
                        </Chip>
                    ))}
                    {extraFilters.map((key) => (
                        <Chip key={key} pressed onClick={() => update({ [key]: '' })} aria-label={t('contacts.list.removeFilter', { name: filterLabel(key) })}>
                            <span className="igr:max-w-48 igr:truncate">{filterLabel(key)}</span>
                            <X className="igr:size-3.5" aria-hidden />
                        </Chip>
                    ))}
                </ChipGroup>
            </div>

            {contacts.isError ? (
                <ErrorState error={contacts.error} onRetry={() => void contacts.refetch()} />
            ) : contacts.isPending ? (
                <Card className="igr:space-y-1 igr:p-3" aria-busy>
                    {Array.from({ length: 8 }, (_, i) => (
                        <Skeleton key={i} className="igr:h-12" />
                    ))}
                </Card>
            ) : rows.length === 0 ? (
                filtered ? (
                    <EmptyState
                        illustration="search"
                        title={t('contacts.noResults')}
                        description={t('contacts.noResultsHelp')}
                        action={
                            <Button variant="outline" onClick={clear}>
                                {t('contacts.filters.clear')}
                            </Button>
                        }
                    />
                ) : (
                    <EmptyState
                        illustration="customers"
                        title={t('contacts.empty.title', { many: terms.many })}
                        description={t(business.servicesOnly ? 'contacts.empty.descriptionServices' : 'contacts.empty.descriptionStore')}
                        action={
                            siteMissing && canConnect ? (
                                <Button asChild>
                                    <Link to={`/w/${workspace.id}/sitios?conectar=1`}>
                                        <Globe />
                                        {t('contacts.empty.connect')}
                                    </Link>
                                </Button>
                            ) : undefined
                        }
                        secondary={
                            can.edit ? (
                                <Button variant={siteMissing && canConnect ? 'outline' : 'default'} asChild>
                                    <Link to={`/w/${workspace.id}/contactos/importar`}>
                                        <FileUp />
                                        {t('contacts.list.import')}
                                    </Link>
                                </Button>
                            ) : undefined
                        }
                        steps={[t('contacts.empty.step1'), t('contacts.empty.step2', { many: terms.many }), t('contacts.empty.step3')]}
                    />
                )
            ) : (
                <Card className={contacts.isPlaceholderData ? 'igr:overflow-hidden igr:opacity-70 igr:transition-opacity' : 'igr:overflow-hidden'}>
                    <ContactRows rows={rows} business={business} selected={bulkEnabled ? chosen.ids : null} onToggle={toggle} onTogglePage={togglePage} />
                    <div className="igr:flex igr:items-center igr:justify-between igr:gap-2 igr:border-t igr:px-3 igr:py-2 igr:text-support igr:text-muted-foreground">
                        <span className="igr:tabular-nums">
                            {meta
                                ? t('contacts.list.range', {
                                      from: formatNumber((filters.page - 1) * meta.per_page + 1, workspace.locale),
                                      to: formatNumber(Math.min(filters.page * meta.per_page, meta.total), workspace.locale),
                                      total: formatNumber(meta.total, workspace.locale),
                                  })
                                : null}
                        </span>
                        <div className="igr:flex igr:gap-1">
                            <Button variant="outline" size="sm" disabled={filters.page <= 1} onClick={() => apply({ ...filters, page: filters.page - 1 })} aria-label={t('common.previous')}>
                                <ChevronLeft />
                            </Button>
                            <Button variant="outline" size="sm" disabled={filters.page >= pages} onClick={() => apply({ ...filters, page: filters.page + 1 })} aria-label={t('common.next')}>
                                <ChevronRight />
                            </Button>
                        </div>
                    </div>
                </Card>
            )}

            {selectedCount > 0 ? (
                <div
                    role="region"
                    aria-label={t('contacts.bulk.label')}
                    className="igr:sticky igr:bottom-3 igr:z-10 igr:mt-3 igr:flex igr:flex-wrap igr:items-center igr:gap-x-3 igr:gap-y-2 igr:rounded-xl igr:border igr:bg-popover igr:px-3 igr:py-2 igr:shadow-lg"
                >
                    <span className="igr:flex igr:items-center igr:gap-2 igr:text-body igr:font-medium igr:tabular-nums">
                        <UsersRound className="igr:size-4 igr:text-primary" aria-hidden />
                        {t('contacts.bulk.selected', { count: selectedCount, formatted: formatNumber(selectedCount, workspace.locale) })}
                    </span>
                    {!chosen.all && total !== null && total > rows.length && rows.every((r) => chosen.ids.has(r.id)) ? (
                        <Button variant="link" size="sm" onClick={() => setSelection({ key: viewKey, ids: chosen.ids, all: true })}>
                            {t('contacts.bulk.selectAll', { count: total, formatted: formatNumber(total, workspace.locale) })}
                        </Button>
                    ) : null}
                    <div className="igr:ml-auto igr:flex igr:flex-wrap igr:items-center igr:gap-2">
                        {can.export ? (
                            <Button variant="outline" size="sm" asChild>
                                <a href={contactsExportUrl(workspace.id, bulkSelection)} download onClick={() => toast.success(t('contacts.bulk.exporting'))}>
                                    <Download />
                                    {t('contacts.bulk.export')}
                                </a>
                            </Button>
                        ) : null}
                        {canGroup ? (
                            <Button size="sm" onClick={() => setGroupDialog(true)}>
                                <UsersRound />
                                {t('contacts.bulk.addToGroup')}
                            </Button>
                        ) : null}
                        <Button variant="ghost" size="icon" className="igr:size-8" onClick={clearSelection} aria-label={t('contacts.bulk.clear')}>
                            <X />
                        </Button>
                    </div>
                </div>
            ) : null}

            {sheet ? (
                <ContactFiltersSheet
                    open
                    onOpenChange={setSheet}
                    filters={filters}
                    onChange={(patch) => update(patch)}
                    business={business}
                    sorts={phone ? sorts : null}
                    groups={canSeeGroups ? groups : null}
                    total={total}
                    side={phone ? 'bottom' : 'right'}
                />
            ) : null}
            {groupDialog ? (
                <AddToGroupDialog open onOpenChange={setGroupDialog} selection={bulkSelection} count={selectedCount} groups={staticGroups} onDone={clearSelection} />
            ) : null}
        </>
    );
}
