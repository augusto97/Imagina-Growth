import { Gift, SlidersHorizontal } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input, Select, Textarea } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { EmptyState, ErrorState, FieldError } from '@/components/ui/misc';
import { Progress } from '@/components/ui/progress';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TBody, TD, TH, THead, TR } from '@/components/ui/table';
import { useCan, useWorkspace } from '@/hooks/useWorkspace';
import { isApiError } from '@/lib/api';
import { formatDate, formatDateTime, formatMoney, formatNumber } from '@/lib/format';
import { toast } from '@/stores/toast';
import { useAdjust, useContactLoyalty } from './api';
import { entryAmount, reasonLabel, statusTone } from './labels';
import type { Adjustment, ContactLoyalty } from './types';

/** Pestaña «Fidelización» del perfil 360: saldos, nivel, avance y el libro de movimientos. */
export function ContactLoyaltyTab({ contactId }: { contactId: string }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const allowed = useCan('viewAllContacts');
    const canAdjust = useCan('manageMarketing');
    const [page, setPage] = useState(1);
    const [adjusting, setAdjusting] = useState(false);
    const q = useContactLoyalty(workspace.id, contactId, page, allowed);

    if (!allowed) {
        return <EmptyState icon={Gift} title={t('loyalty.contact.noAccessTitle')} description={t('loyalty.contact.noAccess')} />;
    }
    if (q.isError) {
        return <ErrorState error={q.error} onRetry={() => void q.refetch()} />;
    }
    if (q.isPending) {
        return <Skeleton className="igr:h-72" />;
    }
    const d = q.data;
    if (d.program === null && d.ledger.length === 0) {
        return (
            <EmptyState
                icon={Gift}
                title={t('loyalty.contact.noProgramTitle')}
                description={t('loyalty.contact.noProgram')}
                action={
                    <Button variant="outline" asChild>
                        <Link to={`/w/${workspace.id}/fidelizacion`}>{t('loyalty.contact.setup')}</Link>
                    </Button>
                }
            />
        );
    }
    const name = d.program?.currency_name ?? t('loyalty.contact.points');
    const money = (v: string | number) => formatMoney(v, d.currency, workspace.locale);
    const tiles = [
        { key: 'points', label: name, value: formatNumber(d.balances.points, workspace.locale) },
        { key: 'pending', label: t('loyalty.contact.pending'), value: formatNumber(d.balances.pending, workspace.locale) },
        { key: 'wallet', label: t('loyalty.contact.wallet'), value: money(d.balances.wallet) },
        {
            key: 'expiring',
            label: t('loyalty.contact.expiring'),
            value: d.balances.expiring > 0 && d.balances.expiring_at ? formatNumber(d.balances.expiring, workspace.locale) : '—',
            help: d.balances.expiring > 0 && d.balances.expiring_at ? t('loyalty.contact.expiringOn', { date: formatDate(d.balances.expiring_at, workspace.locale) }) : undefined,
        },
    ];

    return (
        <div className="igr:space-y-4">
            <dl className="igr:grid igr:gap-3 igr:sm:grid-cols-2 igr:lg:grid-cols-4">
                {tiles.map((tile) => (
                    <div key={tile.key} className="igr:rounded-lg igr:border igr:bg-card igr:p-3">
                        <dt className="igr:text-xs igr:text-muted-foreground">{tile.label}</dt>
                        <dd className="igr:mt-1 igr:text-lg igr:font-semibold igr:tabular-nums">{tile.value}</dd>
                        {tile.help ? <p className="igr:text-[11.5px] igr:text-muted-foreground">{tile.help}</p> : null}
                    </div>
                ))}
            </dl>
            {d.tier ? <TierCard data={d} /> : null}
            <Card>
                <CardHeader className="igr:flex-row igr:items-start igr:justify-between igr:gap-3">
                    <div>
                        <CardTitle>{t('loyalty.contact.ledger')}</CardTitle>
                        <CardDescription>{t('loyalty.contact.ledgerHelp')}</CardDescription>
                    </div>
                    {canAdjust && d.program !== null ? (
                        <Button variant="outline" size="sm" onClick={() => setAdjusting(true)}>
                            <SlidersHorizontal />
                            {t('loyalty.contact.adjust')}
                        </Button>
                    ) : null}
                </CardHeader>
                <CardContent>
                    {d.ledger.length === 0 ? (
                        <p className="igr:py-6 igr:text-center igr:text-[13px] igr:text-muted-foreground">{t('loyalty.contact.noMovements')}</p>
                    ) : (
                        <Table>
                            <THead>
                                <TR>
                                    <TH>{t('loyalty.contact.date')}</TH>
                                    <TH>{t('loyalty.contact.movement')}</TH>
                                    <TH>{t('loyalty.contact.status')}</TH>
                                    <TH className="igr:text-right">{t('loyalty.contact.amount')}</TH>
                                    <TH className="igr:text-right">{t('loyalty.contact.balanceAfter')}</TH>
                                </TR>
                            </THead>
                            <TBody>
                                {d.ledger.map((e) => (
                                    <TR key={e.id}>
                                        <TD className="igr:text-xs igr:text-muted-foreground">{formatDateTime(e.created_at, workspace.locale, workspace.timezone)}</TD>
                                        <TD>
                                            {reasonLabel(t, e)}
                                            <span className="igr:ml-1.5 igr:text-xs igr:text-muted-foreground">{e.kind === 'wallet' ? t('loyalty.contact.walletShort') : name}</span>
                                        </TD>
                                        <TD>
                                            <Badge variant={statusTone(e.status)}>{t(`loyalty.ledgerStatus.${e.status}`)}</Badge>
                                        </TD>
                                        <TD className="igr:text-right igr:tabular-nums">{entryAmount(e, d.currency, workspace.locale)}</TD>
                                        <TD className="igr:text-right igr:tabular-nums igr:text-muted-foreground">{e.kind === 'wallet' ? money(e.balance_after) : formatNumber(Number(e.balance_after), workspace.locale)}</TD>
                                    </TR>
                                ))}
                            </TBody>
                        </Table>
                    )}
                    {d.meta.last_page > 1 ? (
                        <div className="igr:mt-3 igr:flex igr:justify-end igr:gap-2">
                            <Button variant="outline" size="sm" disabled={page <= 1} onClick={() => setPage(page - 1)}>
                                {t('loyalty.members.prev')}
                            </Button>
                            <Button variant="outline" size="sm" disabled={page >= d.meta.last_page} onClick={() => setPage(page + 1)}>
                                {t('loyalty.members.next')}
                            </Button>
                        </div>
                    ) : null}
                </CardContent>
            </Card>
            {adjusting ? <AdjustDialog contactId={contactId} data={d} onClose={() => setAdjusting(false)} /> : null}
        </div>
    );
}

