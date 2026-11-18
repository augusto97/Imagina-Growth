import { ArrowLeft, ExternalLink, Inbox } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Link, useParams } from 'react-router';
import { useSite } from '@/api/queries';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { DescriptionList, EmptyState, ErrorState } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TBody, TD, TH, THead, TR } from '@/components/ui/table';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useCan, useWorkspace } from '@/hooks/useWorkspace';
import { formatDate, formatDateTime, formatNumber, formatRelative } from '@/lib/format';
import { toast } from '@/stores/toast';
import { ReconciliationTable } from './ReconciliationTable';
import { BackfillBadge, BackfillProgress, SiteStatusBadge } from './SiteBits';
import { SiteActions } from './SiteActions';

function Copyable({ value }: { value: string }) {
    const { t } = useTranslation();
    return (
        <button
            type="button"
            className="igr:rounded igr:bg-muted igr:px-1.5 igr:py-0.5 igr:font-mono igr:text-xs igr:hover:bg-accent"
            onClick={() => void navigator.clipboard.writeText(value).then(() => toast.success(t('common.copied')))}
            title={t('common.copy')}
        >
            {value}
        </button>
    );
}

export function SiteDetailPage() {
    const { t } = useTranslation();
    const { siteId = '' } = useParams();
    const { workspace } = useWorkspace();
    const canManage = useCan('manageSites');
    const query = useSite(workspace.id, siteId);
    const locale = workspace.locale;

    if (query.isError) {
        return <ErrorState error={query.error} onRetry={() => void query.refetch()} />;
    }
    if (query.isPending) {
        return (
            <div className="igr:space-y-4">
                <Skeleton className="igr:h-8 igr:w-64" />
                <Skeleton className="igr:h-48" />
                <Skeleton className="igr:h-64" />
            </div>
        );
    }

    const { site, reconciliation, rejections, events_24h: events } = query.data;
    const tz = site.timezone ?? workspace.timezone;
    const health = site.health;
    const capabilityChips = Object.entries(site.capabilities).filter(([, v]) => v === true || (Array.isArray(v) && v.length > 0) || (typeof v === 'string' && v !== ''));

    return (
        <>
            <Link to={`/w/${workspace.id}/sitios`} className="igr:mb-3 igr:inline-flex igr:items-center igr:gap-1 igr:text-[13px] igr:text-muted-foreground igr:hover:text-foreground">
                <ArrowLeft className="igr:size-3.5" />
                {t('sites.title')}
            </Link>
            <div className="igr:mb-5 igr:flex igr:flex-wrap igr:items-start igr:justify-between igr:gap-3">
                <div>
                    <div className="igr:flex igr:items-center igr:gap-2">
                        <h1 className="igr:text-lg igr:font-semibold igr:tracking-tight">{site.name ?? site.host}</h1>
                        <SiteStatusBadge status={site.status} />
                    </div>
                    <a href={site.url} target="_blank" rel="noreferrer" className="igr:mt-0.5 igr:inline-flex igr:items-center igr:gap-1 igr:text-[13px] igr:text-muted-foreground igr:hover:text-foreground">
                        {site.host}
                        <ExternalLink className="igr:size-3" />
                    </a>
                </div>
                <SiteActions workspaceId={workspace.id} site={site} canManage={canManage} />
            </div>

            <Tabs defaultValue="import">
                <TabsList>
                    <TabsTrigger value="import">{t('sites.tabs.import')}</TabsTrigger>
                    <TabsTrigger value="summary">{t('sites.tabs.summary')}</TabsTrigger>
                    <TabsTrigger value="data">{t('sites.tabs.data')}</TabsTrigger>
                    <TabsTrigger value="technical">{t('sites.tabs.technical')}</TabsTrigger>
                </TabsList>

                <TabsContent value="import" className="igr:space-y-4">
                    <Card>
                        <CardHeader>
                            <div className="igr:flex igr:items-center igr:justify-between igr:gap-2">
                                <CardTitle>{t('sites.backfill.title')}</CardTitle>
                                <BackfillBadge backfill={site.backfill} />
                            </div>
                            <CardDescription>
                                {site.backfill?.from
                                    ? t('sites.backfill.since', { date: formatDate(site.backfill.from, locale) })
                                    : t('sites.backfill.description')}
                                {site.backfill?.finished_at ? ` · ${t('sites.backfill.finished', { when: formatDateTime(site.backfill.finished_at, locale, tz) })}` : ''}
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            {site.backfill && Object.keys(site.backfill.entities).length > 0 ? (
                                <BackfillProgress backfill={site.backfill} locale={locale} />
                            ) : (
                                <p className="igr:text-[13px] igr:text-muted-foreground">{t('sites.backfill.waiting')}</p>
                            )}
                            {site.backfill?.error ? <p className="igr:mt-3 igr:rounded-md igr:bg-destructive/10 igr:px-3 igr:py-2 igr:text-[13px] igr:text-destructive">{site.backfill.error}</p> : null}
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardTitle>{t('reconciliation.title')}</CardTitle>
                            <CardDescription>{t('reconciliation.description')}</CardDescription>
                        </CardHeader>
                        <CardContent>
                            <ReconciliationTable reconciliation={reconciliation} currency={site.currency ?? workspace.currency} locale={locale} timezone={tz} />
                        </CardContent>
                    </Card>
                </TabsContent>

                <TabsContent value="summary">
                    <Card>
                        <CardContent className="igr:pt-4">
                            <DescriptionList
                                items={[
                                    { label: t('sites.fields.platform'), value: site.platform === 'woocommerce' ? 'WooCommerce' : 'WordPress' },
                                    { label: t('sites.fields.versions'), value: [site.plugin_version && `${t('sites.fields.plugin')} ${site.plugin_version}`, site.wp_version && `WordPress ${site.wp_version}`, site.wc_version && `WooCommerce ${site.wc_version}`, site.php_version && `PHP ${site.php_version}`].filter(Boolean).join(' · ') || '—' },
                                    { label: t('sites.fields.connectedAt'), value: formatDateTime(site.connected_at, locale, tz) },
                                    { label: t('sites.fields.lastSeen'), value: formatRelative(site.last_seen_at, locale) },
                                    { label: t('sites.fields.lastEvent'), value: formatRelative(site.last_event_at, locale) },
                                    { label: t('sites.fields.timezone'), value: tz },
                                    { label: t('sites.fields.currency'), value: site.currency ?? workspace.currency },
                                    {
                                        label: t('sites.fields.outbox'),
                                        value:
                                            health.heartbeat_at === undefined
                                                ? t('sites.fields.noHeartbeat')
                                                : t('sites.fields.outboxValue', { pending: formatNumber(health.outbox_pending ?? 0, locale), dead: formatNumber(health.outbox_dead ?? 0, locale) }),
                                    },
                                    { label: t('sites.fields.lastError'), value: health.last_error ?? '—' },
                                ]}
                            />
                        </CardContent>
                    </Card>
                </TabsContent>

                <TabsContent value="data" className="igr:space-y-4">
                    <Card>
                        <CardHeader>
                            <CardTitle>{t('sites.events.title')}</CardTitle>
                            <CardDescription>{t('sites.events.description')}</CardDescription>
                        </CardHeader>
                        <CardContent>
                            {events.length === 0 ? (
                                <p className="igr:text-[13px] igr:text-muted-foreground">{t('sites.events.empty')}</p>
                            ) : (
                                <Table>
                                    <THead>
                                        <TR className="igr:hover:bg-transparent">
                                            <TH>{t('sites.events.event')}</TH>
                                            <TH className="igr:text-right">{t('sites.events.count')}</TH>
                                        </TR>
                                    </THead>
                                    <TBody>
                                        {events.map((e) => (
                                            <TR key={e.event}>
                                                <TD className="igr:font-mono igr:text-xs">{e.event}</TD>
                                                <TD className="igr:text-right igr:tabular-nums">{formatNumber(e.c, locale)}</TD>
                                            </TR>
                                        ))}
                                    </TBody>
                                </Table>
                            )}
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardTitle>{t('sites.rejections.title')}</CardTitle>
                            <CardDescription>{t('sites.rejections.description')}</CardDescription>
                        </CardHeader>
                        <CardContent>
                            {rejections.length === 0 ? (
                                <EmptyState icon={Inbox} title={t('sites.rejections.empty')} className="igr:py-6" />
                            ) : (
                                <Table>
                                    <THead>
                                        <TR className="igr:hover:bg-transparent">
                                            <TH>{t('sites.rejections.when')}</TH>
                                            <TH>{t('sites.events.event')}</TH>
                                            <TH>{t('sites.rejections.reason')}</TH>
                                            <TH>{t('sites.rejections.detail')}</TH>
                                        </TR>
                                    </THead>
                                    <TBody>
                                        {rejections.map((r, i) => (
                                            <TR key={`${r.event_id ?? ''}-${i}`}>
                                                <TD className="igr:whitespace-nowrap igr:text-muted-foreground">{formatRelative(r.created_at, locale)}</TD>
                                                <TD className="igr:font-mono igr:text-xs">{r.event ?? '—'}</TD>
                                                <TD>
                                                    <Badge variant="destructive">{t(`sites.rejections.reasons.${r.reason}`, { defaultValue: r.reason })}</Badge>
                                                </TD>
                                                <TD className="igr:max-w-md igr:font-mono igr:text-xs igr:text-muted-foreground igr:break-words">{r.detail ?? '—'}</TD>
                                            </TR>
                                        ))}
                                    </TBody>
                                </Table>
                            )}
                        </CardContent>
                    </Card>
                </TabsContent>

                <TabsContent value="technical">
                    <Card>
                        <CardContent className="igr:space-y-4 igr:pt-4">
                            <DescriptionList
                                items={[
                                    { label: t('sites.fields.siteId'), value: <Copyable value={site.id} /> },
                                    { label: t('sites.fields.publicKey'), value: <Copyable value={site.public_key} /> },
                                    { label: t('sites.fields.restUrl'), value: site.rest_url ?? '—' },
                                    { label: t('sites.fields.secretRotated'), value: formatDateTime(site.secret_rotated_at, locale, tz) },
                                    { label: t('sites.fields.paidStatuses'), value: Array.isArray(site.settings.paid_statuses) ? (site.settings.paid_statuses as string[]).map((s) => t(`orderStatus.${s}`, { defaultValue: s })).join(', ') : '—' },
                                    { label: t('sites.fields.codGateways'), value: Array.isArray(site.settings.cod_gateways) ? (site.settings.cod_gateways as string[]).join(', ') : '—' },
                                    { label: t('sites.fields.offlineGateways'), value: Array.isArray(site.settings.offline_gateways) ? (site.settings.offline_gateways as string[]).join(', ') : '—' },
                                ]}
                            />
                            <div>
                                <p className="igr:mb-2 igr:text-[13px] igr:text-muted-foreground">{t('sites.fields.capabilities')}</p>
                                {capabilityChips.length === 0 ? (
                                    <p className="igr:text-[13px]">—</p>
                                ) : (
                                    <div className="igr:flex igr:flex-wrap igr:gap-1.5">
                                        {capabilityChips.map(([k, v]) => (
                                            <Badge key={k} variant="secondary">
                                                {t(`sites.capabilities.${k}`, { defaultValue: k })}
                                                {Array.isArray(v) ? `: ${v.join(', ')}` : typeof v === 'string' ? `: ${v}` : ''}
                                            </Badge>
                                        ))}
                                    </div>
                                )}
                            </div>
                            {canManage ? null : (
                                <Button variant="outline" size="sm" disabled>
                                    {t('sites.readOnly')}
                                </Button>
                            )}
                        </CardContent>
                    </Card>
                </TabsContent>
            </Tabs>
        </>
    );
}
