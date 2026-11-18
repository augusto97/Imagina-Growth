import { Ban, FileUp, Plus, Search } from 'lucide-react';
import { type SyntheticEvent, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input, Select } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { EmptyState, ErrorState, FieldError } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TBody, TD, TH, THead, TR } from '@/components/ui/table';
import { useCan, useWorkspace } from '@/hooks/useWorkspace';
import { isApiError } from '@/lib/api';
import { cn } from '@/lib/cn';
import { formatDate, formatNumber } from '@/lib/format';
import { toast } from '@/stores/toast';
import { type ChannelKind, type Suppression, useSuppressionMutations, useSuppressions } from './api';
import { fieldError } from './shared';

const REASONS: Suppression['reason'][] = ['bounce', 'complaint', 'unsubscribe', 'rne', 'repep', 'manual', 'wa_block'];

export function SuppressionsTab() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const canAdmin = useCan('manageWorkspace');
    const [params, setParams] = useState({ q: '', channel: '', reason: '', page: 1 });
    const [search, setSearch] = useState('');
    const list = useSuppressions(workspace.id, params);
    const m = useSuppressionMutations(workspace.id);
    const [adding, setAdding] = useState(false);
    const [value, setValue] = useState({ channel: 'email' as ChannelKind, value: '' });
    const [registry, setRegistry] = useState<{ country: 'CO' | 'MX'; file: File | null }>({ country: (workspace.country === 'MX' ? 'MX' : 'CO'), file: null });

    const add = (e: SyntheticEvent<HTMLFormElement>) => {
        e.preventDefault();
        m.add.mutate(value, {
            onSuccess: (r) => {
                toast.success(r.added ? t('channels.suppressions.added') : t('channels.suppressions.exists'));
                setAdding(false);
                setValue({ ...value, value: '' });
            },
        });
    };
    const importRegistry = (e: SyntheticEvent<HTMLFormElement>) => {
        e.preventDefault();
        if (!registry.file) return;
        m.registry.mutate(
            { country: registry.country, file: registry.file },
            {
                onSuccess: (r) => {
                    toast.success(t('channels.suppressions.imported', { rows: formatNumber(r.rows), added: formatNumber(r.added) }));
                    setRegistry({ ...registry, file: null });
                },
                onError: (err) => toast.error(t('channels.suppressions.registryTitle'), isApiError(err) ? err.message : undefined),
            },
        );
    };

    return (
        <div className="igr:grid igr:gap-4 igr:lg:grid-cols-[1fr_320px]">
            <div className="igr:space-y-3">
                <form
                    className="igr:flex igr:flex-wrap igr:gap-2"
                    onSubmit={(e) => {
                        e.preventDefault();
                        setParams({ ...params, q: search.trim(), page: 1 });
                    }}
                >
                    <div className="igr:relative igr:min-w-56 igr:flex-1">
                        <Search className="igr:pointer-events-none igr:absolute igr:top-2 igr:left-2.5 igr:size-4 igr:text-muted-foreground" aria-hidden="true" />
                        <Input aria-label={t('channels.suppressions.search')} placeholder={t('channels.suppressions.search')} className="igr:pl-8" value={search} onChange={(e) => setSearch(e.target.value)} />
                    </div>
                    <Select aria-label={t('channels.suppressions.channel')} value={params.channel} onChange={(e) => setParams({ ...params, channel: e.target.value, page: 1 })} className="igr:w-auto">
                        <option value="">{t('channels.suppressions.allChannels')}</option>
                        {(['email', 'whatsapp', 'sms'] as const).map((c) => (
                            <option key={c} value={c}>
                                {t(`channels.tabs.${c}`)}
                            </option>
                        ))}
                    </Select>
                    <Select aria-label={t('channels.suppressions.reason')} value={params.reason} onChange={(e) => setParams({ ...params, reason: e.target.value, page: 1 })} className="igr:w-auto">
                        <option value="">{t('channels.suppressions.allReasons')}</option>
                        {REASONS.map((r) => (
                            <option key={r} value={r}>
                                {t(`channels.suppressions.reasons.${r}`)}
                            </option>
                        ))}
                    </Select>
                    <Button type="button" onClick={() => setAdding(true)}>
                        <Plus />
                        {t('channels.suppressions.add')}
                    </Button>
                </form>
                {list.isError ? (
                    <ErrorState error={list.error} onRetry={() => void list.refetch()} />
                ) : list.isPending ? (
                    <Skeleton className="igr:h-48" />
                ) : list.data.data.length === 0 ? (
                    <EmptyState icon={Ban} title={t('channels.suppressions.empty')} description={t('channels.suppressions.emptyHelp')} />
                ) : (
                    <div className="igr:overflow-x-auto igr:rounded-lg igr:border">
                        <Table>
                            <THead>
                                <TR>
                                    <TH>{t('channels.suppressions.contact')}</TH>
                                    <TH>{t('channels.suppressions.channel')}</TH>
                                    <TH>{t('channels.suppressions.reason')}</TH>
                                    <TH>{t('channels.suppressions.date')}</TH>
                                    <TH className="igr:sr-only">{t('channels.suppressions.remove')}</TH>
                                </TR>
                            </THead>
                            <TBody>
                                {list.data.data.map((s) => (
                                    <TR key={s.id}>
                                        <TD>
                                            {s.contact ? (
                                                <Link className="igr:hover:underline" to={`/w/${workspace.id}/contactos/${s.contact.id}`}>
                                                    <span className="igr:block igr:text-[13px] igr:font-medium">{s.contact.name}</span>
                                                    <span className="igr:block igr:text-xs igr:text-muted-foreground">{s.contact.value}</span>
                                                </Link>
                                            ) : (
                                                <span className="igr:text-xs igr:text-muted-foreground">{t('channels.suppressions.protected')}</span>
                                            )}
                                        </TD>
                                        <TD>{t(`channels.tabs.${s.channel}`)}</TD>
                                        <TD>
                                            <Badge variant={s.reason === 'complaint' || s.reason === 'bounce' ? 'destructive' : 'secondary'}>{t(`channels.suppressions.reasons.${s.reason}`)}</Badge>
                                        </TD>
                                        <TD className="igr:whitespace-nowrap igr:text-muted-foreground">{formatDate(s.created_at, workspace.locale, workspace.timezone)}</TD>
                                        <TD className="igr:text-right">
                                            {canAdmin && (
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    disabled={s.reason === 'complaint'}
                                                    title={s.reason === 'complaint' ? t('channels.suppressions.complaintLocked') : undefined}
                                                    onClick={() => m.remove.mutate(s.id, { onSuccess: () => toast.success(t('channels.suppressions.removed')) })}
                                                >
                                                    {t('channels.suppressions.remove')}
                                                </Button>
                                            )}
                                        </TD>
                                    </TR>
                                ))}
                            </TBody>
                        </Table>
                    </div>
                )}
                {list.data && list.data.meta.last_page > 1 && (
                    <div className="igr:flex igr:justify-end igr:gap-2">
                        <Button variant="outline" size="sm" disabled={params.page <= 1} onClick={() => setParams({ ...params, page: params.page - 1 })}>
                            {t('common.previous')}
                        </Button>
                        <Button variant="outline" size="sm" disabled={params.page >= list.data.meta.last_page} onClick={() => setParams({ ...params, page: params.page + 1 })}>
                            {t('common.next')}
                        </Button>
                    </div>
                )}
            </div>
            {canAdmin && (
                <Card>
                    <CardHeader>
                        <CardTitle className="igr:flex igr:items-center igr:gap-2">
                            <FileUp className="igr:size-4 igr:text-muted-foreground" aria-hidden="true" />
                            {t('channels.suppressions.registryTitle')}
                        </CardTitle>
                        <CardDescription>{t('channels.suppressions.registryHelp')}</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <form onSubmit={importRegistry} className="igr:space-y-3">
                            <div className="igr:space-y-1">
                                <Label htmlFor="rne-country">{t('channels.suppressions.country')}</Label>
                                <Select id="rne-country" value={registry.country} onChange={(e) => setRegistry({ ...registry, country: e.target.value as 'CO' | 'MX' })}>
                                    <option value="CO">{t('channels.suppressions.reasons.rne')}</option>
                                    <option value="MX">{t('channels.suppressions.reasons.repep')}</option>
                                </Select>
                            </div>
                            <div className="igr:space-y-1">
                                <span className="igr:text-[13px] igr:font-medium">{t('channels.suppressions.file')}</span>
                                {/* Selector propio: el control nativo del navegador sale en inglés. */}
                                <label className="igr:flex igr:cursor-pointer igr:items-center igr:gap-2 igr:rounded-md igr:border igr:border-dashed igr:px-3 igr:py-2 igr:text-[13px] igr:hover:bg-muted/50 igr:focus-within:ring-2 igr:focus-within:ring-ring">
                                    <FileUp className="igr:size-4 igr:shrink-0 igr:text-muted-foreground" aria-hidden="true" />
                                    <span className={cn('igr:truncate', !registry.file && 'igr:text-muted-foreground')}>{registry.file?.name ?? t('channels.suppressions.chooseFile')}</span>
                                    <input type="file" accept=".csv,.txt,text/csv,text/plain" className="igr:sr-only" onChange={(e) => setRegistry({ ...registry, file: e.target.files?.[0] ?? null })} />
                                </label>
                            </div>
                            <Button type="submit" loading={m.registry.isPending} disabled={!registry.file}>
                                {t('channels.suppressions.import')}
                            </Button>
                        </form>
                        {list.data && list.data.imports.length > 0 && (
                            <div className="igr:mt-4 igr:space-y-1">
                                <h4 className="igr:text-xs igr:font-semibold igr:text-muted-foreground">{t('channels.suppressions.lastImports')}</h4>
                                <ul className="igr:space-y-1 igr:text-xs">
                                    {list.data.imports.map((i) => (
                                        <li key={i.imported_at} className="igr:flex igr:justify-between igr:gap-2">
                                            <span>{t(`channels.suppressions.reasons.${i.country === 'CO' ? 'rne' : 'repep'}`)} · {formatDate(i.imported_at, workspace.locale, workspace.timezone)}</span>
                                            <span className="igr:text-muted-foreground">{t('channels.suppressions.imported', { rows: formatNumber(i.rows), added: formatNumber(i.added) })}</span>
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        )}
                    </CardContent>
                </Card>
            )}
            <Dialog open={adding} onOpenChange={setAdding}>
                <DialogContent className="igr:max-w-md">
                    <DialogHeader>
                        <DialogTitle>{t('channels.suppressions.addTitle')}</DialogTitle>
                    </DialogHeader>
                    <form id="igr-supp-form" onSubmit={add} className="igr:grid igr:gap-3">
                        <div className="igr:space-y-1">
                            <Label htmlFor="sup-channel">{t('channels.suppressions.channel')}</Label>
                            <Select id="sup-channel" value={value.channel} onChange={(e) => setValue({ ...value, channel: e.target.value as ChannelKind })}>
                                {(['email', 'whatsapp', 'sms'] as const).map((c) => (
                                    <option key={c} value={c}>
                                        {t(`channels.tabs.${c}`)}
                                    </option>
                                ))}
                            </Select>
                        </div>
                        <div className="igr:space-y-1">
                            <Label htmlFor="sup-value">{t('channels.suppressions.value')}</Label>
                            <Input id="sup-value" required value={value.value} onChange={(e) => setValue({ ...value, value: e.target.value })} />
                            <FieldError message={fieldError(m.add.error, 'value')} />
                        </div>
                    </form>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setAdding(false)}>
                            {t('common.cancel')}
                        </Button>
                        <Button type="submit" form="igr-supp-form" loading={m.add.isPending}>
                            {t('channels.suppressions.add')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}
