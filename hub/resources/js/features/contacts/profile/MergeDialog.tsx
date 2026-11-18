import { ArrowLeft, GitMerge, Search } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { ErrorState } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { useDebounced } from '@/hooks/useDebounced';
import { useWorkspace } from '@/hooks/useWorkspace';
import { formatMoney, formatNumber } from '@/lib/format';
import { cn } from '@/lib/cn';
import { toast } from '@/stores/toast';
import { type ContactListRow, type ContactProfile, useContactList, useMergeContact } from '../api';
import { errorMessage } from '../errors';
import { DEFAULT_FILTERS } from '../filters';

/** Fusión manual: el perfil abierto gana y el elegido se une a él (reversible desde Identidades). */
export function MergeDialog({ profile, open, onOpenChange }: { profile: ContactProfile; open: boolean; onOpenChange: (open: boolean) => void }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const [query, setQuery] = useState('');
    const [picked, setPicked] = useState<ContactListRow | null>(null);
    const q = useDebounced(query.trim(), 250);
    const results = useContactList(workspace.id, { ...DEFAULT_FILTERS, q }, open && q !== '');
    const merge = useMergeContact(workspace.id, profile.id);
    const candidates = (results.data?.data ?? []).filter((c) => c.id !== profile.id).slice(0, 8);
    const name = (c: { name: string }) => (c.name !== '' ? c.name : t('contacts.noName'));

    const close = () => {
        setQuery('');
        setPicked(null);
        merge.reset();
        onOpenChange(false);
    };

    return (
        <Dialog open={open} onOpenChange={(next) => (next ? onOpenChange(true) : close())}>
            <DialogContent className="igr:max-w-xl">
                <DialogHeader>
                    <DialogTitle>{t('contacts.merge.title')}</DialogTitle>
                    <DialogDescription>{t('contacts.merge.description', { name: name(profile) })}</DialogDescription>
                </DialogHeader>
                {picked === null ? (
                    <>
                        <div className="igr:relative">
                            <Search className="igr:pointer-events-none igr:absolute igr:left-2.5 igr:top-2 igr:size-4 igr:text-muted-foreground" />
                            <Input
                                autoFocus
                                className="igr:pl-8"
                                placeholder={t('contacts.merge.search')}
                                aria-label={t('contacts.merge.search')}
                                value={query}
                                onChange={(e) => setQuery(e.target.value)}
                            />
                        </div>
                        <div className="igr:mt-3 igr:max-h-72 igr:overflow-y-auto" role="listbox" aria-label={t('contacts.merge.results')}>
                            {q === '' ? (
                                <p className="igr:py-6 igr:text-center igr:text-xs igr:text-muted-foreground">{t('contacts.merge.hint')}</p>
                            ) : results.isError ? (
                                <ErrorState error={results.error} />
                            ) : results.isPending || results.isPlaceholderData ? (
                                <div className="igr:space-y-2">
                                    <Skeleton className="igr:h-10" />
                                    <Skeleton className="igr:h-10" />
                                </div>
                            ) : candidates.length === 0 ? (
                                <p className="igr:py-6 igr:text-center igr:text-xs igr:text-muted-foreground">{t('contacts.merge.noResults')}</p>
                            ) : (
                                <ul className="igr:space-y-1">
                                    {candidates.map((c) => (
                                        <li key={c.id}>
                                            <button
                                                type="button"
                                                role="option"
                                                aria-selected={false}
                                                onClick={() => setPicked(c)}
                                                className="igr:flex igr:w-full igr:items-center igr:justify-between igr:gap-3 igr:rounded-md igr:px-2.5 igr:py-2 igr:text-left igr:outline-none igr:hover:bg-accent igr:focus-visible:bg-accent"
                                            >
                                                <span className="igr:min-w-0">
                                                    <span className="igr:block igr:truncate igr:text-[13px] igr:font-medium">{name(c)}</span>
                                                    <span className="igr:block igr:truncate igr:text-xs igr:text-muted-foreground">
                                                        {[c.email, c.phone, c.city].filter(Boolean).join(' · ') || '—'}
                                                    </span>
                                                </span>
                                                <span className="igr:shrink-0 igr:text-xs igr:tabular-nums igr:text-muted-foreground">
                                                    {t('contacts.merge.orders', { count: c.orders_count, formatted: formatNumber(c.orders_count, workspace.locale) })}
                                                </span>
                                            </button>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </div>
                    </>
                ) : (
                    <div className="igr:space-y-3">
                        <div className="igr:grid igr:grid-cols-[1fr_auto_1fr] igr:items-center igr:gap-3">
                            <Side title={t('contacts.merge.absorbed')} name={name(picked)} detail={[picked.email, picked.phone].filter(Boolean).join(' · ')} muted />
                            <GitMerge className="igr:size-4 igr:text-muted-foreground" aria-hidden="true" />
                            <Side
                                title={t('contacts.merge.keeps')}
                                name={name(profile)}
                                detail={formatMoney(profile.clv.historic, profile.currency, workspace.locale)}
                            />
                        </div>
                        <ul className="igr:list-disc igr:space-y-1 igr:pl-5 igr:text-[13px] igr:text-muted-foreground">
                            <li>{t('contacts.merge.point1')}</li>
                            <li>{t('contacts.merge.point2')}</li>
                            <li>{t('contacts.merge.point3')}</li>
                        </ul>
                        {merge.isError && <p className="igr:text-xs igr:text-destructive">{errorMessage(merge.error, t)}</p>}
                    </div>
                )}
                <DialogFooter>
                    {picked !== null ? (
                        <>
                            <Button variant="ghost" className="igr:mr-auto" onClick={() => setPicked(null)}>
                                <ArrowLeft />
                                {t('common.back')}
                            </Button>
                            <Button
                                loading={merge.isPending}
                                onClick={() =>
                                    merge.mutate(picked.id, {
                                        onSuccess: () => {
                                            toast.success(t('contacts.merge.done'));
                                            close();
                                        },
                                    })
                                }
                            >
                                <GitMerge />
                                {t('contacts.merge.confirm')}
                            </Button>
                        </>
                    ) : (
                        <Button variant="ghost" onClick={close}>
                            {t('common.cancel')}
                        </Button>
                    )}
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function Side({ title, name, detail, muted = false }: { title: string; name: string; detail: string; muted?: boolean }) {
    return (
        <div className={cn('igr:rounded-md igr:border igr:p-3', muted ? 'igr:bg-muted/40' : 'igr:border-primary/40 igr:bg-primary/5')}>
            <p className="igr:text-[11px] igr:font-medium igr:uppercase igr:tracking-wide igr:text-muted-foreground">{title}</p>
            <p className="igr:mt-1 igr:truncate igr:text-[13px] igr:font-medium">{name}</p>
            <p className="igr:truncate igr:text-xs igr:text-muted-foreground">{detail || '—'}</p>
        </div>
    );
}