function TierCard({ data }: { data: ContactLoyalty }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const tier = data.tier;
    if (tier === null) return null;
    const remaining = tier.next_threshold !== null && tier.value !== null ? Math.max(0, Number(tier.next_threshold) - tier.value) : null;
    const remainingText =
        remaining === null || tier.next_threshold_type === null
            ? null
            : tier.next_threshold_type === 'spend_12m'
              ? formatMoney(remaining, data.currency, workspace.locale)
              : tier.next_threshold_type === 'orders_12m'
                ? t('loyalty.contact.ordersCount', { count: Math.ceil(remaining) })
                : `${formatNumber(Math.ceil(remaining), workspace.locale)} ${data.program?.currency_name ?? ''}`;
    return (
        <Card>
            <CardContent className="igr:flex igr:flex-col igr:gap-3 igr:py-4 igr:md:flex-row igr:md:items-center">
                <div className="igr:min-w-40">
                    <p className="igr:text-xs igr:text-muted-foreground">{t('loyalty.contact.tier')}</p>
                    <p className="igr:text-base igr:font-semibold">{tier.name ?? t('loyalty.contact.noTier')}</p>
                    {tier.benefits.length > 0 ? <p className="igr:text-xs igr:text-muted-foreground">{tier.benefits.join(' · ')}</p> : null}
                </div>
                {tier.next_name ? (
                    <div className="igr:flex-1 igr:space-y-1">
                        <Progress value={tier.progress_pct} label={t('loyalty.contact.progressTo', { name: tier.next_name })} />
                        <p className="igr:text-xs igr:text-muted-foreground">{remainingText ? t('loyalty.contact.toNext', { remaining: remainingText, name: tier.next_name }) : null}</p>
                    </div>
                ) : (
                    <p className="igr:text-xs igr:text-muted-foreground">{t('loyalty.contact.topTier')}</p>
                )}
            </CardContent>
        </Card>
    );
}

