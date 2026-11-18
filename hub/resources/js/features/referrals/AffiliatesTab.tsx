import { Archive, KeyRound, Megaphone, Pencil, Plus } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { EmptyState, ErrorState } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TBody, TD, TH, THead, TR } from '@/components/ui/table';
import { useCan, useWorkspace } from '@/hooks/useWorkspace';
import { formatMoney } from '@/lib/format';
import { toast } from '@/stores/toast';
import { AffiliateDialog } from './AffiliateDialog';
import { useAffiliateActions, useAffiliates } from './api';
import { PortalLinkDialog } from './PortalLinkDialog';
import type { Affiliate } from './types';

/** Afiliados, influencers y aliados: cupón y enlace propios, comisión y su panel con enlace mágico. */
export function AffiliatesTab() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const canManage = useCan('manageMarketing');
    const affiliates = useAffiliates(workspace.id);
    const { archive } = useAffiliateActions(workspace.id);
    const [editing, setEditing] = useState<Affiliate | 'new' | null>(null);
    const [portal, setPortal] = useState<Affiliate | null>(null);
    const [archiving, setArchiving] = useState<Affiliate | null>(null);
    const money = (v: string | undefined) => formatMoney(v ?? 0, workspace.currency, workspace.locale);

    if (affiliates.isPending) {
        return <Skeleton className="igr:h-48" />;
    }
    if (affiliates.isError) {
        return <ErrorState error={affiliates.error} onRetry={() => void affiliates.refetch()} />;
    }
    const list = affiliates.data;

    return (
        <div className="igr:space-y-3">
            {list.length === 0 ? (
                <EmptyState
                    icon={Megaphone}
                    title={t('referrals.affiliates.emptyTitle')}
                    description={t('referrals.affiliates.emptyDescription')}
                    action={canManage ? <Button onClick={() => setEditing('new')}><Plus />{t('referrals.affiliates.add')}</Button> : undefined}
                />
            ) : (
                <>
                    {canManage ? (
                        <div className="igr:flex igr:justify-end">
                            <Button onClick={() => setEditing('new')}>
                                <Plus />
                                {t('referrals.affiliates.add')}
                            </Button>
                        </div>
                    ) : null}
                    <Card className="igr:overflow-hidden">
                        <Table>
                            <THead>
                                <TR>
                                    <TH>{t('referrals.affiliates.name')}</TH>
                                    <TH>{t('referrals.affiliates.codes')}</TH>
                                    <TH>{t('referrals.affiliates.commission')}</TH>
                                    <TH className="igr:text-right">{t('referrals.affiliates.orders')}</TH>
                                    <TH className="igr:text-right">{t('referrals.affiliates.revenue')}</TH>
                                    <TH className="igr:text-right">{t('referrals.affiliates.toApprove')}</TH>
                                    <TH className="igr:text-right">{t('referrals.affiliates.toPay')}</TH>
                                    {canManage ? <TH className="igr:text-right">{t('referrals.affiliates.actions')}</TH> : null}
                                </TR>
                            </THead>
                            <TBody>
                                {list.map((a) => (
                                    <TR key={a.id}>
                                        <TD>
                                            <Link to={`afiliados/${a.id}`} className="igr:font-medium igr:hover:underline">
                                                {a.name}
                                            </Link>
                                            <span className="igr:mt-0.5 igr:flex igr:gap-1">
                                                <Badge variant="outline">{t(`referrals.affiliates.kinds.${a.kind}`)}</Badge>
                                                {a.status === 'paused' ? <Badge variant="warning">{t('referrals.affiliates.statuses.paused')}</Badge> : null}
                                            </span>
                                        </TD>
                                        <TD className="igr:font-mono igr:text-xs">
                                            <span className="igr:block">?ref={a.link_code}</span>
                                            <span className="igr:text-muted-foreground">{a.coupon_codes.join(', ') || '—'}</span>
                                        </TD>
                                        <TD className="igr:max-w-56 igr:text-xs igr:text-muted-foreground">{a.commission_label}</TD>
                                        <TD className="igr:text-right igr:tabular-nums">{a.stats?.orders ?? 0}</TD>
                                        <TD className="igr:text-right igr:tabular-nums">{money(a.stats?.revenue)}</TD>
                                        <TD className="igr:text-right igr:tabular-nums">{money(a.stats?.pending)}</TD>
                                        <TD className="igr:text-right igr:tabular-nums">{money(a.stats?.approved)}</TD>
                                        {canManage ? (
                                            <TD className="igr:text-right">
                                                <div className="igr:inline-flex igr:gap-1">
                                                    <Button size="icon" variant="ghost" aria-label={t('referrals.affiliates.portalLink')} title={t('referrals.affiliates.portalLink')} onClick={() => setPortal(a)}>
                                                        <KeyRound />
                                                    </Button>
                                                    <Button size="icon" variant="ghost" aria-label={t('referrals.affiliates.edit')} title={t('referrals.affiliates.edit')} onClick={() => setEditing(a)}>
                                                        <Pencil />
                                                    </Button>
                                                    <Button size="icon" variant="ghost" aria-label={t('referrals.affiliates.archive')} title={t('referrals.affiliates.archive')} onClick={() => setArchiving(a)}>
                                                        <Archive />
                                                    </Button>
                                                </div>
                                            </TD>
                                        ) : null}
                                    </TR>
                                ))}
                            </TBody>
                        </Table>
                    </Card>
                </>
            )}
            {editing !== null ? <AffiliateDialog affiliate={editing === 'new' ? null : editing} onClose={() => setEditing(null)} /> : null}
            {portal !== null ? <PortalLinkDialog affiliate={portal} onClose={() => setPortal(null)} /> : null}
            <Dialog open={archiving !== null} onOpenChange={(open) => (!open ? setArchiving(null) : undefined)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{t('referrals.affiliates.archiveTitle', { name: archiving?.name ?? '' })}</DialogTitle>
                        <DialogDescription>{t('referrals.affiliates.archiveDescription')}</DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setArchiving(null)}>
                            {t('referrals.cancel')}
                        </Button>
                        <Button
                            variant="destructive"
                            loading={archive.isPending}
                            onClick={() =>
                                archiving &&
                                archive.mutate(archiving.id, {
                                    onSuccess: () => {
                                        toast.success(t('referrals.affiliates.archived'));
                                        setArchiving(null);
                                    },
                                })
                            }
                        >
                            {t('referrals.affiliates.archive')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}
