import { ChevronLeft, ChevronRight, FileUp, Search, Users, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useNavigate, useSearchParams } from 'react-router';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input, Select } from '@/components/ui/input';
import { EmptyState, ErrorState, PageHeader } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TBody, TD, TH, THead, TR } from '@/components/ui/table';
import { useDebounced } from '@/hooks/useDebounced';
import { useWorkspace } from '@/hooks/useWorkspace';
import { formatDate, formatMoney, formatNumber } from '@/lib/format';
import { answerLabel, useHdyhauSurvey } from '@/features/survey/api';
import { useContactAbilities } from './abilities';
import { type ContactFilters, useContactList } from './api';
import { DEFAULT_FILTERS, hasActiveFilters, readFilters, writeFilters } from './filters';
import { CONSENT_CHANNELS, LIFECYCLE_STAGES, RFM_SEGMENTS, rfmTone } from './labels';

export function ContactsPage() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const can = useContactAbilities();
    const navigate = useNavigate();
    const [params, setParams] = useSearchParams();
    const filters = readFilters(params);
    const contacts = useContactList(workspace.id, filters);
    const survey = useHdyhauSurvey(workspace.id, can.viewDashboards);
    const meta = contacts.data?.meta;
    const pages = meta ? Math.max(1, Math.ceil(meta.total / meta.per_page)) : 1;

    // Los campos de texto se escriben al instante y llegan a la URL con un pequeño retraso.
    const [draft, setDraft] = useState({ q: filters.q, city: filters.city, tag: filters.tag });
    const debounced = useDebounced(draft, 300);
    useEffect(() => {
        const current = readFilters(params);
        if (debounced.q.trim() !== current.q || debounced.city.trim() !== current.city || debounced.tag.trim() !== current.tag) {
            setParams(writeFilters({ ...current, q: debounced.q.trim(), city: debounced.city.trim(), tag: debounced.tag.trim(), page: 1 }), { replace: true });
        }
        // Solo reacciona a lo escrito; params se lee fresco dentro.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [debounced]);

    const update = (patch: Partial<ContactFilters>) => setParams(writeFilters({ ...filters, page: 1, ...patch }), { replace: true });
    const clear = () => {
        setDraft({ q: '', city: '', tag: '' });
        setParams(writeFilters({ ...DEFAULT_FILTERS, sort: filters.sort }), { replace: true });
    };
    const filtered = hasActiveFilters(filters);
    const open = (id: string) => void navigate(`/w/${workspace.id}/contactos/${id}`);

    return (
        <>
            <PageHeader
                title={t('contacts.title')}
                description={t('contacts.description')}
                meta={
                    meta ? (
                        <Badge variant="secondary" className="igr:tabular-nums">
                            {t('contacts.count', { count: meta.total, formatted: formatNumber(meta.total, workspace.locale) })}
                        </Badge>
                    ) : undefined
                }
                actions={
                    can.edit ? (
                        <Button variant="outline" asChild>
                            <Link to={`/w/${workspace.id}/contactos/importar`}>
                                <FileUp />
                                {t('contacts.import.button')}
                            </Link>
                        </Button>
                    ) : undefined
                }
            />

            <div className="igr:mb-3 igr:flex igr:flex-wrap igr:items-center igr:gap-2">
                <div className="igr:relative igr:w-full igr:max-w-xs">
                    <Search className="igr:pointer-events-none igr:absolute igr:left-2.5 igr:top-2 igr:size-4 igr:text-muted-foreground" />
                    <Input
                        className="igr:pl-8"
                        placeholder={t('contacts.search')}
                        aria-label={t('contacts.search')}
                        title={t('contacts.searchHelp')}
                        value={draft.q}
                        onChange={(e) => setDraft({ ...draft, q: e.target.value })}
                    />
                </div>
                <Select aria-label={t('contacts.filters.stage')} className="igr:w-auto" value={filters.stage} onChange={(e) => update({ stage: e.target.value })}>
                    <option value="">{t('contacts.filters.anyStage')}</option>
                    {LIFECYCLE_STAGES.map((s) => (
                        <option key={s} value={s}>
                            {t(`stages.${s}`)}
                        </option>
                    ))}
                </Select>
                <Select aria-label={t('contacts.filters.rfm')} className="igr:w-auto" value={filters.rfm_segment} onChange={(e) => update({ rfm_segment: e.target.value })}>
                    <option value="">{t('contacts.filters.anyRfm')}</option>
                    {RFM_SEGMENTS.map((s) => (
                        <option key={s} value={s}>
                            {t(`contacts.rfm.${s}`)}
                        </option>
                    ))}
                </Select>
                <Select
                    aria-label={t('contacts.filters.consent')}
                    className="igr:w-auto"
                    value={filters.consent}
                    onChange={(e) => update({ consent: e.target.value as ContactFilters['consent'] })}
                >
                    <option value="">{t('contacts.filters.anyConsent')}</option>
                    {CONSENT_CHANNELS.map((c) => (
                        <option key={c} value={c}>
                            {t('contacts.filters.consentTo', { channel: t(`contacts.consentChannels.${c}`) })}
                        </option>
                    ))}
                </Select>
                <Select
                    aria-label={t('contacts.filters.buyers')}
                    className="igr:w-auto"
                    value={filters.buyers}
                    onChange={(e) => update({ buyers: e.target.value as ContactFilters['buyers'] })}
                >
                    <option value="">{t('contacts.filters.anyBuyer')}</option>
                    <option value="1">{t('contacts.filters.buyersOnly')}</option>
                    <option value="0">{t('contacts.filters.nonBuyers')}</option>
                </Select>
                <Input
                    className="igr:w-36"
                    placeholder={t('contacts.filters.city')}
                    aria-label={t('contacts.filters.city')}
                    value={draft.city}
                    onChange={(e) => setDraft({ ...draft, city: e.target.value })}
                />
                <Input
                    className="igr:w-32"
                    placeholder={t('contacts.filters.tag')}
                    aria-label={t('contacts.filters.tag')}
                    value={draft.tag}
                    onChange={(e) => setDraft({ ...draft, tag: e.target.value })}
                />
                {filtered && (
                    <Button variant="ghost" size="sm" onClick={clear}>
                        <X />
                        {t('contacts.filters.clear')}
                    </Button>
                )}
                <div className="igr:ml-auto igr:flex igr:items-center igr:gap-2">
                    <label htmlFor="contacts-sort" className="igr:text-xs igr:text-muted-foreground">
                        {t('contacts.sort.label')}
                    </label>
                    <Select id="contacts-sort" className="igr:w-auto" value={filters.sort} onChange={(e) => update({ sort: e.target.value as ContactFilters['sort'] })}>
                        {(['last_seen', 'last_order', 'revenue', 'orders', 'created'] as const).map((s) => (
                            <option key={s} value={s}>
                                {t(`contacts.sort.${s}`)}
                            </option>
                        ))}
                    </Select>
                </div>
            </div>

            {contacts.isError ? (
                <ErrorState error={contacts.error} onRetry={() => void contacts.refetch()} />
            ) : contacts.isPending ? (
                <Card className="igr:space-y-2 igr:p-3">
                    {Array.from({ length: 8 }, (_, i) => (
                        <Skeleton key={i} className="igr:h-9" />
                    ))}
                </Card>
            ) : contacts.data.data.length === 0 ? (
                filtered ? (
                    <EmptyState
                        icon={Search}
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
                        icon={Users}
                        title={t('contacts.emptyTitle')}
                        description={t('contacts.emptyDescription')}
                        action={
                            can.edit ? (
                                <Button asChild>
                                    <Link to={`/w/${workspace.id}/contactos/importar`}>
                                        <FileUp />
                                        {t('contacts.import.button')}
                                    </Link>
                                </Button>
                            ) : undefined
                        }
                    />
                )
            ) : (
                <Card className={contacts.isPlaceholderData ? 'igr:opacity-70 igr:transition-opacity' : undefined}>
                    <Table>
                        <THead>
                            <TR className="igr:hover:bg-transparent">
                                <TH>{t('contacts.columns.person')}</TH>
                                <TH className="igr:hidden igr:md:table-cell">{t('contacts.columns.city')}</TH>
                                <TH>{t('contacts.columns.stage')}</TH>
                                <TH>{t('contacts.columns.rfm')}</TH>
                                <TH className="igr:text-right">{t('contacts.columns.orders')}</TH>
                                <TH className="igr:text-right">{t('contacts.columns.revenue')}</TH>
                                <TH className="igr:hidden igr:whitespace-nowrap igr:text-right igr:lg:table-cell">{t('contacts.columns.lastOrder')}</TH>
                                <TH className="igr:hidden igr:xl:table-cell">{t('contacts.columns.hdyhau')}</TH>
                                <TH className="igr:hidden igr:xl:table-cell">{t('contacts.columns.tags')}</TH>
                            </TR>
                        </THead>
                        <TBody>
                            {contacts.data.data.map((c) => (
                                <TR key={c.id} className="igr:cursor-pointer" onClick={() => open(c.id)}>
                                    <TD className="igr:max-w-72">
                                        <Link
                                            to={`/w/${workspace.id}/contactos/${c.id}`}
                                            className="igr:font-medium igr:outline-none igr:hover:underline igr:focus-visible:underline"
                                            onClick={(e) => e.stopPropagation()}
                                        >
                                            {c.name !== '' ? c.name : t('contacts.noName')}
                                        </Link>
                                        <p className="igr:truncate igr:text-xs igr:text-muted-foreground" title={[c.email, c.phone].filter(Boolean).join(' · ')}>
                                            {[c.email, c.phone].filter(Boolean).join(' · ') || '—'}
                                        </p>
                                    </TD>
                                    <TD className="igr:hidden igr:text-muted-foreground igr:md:table-cell">{c.city ?? '—'}</TD>
                                    <TD>{c.lifecycle_stage ? <Badge variant="secondary">{t(`stages.${c.lifecycle_stage}`, { defaultValue: c.lifecycle_stage })}</Badge> : '—'}</TD>
                                    <TD>
                                        {c.rfm_segment ? (
                                            <Badge variant={rfmTone(c.rfm_segment)}>{t(`contacts.rfm.${c.rfm_segment}`, { defaultValue: c.rfm_segment })}</Badge>
                                        ) : (
                                            <span className="igr:text-muted-foreground">—</span>
                                        )}
                                    </TD>
                                    <TD className="igr:text-right igr:tabular-nums">{formatNumber(c.orders_count, workspace.locale)}</TD>
                                    <TD className="igr:whitespace-nowrap igr:text-right igr:tabular-nums">
                                        {formatMoney(c.revenue_total_ws, workspace.currency, workspace.locale)}
                                    </TD>
                                    <TD className="igr:hidden igr:whitespace-nowrap igr:text-right igr:text-muted-foreground igr:lg:table-cell">
                                        {formatDate(c.last_order_at, workspace.locale, workspace.timezone)}
                                    </TD>
                                    <TD className="igr:hidden igr:text-muted-foreground igr:xl:table-cell">{answerLabel(survey.data, c.hdyhau) ?? '—'}</TD>
                                    <TD className="igr:hidden igr:xl:table-cell">
                                        <div className="igr:flex igr:max-w-48 igr:flex-wrap igr:gap-1">
                                            {c.tags.slice(0, 3).map((tag) => (
                                                <Badge key={tag} variant="outline">
                                                    {tag}
                                                </Badge>
                                            ))}
                                            {c.tags.length > 3 && <span className="igr:text-xs igr:text-muted-foreground">+{c.tags.length - 3}</span>}
                                        </div>
                                    </TD>
                                </TR>
                            ))}
                        </TBody>
                    </Table>
                    <div className="igr:flex igr:items-center igr:justify-between igr:border-t igr:px-3 igr:py-2 igr:text-xs igr:text-muted-foreground">
                        <span>{t('contacts.page', { page: filters.page, pages })}</span>
                        <div className="igr:flex igr:gap-1">
                            <Button
                                variant="outline"
                                size="sm"
                                disabled={filters.page <= 1}
                                onClick={() => setParams(writeFilters({ ...filters, page: filters.page - 1 }))}
                                aria-label={t('common.previous')}
                            >
                                <ChevronLeft />
                            </Button>
                            <Button
                                variant="outline"
                                size="sm"
                                disabled={filters.page >= pages}
                                onClick={() => setParams(writeFilters({ ...filters, page: filters.page + 1 }))}
                                aria-label={t('common.next')}
                            >
                                <ChevronRight />
                            </Button>
                        </div>
                    </div>
                </Card>
            )}
        </>
    );
}