function AdjustDialog({ contactId, data, onClose }: { contactId: string; data: ContactLoyalty; onClose: () => void }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const adjust = useAdjust(workspace.id, contactId);
    const [form, setForm] = useState<{ kind: Adjustment['kind']; direction: Adjustment['direction']; amount: string; reason: Adjustment['reason']; note: string }>({ kind: 'points', direction: 'add', amount: '', reason: 'goodwill', note: '' });
    const server = isApiError(adjust.error) ? adjust.error.fieldErrors : {};
    const valid = Number(form.amount) > 0 && form.note.trim().length >= 3;
    const submit = () =>
        adjust.mutate(
            { ...form, amount: Number(form.amount), note: form.note.trim() },
            { onSuccess: () => { toast.success(t('loyalty.contact.adjusted')); onClose(); } },
        );

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{t('loyalty.contact.adjustTitle')}</DialogTitle>
                    <DialogDescription>{t('loyalty.contact.adjustHelp')}</DialogDescription>
                </DialogHeader>
                <div className="igr:grid igr:grid-cols-2 igr:gap-3">
                    <div className="igr:space-y-1">
                        <Label htmlFor="adj-kind">{t('loyalty.contact.adjustKind')}</Label>
                        <Select id="adj-kind" value={form.kind} onChange={(e) => setForm({ ...form, kind: e.target.value === 'wallet' ? 'wallet' : 'points' })}>
                            <option value="points">{data.program?.currency_name ?? t('loyalty.contact.points')}</option>
                            <option value="wallet">{t('loyalty.contact.wallet')}</option>
                        </Select>
                    </div>
                    <div className="igr:space-y-1">
                        <Label htmlFor="adj-dir">{t('loyalty.contact.adjustDirection')}</Label>
                        <Select id="adj-dir" value={form.direction} onChange={(e) => setForm({ ...form, direction: e.target.value === 'remove' ? 'remove' : 'add' })}>
                            <option value="add">{t('loyalty.contact.add')}</option>
                            <option value="remove">{t('loyalty.contact.remove')}</option>
                        </Select>
                    </div>
                    <div className="igr:space-y-1">
                        <Label htmlFor="adj-amount">{form.kind === 'wallet' ? t('loyalty.contact.amountMoney', { currency: data.currency }) : t('loyalty.contact.amountPoints')}</Label>
                        <Input id="adj-amount" inputMode="decimal" value={form.amount} onChange={(e) => setForm({ ...form, amount: e.target.value.replace(/[^0-9.]/g, '') })} />
                        <FieldError message={server.amount} />
                    </div>
                    <div className="igr:space-y-1">
                        <Label htmlFor="adj-reason">{t('loyalty.contact.adjustReason')}</Label>
                        <Select id="adj-reason" value={form.reason} onChange={(e) => setForm({ ...form, reason: e.target.value as Adjustment['reason'] })}>
                            {(['goodwill', 'refund', 'correction', 'manual'] as const).map((r) => (
                                <option key={r} value={r}>
                                    {t(`loyalty.reasons.${r}`)}
                                </option>
                            ))}
                        </Select>
                    </div>
                    <div className="igr:col-span-2 igr:space-y-1">
                        <Label htmlFor="adj-note">{t('loyalty.contact.note')}</Label>
                        <Textarea id="adj-note" rows={2} maxLength={300} value={form.note} onChange={(e) => setForm({ ...form, note: e.target.value })} />
                        <p className="igr:text-xs igr:text-muted-foreground">{t('loyalty.contact.noteHelp')}</p>
                        <FieldError message={server.note} />
                    </div>
                </div>
                <DialogFooter>
                    <Button variant="outline" onClick={onClose}>
                        {t('common.cancel')}
                    </Button>
                    <Button onClick={submit} loading={adjust.isPending} disabled={!valid}>
                        {t('loyalty.contact.adjustSave')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
