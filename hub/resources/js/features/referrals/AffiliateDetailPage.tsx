import { Copy, KeyRound, Pencil, Receipt } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useParams } from 'react-router';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { EmptyState, ErrorState, PageHeader } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { useCan, useWorkspace } from '@/hooks/useWorkspace';
import { formatRelative } from '@/lib/format';
import { toast } from '@/stores/toast';
import { StatCard, StatGrid } from '@/components/ui/stat-card';
import { useFormatters } from '@/features/analytics/format';
import { AffiliateDialog } from './AffiliateDialog';
import { useAffiliate } from './api';
import { ConversionsTable } from './ConversionsTable';
import { PortalLinkDialog } from './PortalLinkDialog';
import { Breadcrumbs, useAutoCrumbs } from '@/components/ui/page-header';

/** Detalle de un afiliado: sus cifras, enlaces, cupones, material y pedidos. */
export function AffiliateDetailPage() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const { affiliateId } = useParams();
    const canManage = useCan('manageMarketing');
    const fmt = useFormatters();
    const detail = useAffiliate(workspace.id, affiliateId);
    const [editing, setEditing] = useState(false);
    const [portal, setPortal] = useState(false);

    const crumbs = useAutoCrumbs();
    const back = <Breadcrumbs className="igr:mb-3" items={crumbs.map((c, i) => (i === crumbs.length - 1 ? { ...c, to: `/w/${workspace.id}/referidos?tab=afiliados` } : c))} />;
    if (detail.isPending) {
        return <Skeleton className="igr:h-96" />;
    }
    if (detail.isError) {
        return (
            <>
                {back}
                <ErrorState error={detail.error} onRetry={() => void detail.refetch()} />
            </>
        );
    }
    const a = detail.data;

    return (
        <>
            {back}
            <PageHeader
                title={
                    <span className="igr:flex igr:items-center igr:gap-2">
                        {a.name}
                        <Badge variant="outline">{t(`referrals.affiliates.kinds.${a.kind}`)}</Badge>
                        {a.status !== 'active' ? <Badge variant="warning">{t(`referrals.affiliates.statuses.${a.status}`)}</Badge> : null}
                    </span>
                }
                description={a.commission_label}
                actions={
                    canManage ? (
                        <div className="igr:flex igr:gap-2">
                            <Button variant="outline" onClick={() => setPortal(true)}>
                                <KeyRound />
                                {t('referrals.affiliates.portalLink')}
                            </Button>
                            <Button variant="outline" onClick={() => setEditing(true)}>
                                <Pencil />
                                {t('referrals.affiliates.edit')}
                            </Button>
                        </div>
                    ) : undefined
                }
            />
            <div className="igr:space-y-4">
                <StatGrid>
                    <StatCard label={t('referrals.affiliates.orders')} value={fmt.number(a.stats.orders)} />
                    <StatCard label={t('referrals.affiliates.revenue')} value={fmt.money(Number(a.stats.revenue))} />
                    <StatCard label={t('referrals.affiliates.toApprove')} value={fmt.money(Number(a.stats.pending))} />
                    <StatCard label={t('referrals.affiliates.toPay')} value={fmt.money(Number(a.stats.approved))} />
                </StatGrid>
                <div className="igr:grid igr:gap-4 igr:lg:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle>{t('referrals.affiliates.linksTitle')}</CardTitle>
                        </CardHeader>
                        <CardContent className="igr:space-y-2">
                            {a.links.map((l) => (
                                <div key={l.url} className="igr:flex igr:gap-2">
                                    <Input readOnly value={l.url} aria-label={l.site} onFocus={(e) => e.currentTarget.select()} className="igr:font-mono" />
                                    <Button
                                        variant="outline"
                                        size="icon"
                                        aria-label={t('referrals.portal.copy')}
                                        onClick={() => void navigator.clipboard.writeText(l.url).then(() => toast.success(t('referrals.portal.copied')))}
                                    >
                                        <Copy />
                                    </Button>
                                </div>
                            ))}
                            <p className="igr:text-xs igr:text-muted-foreground">
                                {t('referrals.affiliates.coupons')}: <span className="igr:font-mono">{a.coupon_codes.join(', ') || '—'}</span>
                            </p>
                            <p className="igr:text-xs igr:text-muted-foreground">
                                {a.portal_sent_at
                                    ? t('referrals.affiliates.portalSent', { when: formatRelative(a.portal_sent_at, workspace.locale) })
                                    : t('referrals.affiliates.portalNever')}
                            </p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardTitle>{t('referrals.affiliates.material')}</CardTitle>
                        </CardHeader>
                        <CardContent>
                            {a.material.length === 0 ? (
                                <p className="igr:text-[13px] igr:text-muted-foreground">{t('referrals.affiliates.noMaterial')}</p>
                            ) : (
                                <ul className="igr:space-y-1.5 igr:text-[13px]">
                                    {a.material.map((m) => (
                                        <li key={m.url}>
                                            <a href={m.url} target="_blank" rel="noopener noreferrer" className="igr:text-primary igr:hover:underline">
                                                {m.label}
                                            </a>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </CardContent>
                    </Card>
                </div>
                <Card className="igr:overflow-hidden">
                    <CardHeader>
                        <CardTitle>{t('referrals.affiliates.ordersTitle')}</CardTitle>
                    </CardHeader>
                    {a.conversions.length === 0 ? (
                        <CardContent>
                            <EmptyState icon={Receipt} title={t('referrals.conversions.emptyTitle')} description={t('referrals.conversions.emptyDescription')} />
                        </CardContent>
                    ) : (
                        <ConversionsTable rows={a.conversions} showAffiliate={false} />
                    )}
                </Card>
            </div>
            {editing ? <AffiliateDialog affiliate={a} onClose={() => setEditing(false)} /> : null}
            {portal ? <PortalLinkDialog affiliate={a} onClose={() => setPortal(false)} /> : null}
        </>
    );
}
