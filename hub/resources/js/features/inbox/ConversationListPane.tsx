import { Bot, Search, Sparkles } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Badge } from '@/components/ui/badge';
import { Input, Select } from '@/components/ui/input';
import { ErrorState } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { useDebounced } from '@/hooks/useDebounced';
import { useWorkspace } from '@/hooks/useWorkspace';
import { cn } from '@/lib/cn';
import { formatRelative } from '@/lib/format';
import { type ListFilters, useConversations } from './api';
import type { ConversationStatus, InboxView } from './types';

const VIEWS: InboxView[] = ['all', 'mine', 'unassigned', 'ai'];

/** Lista de conversaciones con vistas (todas, mías, sin asignar, con IA), estado, intención y búsqueda. */
export function ConversationListPane({ selected, onSelect }: { selected: string | undefined; onSelect: (id: string) => void }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const [view, setView] = useState<InboxView>('all');
    const [status, setStatus] = useState<ConversationStatus | 'all'>('open');
    const [intent, setIntent] = useState('');
    const [search, setSearch] = useState('');
    const q = useDebounced(search, 300);
    const filters: ListFilters = { view, status, q, label: '', intent };
    const list = useConversations(workspace.id, filters);
    const counts = list.data?.counts;

    return (
        <div className="igr:flex igr:h-full igr:flex-col">
            <div className="igr:space-y-2 igr:border-b igr:p-2.5">
                <div className="igr:relative">
                    <Search className="igr:pointer-events-none igr:absolute igr:left-2 igr:top-2 igr:size-4 igr:text-muted-foreground" aria-hidden />
                    <Input
                        className="igr:pl-8"
                        placeholder={t('inbox.list.search')}
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        aria-label={t('inbox.list.search')}
                    />
                </div>
                <div role="tablist" aria-label={t('inbox.list.views')} className="igr:flex igr:gap-1 igr:overflow-x-auto">
                    {VIEWS.map((v) => (
                        <button
                            key={v}
                            type="button"
                            role="tab"
                            aria-selected={view === v}
                            onClick={() => setView(v)}
                            className={cn(
                                'igr:flex igr:shrink-0 igr:items-center igr:gap-1 igr:whitespace-nowrap igr:rounded-md igr:px-2 igr:py-1 igr:text-xs igr:outline-none igr:focus-visible:ring-2 igr:focus-visible:ring-ring',
                                view === v ? 'igr:bg-accent igr:font-medium' : 'igr:text-muted-foreground igr:hover:text-foreground',
                            )}
                        >
                            {t(`inbox.list.view.${v}`)}
                            {v === 'mine' && counts?.mine ? <span className="igr:tabular-nums">{counts.mine}</span> : null}
                            {v === 'unassigned' && counts?.unassigned ? <span className="igr:tabular-nums">{counts.unassigned}</span> : null}
                        </button>
                    ))}
                </div>
                <div className="igr:flex igr:gap-1.5">
                    <Select
                        value={status}
                        onChange={(e) => setStatus(e.target.value as ConversationStatus | 'all')}
                        aria-label={t('inbox.list.status')}
                        className="igr:h-7 igr:text-xs"
                    >
                        {(['open', 'pending', 'closed', 'all'] as const).map((s) => (
                            <option key={s} value={s}>
                                {t(`inbox.status.${s}`)}
                            </option>
                        ))}
                    </Select>
                    <Select value={intent} onChange={(e) => setIntent(e.target.value)} aria-label={t('inbox.list.intent')} className="igr:h-7 igr:text-xs">
                        <option value="">{t('inbox.list.anyIntent')}</option>
                        {(['purchase', 'support', 'complaint', 'other'] as const).map((i) => (
                            <option key={i} value={i}>
                                {t(`inbox.intent.${i}`)}
                            </option>
                        ))}
                    </Select>
                </div>
            </div>
            <div className="igr:min-h-0 igr:flex-1 igr:overflow-y-auto">
                {list.isError ? (
                    <div className="igr:p-3">
                        <ErrorState error={list.error} onRetry={() => void list.refetch()} />
                    </div>
                ) : list.isPending ? (
                    <div className="igr:space-y-2 igr:p-3">
                        {Array.from({ length: 6 }, (_, i) => (
                            <Skeleton key={i} className="igr:h-12" />
                        ))}
                    </div>
                ) : list.data.data.length === 0 ? (
                    <p className="igr:p-6 igr:text-center igr:text-xs igr:text-muted-foreground">{t('inbox.list.empty')}</p>
                ) : (
                    <ul aria-label={t('inbox.list.title')}>
                        {list.data.data.map((c) => (
                            <li key={c.id}>
                                <button
                                    type="button"
                                    onClick={() => onSelect(c.id)}
                                    aria-current={selected === c.id ? 'true' : undefined}
                                    className={cn(
                                        'igr:flex igr:w-full igr:flex-col igr:gap-0.5 igr:border-b igr:px-3 igr:py-2.5 igr:text-left igr:outline-none igr:hover:bg-accent/60 igr:focus-visible:bg-accent',
                                        selected === c.id && 'igr:bg-accent',
                                    )}
                                >
                                    <span className="igr:flex igr:items-center igr:gap-1.5">
                                        <span className={cn('igr:min-w-0 igr:flex-1 igr:truncate igr:text-[13px]', c.unread_count > 0 ? 'igr:font-semibold' : 'igr:font-medium')}>
                                            {c.contact?.name ?? t('inbox.unknownContact')}
                                        </span>
                                        {c.ai_mode !== 'off' ? (
                                            c.ai_mode === 'auto' ? (
                                                <Bot className="igr:size-3.5 igr:text-primary" aria-label={t('inbox.ai.auto')} />
                                            ) : (
                                                <Sparkles className="igr:size-3.5 igr:text-primary" aria-label={t('inbox.ai.assist')} />
                                            )
                                        ) : null}
                                        <span className="igr:shrink-0 igr:text-[11px] igr:text-muted-foreground">{formatRelative(c.last_message_at, workspace.locale)}</span>
                                    </span>
                                    <span className="igr:flex igr:items-center igr:gap-1.5">
                                        <span className="igr:min-w-0 igr:flex-1 igr:truncate igr:text-xs igr:text-muted-foreground">
                                            {c.preview?.direction === 'out' ? `${t('inbox.list.you')}: ` : ''}
                                            {c.preview?.text ?? '—'}
                                        </span>
                                        {c.unread_count > 0 ? (
                                            <span
                                                className="igr:rounded-full igr:bg-primary igr:px-1.5 igr:text-[10px] igr:font-semibold igr:leading-4 igr:text-primary-foreground igr:tabular-nums"
                                                aria-label={t('inbox.list.unread', { count: c.unread_count })}
                                            >
                                                {c.unread_count}
                                            </span>
                                        ) : null}
                                    </span>
                                    {c.intent || c.labels.length > 0 || c.handoff_reason ? (
                                        <span className="igr:flex igr:flex-wrap igr:gap-1 igr:pt-0.5">
                                            {c.intent && c.intent !== 'other' ? (
                                                <Badge variant={c.intent === 'complaint' || c.intent === 'optout' ? 'destructive' : 'secondary'}>
                                                    {t(`inbox.intent.${c.intent}`)}
                                                </Badge>
                                            ) : null}
                                            {c.handoff_reason ? <Badge variant="warning">{t('inbox.handoff.badge')}</Badge> : null}
                                            {c.labels.slice(0, 2).map((l) => (
                                                <Badge key={l} variant="outline">
                                                    {l}
                                                </Badge>
                                            ))}
                                        </span>
                                    ) : null}
                                </button>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </div>
    );
}
