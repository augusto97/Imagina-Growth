import type { UseQueryResult } from '@tanstack/react-query';
import { Bot, ListFilter, Search, Sparkles, UserRoundCheck } from 'lucide-react';
import { type Dispatch, type SetStateAction, useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { Input, Select } from '@/components/ui/input';
import { ErrorState } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { useDebounced } from '@/hooks/useDebounced';
import { useWorkspace } from '@/hooks/useWorkspace';
import { cn } from '@/lib/cn';
import { formatRelative } from '@/lib/format';
import type { ListFilters } from './api';
import { ChannelIcon } from './ChannelIcon';
import { initials } from './lib';
import type { ConversationList, ConversationStatus, ConversationSummary, InboxChannel, InboxView } from './types';

const VIEWS: InboxView[] = ['all', 'mine', 'unassigned', 'ai'];

/** Lo que se ve al entrar: las abiertas de todo el equipo. */
export const DEFAULT_FILTERS: ListFilters = { view: 'all', status: 'open', q: '', label: '', intent: '', channel: '' };

/**
 * Lista vacía que dice qué hacer (docs/auditoria-2026-11.md U6): sin conversaciones abiertas, ver todas; sin ninguna,
 * cómo llegan los mensajes (WhatsApp con un clic y el botón en la web); con filtros, quitarlos.
 */
function ListEmpty({ filtered, status, onShowAll, onClear }: { filtered: boolean; status: ConversationStatus | 'all'; onShowAll: () => void; onClear: () => void }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const base = `/w/${workspace.id}`;
    if (filtered) {
        return (
            <div className="igr:space-y-3 igr:p-6 igr:text-center">
                <p className="igr:text-support igr:text-muted-foreground">{t('inbox.list.empty')}</p>
                <Button size="sm" variant="outline" onClick={onClear}>
                    {t('inbox.list.clearFilters')}
                </Button>
            </div>
        );
    }
    if (status !== 'all') {
        return (
            <div className="igr:space-y-3 igr:p-6 igr:text-center">
                <p className="igr:text-support igr:font-medium igr:text-foreground">{t('inbox.list.allDoneTitle')}</p>
                <p className="igr:text-support igr:text-muted-foreground">{t('inbox.list.emptyStatus', { status: t(`inbox.status.${status}`).toLowerCase() })}</p>
                <Button size="sm" variant="outline" onClick={onShowAll}>
                    {t('inbox.list.showAll')}
                </Button>
            </div>
        );
    }
    return (
        <EmptyState
            size="compact"
            bordered={false}
            illustration="conversations"
            title={t('inbox.list.firstTitle')}
            description={t('inbox.list.firstDescription')}
            action={
                workspace.abilities.includes('manageWorkspace') ? (
                    <Button asChild size="sm">
                        <Link to={`${base}/conexiones?tarjeta=whatsapp`}>{t('inbox.list.connectWhatsapp')}</Link>
                    </Button>
                ) : undefined
            }
            secondary={
                workspace.abilities.includes('manageMarketing') ? (
                    <Button asChild size="sm" variant="ghost">
                        <Link to={`${base}/en-el-sitio`}>{t('inbox.list.addWidget')}</Link>
                    </Button>
                ) : undefined
            }
        />
    );
}

/**
 * Lista de conversaciones: búsqueda, vistas (todas, mías, sin asignar, con IA) y, plegados, los filtros de estado,
 * intención y canal. La consulta la hace la página (`list`); aquí solo se cambian los filtros.
 */
export function ConversationListPane({
    selected,
    onSelect,
    list,
    filters,
    onFiltersChange,
}: {
    selected: string | undefined;
    onSelect: (id: string) => void;
    list: UseQueryResult<ConversationList>;
    filters: ListFilters;
    onFiltersChange: Dispatch<SetStateAction<ListFilters>>;
}) {
    const { t } = useTranslation();
    const [search, setSearch] = useState(filters.q);
    const [showFilters, setShowFilters] = useState(false);
    const q = useDebounced(search, 300);
    const set = (patch: Partial<ListFilters>) => onFiltersChange((prev) => ({ ...prev, ...patch }));
    // La búsqueda llega a los filtros (y a la API) 300 ms después de dejar de escribir.
    useEffect(() => {
        onFiltersChange((prev) => (prev.q === q ? prev : { ...prev, q }));
    }, [q, onFiltersChange]);
    const counts = list.data?.counts;
    const extra = (filters.status !== 'open' ? 1 : 0) + (filters.intent !== '' ? 1 : 0) + (filters.channel !== '' ? 1 : 0);
    // Al volver del chat (celular), el foco regresa a la conversación que se abrió.
    const listRef = useRef<HTMLDivElement>(null);
    const previous = useRef(selected);
    useEffect(() => {
        const left = previous.current;
        previous.current = selected;
        if (left !== undefined && selected === undefined) {
            listRef.current?.querySelector<HTMLElement>(`[data-conversation="${CSS.escape(left)}"]`)?.focus({ preventScroll: true });
        }
    }, [selected]);

    return (
        <div className="igr:flex igr:h-full igr:flex-col">
            {/* Flex con gap y no space-y: los filtros plegados (display: none) no dejan un hueco. */}
            <div className="igr:flex igr:flex-col igr:gap-2.5 igr:border-b igr:p-3">
                <div className="igr:flex igr:gap-2">
                    <div className="igr:relative igr:min-w-0 igr:flex-1">
                        <Search className="igr:pointer-events-none igr:absolute igr:top-2.5 igr:left-2.5 igr:size-4 igr:text-muted-foreground" aria-hidden />
                        <Input className="igr:pl-8" placeholder={t('inbox.list.search')} value={search} onChange={(e) => setSearch(e.target.value)} aria-label={t('inbox.list.search')} />
                    </div>
                    <Button
                        variant={showFilters || extra > 0 ? 'secondary' : 'outline'}
                        size="icon"
                        className="igr:relative igr:shrink-0"
                        onClick={() => setShowFilters((v) => !v)}
                        aria-expanded={showFilters}
                        aria-controls="inbox-filters"
                        aria-label={extra > 0 ? t('inbox.list.filtersActive', { count: extra }) : t('inbox.list.filters')}
                    >
                        <ListFilter aria-hidden />
                        {extra > 0 ? (
                            <span className="igr:absolute igr:-top-1 igr:-right-1 igr:flex igr:size-4 igr:items-center igr:justify-center igr:rounded-full igr:bg-primary igr:text-[10px] igr:font-semibold igr:text-primary-foreground igr:tabular-nums">
                                {extra}
                            </span>
                        ) : null}
                    </Button>
                </div>
                <div role="tablist" aria-label={t('inbox.list.views')} className="igr:scrollbar-none igr:flex igr:gap-1 igr:overflow-x-auto">
                    {VIEWS.map((v) => {
                        const count = v === 'mine' ? counts?.mine : v === 'unassigned' ? counts?.unassigned : undefined;
                        return (
                            <button
                                key={v}
                                type="button"
                                role="tab"
                                aria-selected={filters.view === v}
                                onClick={() => set({ view: v })}
                                className={cn(
                                    'igr:flex igr:h-7 igr:shrink-0 igr:items-center igr:gap-1.5 igr:rounded-full igr:px-2.5 igr:text-xs igr:whitespace-nowrap igr:outline-none igr:transition-colors igr:focus-visible:ring-2 igr:focus-visible:ring-ring',
                                    filters.view === v ? 'igr:bg-foreground igr:font-medium igr:text-background' : 'igr:text-muted-foreground igr:hover:bg-accent igr:hover:text-foreground',
                                )}
                            >
                                {t(`inbox.list.view.${v}`)}
                                {count ? <span className={cn('igr:tabular-nums', filters.view === v ? 'igr:opacity-80' : 'igr:text-foreground')}>{count}</span> : null}
                            </button>
                        );
                    })}
                </div>
                <div id="inbox-filters" className={cn('igr:grid igr:grid-cols-2 igr:gap-2', !showFilters && 'igr:hidden')}>
                    <Select value={filters.status} onChange={(e) => set({ status: e.target.value as ConversationStatus | 'all' })} aria-label={t('inbox.list.status')} className="igr:h-8 igr:text-xs">
                        {(['open', 'pending', 'closed', 'all'] as const).map((s) => (
                            <option key={s} value={s}>
                                {t(`inbox.status.${s}`)}
                            </option>
                        ))}
                    </Select>
                    <Select value={filters.intent} onChange={(e) => set({ intent: e.target.value })} aria-label={t('inbox.list.intent')} className="igr:h-8 igr:text-xs">
                        <option value="">{t('inbox.list.anyIntent')}</option>
                        {(['purchase', 'support', 'complaint', 'other'] as const).map((i) => (
                            <option key={i} value={i}>
                                {t(`inbox.intent.${i}`)}
                            </option>
                        ))}
                    </Select>
                    <Select
                        value={filters.channel}
                        onChange={(e) => set({ channel: e.target.value as InboxChannel | '' })}
                        aria-label={t('inbox.list.channel')}
                        className="igr:col-span-2 igr:h-8 igr:text-xs"
                    >
                        <option value="">{t('inbox.list.anyChannel')}</option>
                        {(['whatsapp', 'messenger', 'instagram'] as const).map((ch) => (
                            <option key={ch} value={ch}>
                                {t(`inbox.channel.${ch}`)}
                            </option>
                        ))}
                    </Select>
                </div>
            </div>
            <div ref={listRef} className="igr:min-h-0 igr:flex-1 igr:overflow-y-auto">
                {list.isError ? (
                    <div className="igr:p-3">
                        <ErrorState error={list.error} onRetry={() => void list.refetch()} />
                    </div>
                ) : list.isPending ? (
                    <div className="igr:space-y-1 igr:p-2">
                        {Array.from({ length: 7 }, (_, i) => (
                            <div key={i} className="igr:flex igr:gap-3 igr:p-2">
                                <Skeleton className="igr:size-9 igr:rounded-full" />
                                <div className="igr:flex-1 igr:space-y-2">
                                    <Skeleton className="igr:h-3.5 igr:w-2/3" />
                                    <Skeleton className="igr:h-3 igr:w-full" />
                                </div>
                            </div>
                        ))}
                    </div>
                ) : list.data.data.length === 0 ? (
                    <ListEmpty
                        filtered={filters.view !== 'all' || filters.intent !== '' || filters.channel !== '' || filters.q.trim() !== ''}
                        status={filters.status}
                        onShowAll={() => set({ status: 'all' })}
                        onClear={() => {
                            setSearch('');
                            onFiltersChange({ ...DEFAULT_FILTERS, status: 'all' });
                        }}
                    />
                ) : (
                    <ul aria-label={t('inbox.list.title')} className="igr:divide-y">
                        {list.data.data.map((c) => (
                            <li key={c.id}>
                                <Row c={c} selected={selected === c.id} onSelect={onSelect} />
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </div>
    );
}

/** Una conversación: avatar con el canal, nombre, cuándo, el último mensaje y lo que pide atención. */
function Row({ c, selected, onSelect }: { c: ConversationSummary; selected: boolean; onSelect: (id: string) => void }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const unread = c.unread_count > 0;
    return (
        <button
            type="button"
            data-conversation={c.id}
            onClick={() => onSelect(c.id)}
            aria-current={selected ? 'true' : undefined}
            className={cn(
                'igr:relative igr:flex igr:w-full igr:gap-3 igr:px-3 igr:py-3 igr:text-left igr:outline-none igr:transition-colors igr:hover:bg-accent/60 igr:focus-visible:bg-accent',
                selected && 'igr:bg-brand-soft/60 igr:hover:bg-brand-soft/70',
            )}
        >
            {selected ? <span aria-hidden className="igr:absolute igr:inset-y-2 igr:left-0 igr:w-0.5 igr:rounded-full igr:bg-primary" /> : null}
            <span aria-hidden className="igr:relative igr:mt-0.5 igr:flex igr:size-9 igr:shrink-0 igr:items-center igr:justify-center igr:rounded-full igr:bg-muted igr:text-xs igr:font-semibold igr:text-muted-foreground">
                {initials(c.contact?.name)}
                <span className="igr:absolute igr:-right-0.5 igr:-bottom-0.5 igr:flex igr:size-4 igr:items-center igr:justify-center igr:rounded-full igr:bg-card igr:ring-2 igr:ring-card">
                    <ChannelIcon channel={c.channel} className="igr:size-3" />
                </span>
            </span>
            <span className="igr:flex igr:min-w-0 igr:flex-1 igr:flex-col igr:gap-0.5">
                <span className="igr:flex igr:items-center igr:gap-1.5">
                    <span className={cn('igr:min-w-0 igr:flex-1 igr:truncate igr:text-[13px] igr:text-foreground', unread ? 'igr:font-semibold' : 'igr:font-medium')}>
                        {c.contact?.name ?? t('inbox.unknownContact')}
                    </span>
                    {c.ai_mode !== 'off' ? (
                        c.ai_mode === 'auto' ? (
                            <Bot className="igr:size-3.5 igr:shrink-0 igr:text-primary" role="img" aria-label={t('inbox.ai.autoLong')} />
                        ) : (
                            <Sparkles className="igr:size-3.5 igr:shrink-0 igr:text-primary" role="img" aria-label={t('inbox.ai.assistLong')} />
                        )
                    ) : null}
                    <span className={cn('igr:shrink-0 igr:text-[11px] igr:tabular-nums', unread ? 'igr:font-medium igr:text-primary' : 'igr:text-muted-foreground')}>
                        {formatRelative(c.last_message_at, workspace.locale)}
                    </span>
                </span>
                <span className="igr:flex igr:items-center igr:gap-1.5">
                    <span className={cn('igr:min-w-0 igr:flex-1 igr:truncate igr:text-xs', unread ? 'igr:text-foreground' : 'igr:text-muted-foreground')}>
                        {c.preview?.direction === 'out' ? `${t('inbox.list.you')}: ` : ''}
                        {c.preview?.text ?? '—'}
                    </span>
                    {unread ? (
                        <span
                            className="igr:min-w-4.5 igr:rounded-full igr:bg-primary igr:px-1.5 igr:text-center igr:text-[10px] igr:leading-4.5 igr:font-semibold igr:text-primary-foreground igr:tabular-nums"
                            aria-label={t('inbox.list.unread', { count: c.unread_count })}
                        >
                            {c.unread_count}
                        </span>
                    ) : null}
                </span>
                {c.intent || c.labels.length > 0 || c.handoff_reason || c.assignee ? (
                    <span className="igr:flex igr:flex-wrap igr:items-center igr:gap-1 igr:pt-1">
                        {c.handoff_reason ? <Badge variant="warning">{t('inbox.handoff.badge')}</Badge> : null}
                        {c.intent && c.intent !== 'other' ? (
                            <Badge variant={c.intent === 'complaint' || c.intent === 'optout' ? 'destructive' : 'secondary'}>{t(`inbox.intent.${c.intent}`)}</Badge>
                        ) : null}
                        {c.labels.slice(0, 2).map((l) => (
                            <Badge key={l} variant="outline">
                                {l}
                            </Badge>
                        ))}
                        {c.assignee?.name ? (
                            <span className="igr:inline-flex igr:min-w-0 igr:items-center igr:gap-1 igr:text-[11px] igr:text-muted-foreground">
                                <UserRoundCheck className="igr:size-3 igr:shrink-0" aria-hidden />
                                <span className="igr:truncate">{c.assignee.name}</span>
                            </span>
                        ) : null}
                    </span>
                ) : null}
            </span>
        </button>
    );
}
