import { Check, Copy, PackageSearch, RefreshCw, Sparkles } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useContacts } from '@/api/queries';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input, Select } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { EmptyState, ErrorState, PageHeader } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TBody, TD, TH, THead, TR } from '@/components/ui/table';
import { useCan, useWorkspace } from '@/hooks/useWorkspace';
import { isApiError } from '@/lib/api';
import { formatPercent, formatRelative } from '@/lib/format';
import { toast } from '@/stores/toast';
import {
    type CatalogProduct,
    type PreviewParams,
    RECO_KINDS,
    type RecoKind,
    type RecoOverview,
    useAssociations,
    useRebuildReco,
    useRecoOverview,
    useRecoPreview,
    useSaveRecoSettings,
} from './api';
import { CommerceNav } from './CommerceNav';
import { ProductCard } from './ProductCard';
import { ProductPicker } from './ProductPicker';

/** Recomendaciones (M16): bloques, vista previa con datos reales y reglas de «comprados juntos». */
export function RecommendationsPage() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const [site, setSite] = useState<string | null>(null);
    const overview = useRecoOverview(workspace.id, site);

    return (
        <>
            <CommerceNav />
            <PageHeader
                title={t('onsiteCommerce.reco.title')}
                description={t('onsiteCommerce.reco.description')}
                actions={
                    overview.data && overview.data.sites.length > 1 ? (
                        <Select aria-label={t('onsiteCommerce.reco.site')} value={overview.data.site_id ?? ''} onChange={(e) => setSite(e.target.value)} className="igr:w-48">
                            {overview.data.sites.map((s) => (
                                <option key={s.id} value={s.id}>
                                    {s.name}
                                </option>
                            ))}
                        </Select>
                    ) : null
                }
            />
            {overview.isPending ? (
                <div className="igr:grid igr:gap-3">
                    <Skeleton className="igr:h-20" />
                    <Skeleton className="igr:h-64" />
                </div>
            ) : overview.isError ? (
                <ErrorState error={overview.error} onRetry={() => void overview.refetch()} />
            ) : overview.data.site_id === null ? (
                <EmptyState icon={PackageSearch} title={t('onsiteCommerce.reco.noSite.title')} description={t('onsiteCommerce.reco.noSite.description')} />
            ) : (
                <div className="igr:space-y-5">
                    <StatusStrip overview={overview.data} />
                    <BlocksGrid overview={overview.data} />
                    <PreviewCard overview={overview.data} />
                    <RulesCard overview={overview.data} />
                </div>
            )}
        </>
    );
}

function StatusStrip({ overview }: { overview: RecoOverview }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const canManage = useCan('manageMarketing');
    const rebuild = useRebuildReco(workspace.id);
    const s = overview.stats;
    if (s === null) return null;
    const items = [
        { label: t('onsiteCommerce.reco.stats.inStock'), value: t('onsiteCommerce.reco.stats.ofTotal', { n: s.in_stock, total: s.products }) },
        { label: t('onsiteCommerce.reco.stats.withSales', { days: overview.config.best_sellers_days }), value: String(s.with_sales_90d) },
        { label: t('onsiteCommerce.reco.stats.trending'), value: String(s.trending) },
        { label: t('onsiteCommerce.reco.stats.rules'), value: t('onsiteCommerce.reco.stats.rulesValue', { rules: s.rules, products: s.products_with_rules }) },
    ];

    return (
        <Card>
            <CardContent className="igr:flex igr:flex-col igr:gap-4 igr:py-4 igr:md:flex-row igr:md:items-center">
                <dl className="igr:grid igr:flex-1 igr:grid-cols-2 igr:gap-4 igr:md:grid-cols-4">
                    {items.map((item) => (
                        <div key={item.label}>
                            <dt className="igr:text-xs igr:text-muted-foreground">{item.label}</dt>
                            <dd className="igr:mt-0.5 igr:text-lg igr:font-semibold igr:tabular-nums">{item.value}</dd>
                        </div>
                    ))}
                </dl>
                <div className="igr:space-y-1.5 igr:text-xs igr:text-muted-foreground igr:md:w-56 igr:md:text-right">
                    <p>
                        {s.stats_computed_at
                            ? t('onsiteCommerce.reco.stats.updated', { when: formatRelative(s.stats_computed_at, workspace.locale) })
                            : t('onsiteCommerce.reco.stats.never')}
                    </p>
                    {canManage ? (
                        <Button
                            variant="outline"
                            size="sm"
                            loading={rebuild.isPending}
                            onClick={() => rebuild.mutate(undefined, { onSuccess: () => toast.success(t('onsiteCommerce.reco.stats.rebuildQueued')) })}
                        >
                            <RefreshCw />
                            {t('onsiteCommerce.reco.stats.rebuild')}
                        </Button>
                    ) : null}
                </div>
            </CardContent>
        </Card>
    );
}

function BlocksGrid({ overview }: { overview: RecoOverview }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const canManage = useCan('manageMarketing');
    const save = useSaveRecoSettings(workspace.id);
    const [copied, setCopied] = useState<string | null>(null);
    const copy = (code: string) => {
        void navigator.clipboard.writeText(code).then(() => {
            setCopied(code);
            window.setTimeout(() => setCopied(null), 1500);
        });
    };

    return (
        <section aria-labelledby="reco-blocks">
            <h2 id="reco-blocks" className="igr:mb-2 igr:text-sm igr:font-semibold">
                {t('onsiteCommerce.reco.blocks.title')}
            </h2>
            <p className="igr:mb-3 igr:text-[13px] igr:text-muted-foreground">{t('onsiteCommerce.reco.blocks.help')}</p>
            <div className="igr:grid igr:grid-cols-1 igr:gap-3 igr:md:grid-cols-2 igr:xl:grid-cols-3">
                {overview.kinds.map((k) => (
                    <Card key={k.kind} className={k.enabled ? '' : 'igr:opacity-70'}>
                        <CardHeader className="igr:space-y-1 igr:pb-2">
                            <div className="igr:flex igr:items-start igr:justify-between igr:gap-2">
                                <CardTitle className="igr:text-sm">{t(`onsiteCommerce.kinds.${k.kind}.name`)}</CardTitle>
                                <Badge variant={k.personal ? 'default' : 'secondary'}>
                                    {k.personal ? t('onsiteCommerce.reco.blocks.personal') : t('onsiteCommerce.reco.blocks.shared')}
                                </Badge>
                            </div>
                            <CardDescription className="igr:text-xs">{t(`onsiteCommerce.kinds.${k.kind}.logic`)}</CardDescription>
                        </CardHeader>
                        <CardContent className="igr:flex igr:items-center igr:justify-between igr:gap-2 igr:pt-0">
                            <button
                                type="button"
                                onClick={() => copy(k.shortcode)}
                                className="igr:inline-flex igr:min-w-0 igr:items-center igr:gap-1.5 igr:rounded igr:bg-muted igr:px-1.5 igr:py-0.5 igr:font-mono igr:text-[11px] igr:text-muted-foreground igr:hover:text-foreground"
                                aria-label={t('onsiteCommerce.reco.blocks.copy', { code: k.shortcode })}
                            >
                                <span className="igr:truncate">{k.shortcode}</span>
                                {copied === k.shortcode ? <Check className="igr:size-3" /> : <Copy className="igr:size-3" />}
                            </button>
                            {canManage ? (
                                <label className="igr:flex igr:shrink-0 igr:cursor-pointer igr:items-center igr:gap-1.5 igr:text-xs">
                                    <input
                                        type="checkbox"
                                        checked={k.enabled}
                                        disabled={save.isPending}
                                        onChange={(e) =>
                                            save.mutate(
                                                { kinds: { [k.kind]: e.target.checked } },
                                                { onError: (err) => toast.error(t('errors.title'), isApiError(err) ? err.message : undefined) },
                                            )
                                        }
                                    />
                                    {k.enabled ? t('onsiteCommerce.reco.blocks.on') : t('onsiteCommerce.reco.blocks.off')}
                                </label>
                            ) : (
                                <span className="igr:text-xs igr:text-muted-foreground">
                                    {k.enabled ? t('onsiteCommerce.reco.blocks.on') : t('onsiteCommerce.reco.blocks.off')}
                                </span>
                            )}
                        </CardContent>
                    </Card>
                ))}
            </div>
        </section>
    );
}

function PreviewCard({ overview }: { overview: RecoOverview }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const canSeeContacts = useCan('viewAllContacts');
    const [kind, setKind] = useState<RecoKind>('bought_together');
    const [product, setProduct] = useState<CatalogProduct | null>(null);
    const [cart, setCart] = useState<CatalogProduct[]>([]);
    const [contactQ, setContactQ] = useState('');
    const [contactId, setContactId] = useState('');
    const contacts = useContacts(workspace.id, contactQ.trim(), 1);
    const needs = overview.kinds.find((k) => k.kind === kind)?.needs ?? null;

    const params: PreviewParams = { kind, site_id: overview.site_id };
    if (needs === 'product' && product) params.product_id = product.id;
    if (needs === 'category' && product) params.product_id = product.id;
    if (needs === 'cart' && cart.length > 0) params.cart = cart.map((p) => p.id).join(',');
    if (needs === 'contact' && contactId !== '') params.contact_id = contactId;
    const ready = needs === 'product' ? product !== null : needs === 'cart' ? cart.length > 0 : needs === 'contact' ? contactId !== '' : true;
    const preview = useRecoPreview(workspace.id, params, ready);

    return (
        <Card>
            <CardHeader>
                <CardTitle className="igr:text-sm">{t('onsiteCommerce.reco.preview.title')}</CardTitle>
                <CardDescription>{t('onsiteCommerce.reco.preview.help')}</CardDescription>
            </CardHeader>
            <CardContent className="igr:space-y-4">
                <div className="igr:grid igr:gap-3 igr:md:grid-cols-[220px_1fr]">
                    <div className="igr:space-y-1.5">
                        <Label htmlFor="reco-kind">{t('onsiteCommerce.reco.preview.kind')}</Label>
                        <Select id="reco-kind" value={kind} onChange={(e) => setKind(e.target.value as RecoKind)}>
                            {RECO_KINDS.map((k) => (
                                <option key={k} value={k}>
                                    {t(`onsiteCommerce.kinds.${k}.name`)}
                                </option>
                            ))}
                        </Select>
                    </div>
                    <div className="igr:space-y-1.5">
                        {needs === 'product' || needs === 'category' ? (
                            <>
                                <Label>{needs === 'product' ? t('onsiteCommerce.reco.preview.product') : t('onsiteCommerce.reco.preview.productOptional')}</Label>
                                <ProductPicker site={overview.site_id} value={product} onChange={setProduct} label={t('onsiteCommerce.reco.preview.product')} />
                            </>
                        ) : needs === 'cart' ? (
                            <>
                                <Label>{t('onsiteCommerce.reco.preview.cart')}</Label>
                                <ProductPicker
                                    site={overview.site_id}
                                    value={null}
                                    onChange={(p) => p && setCart((c) => (c.some((x) => x.id === p.id) ? c : [...c, p]))}
                                    label={t('onsiteCommerce.reco.preview.cart')}
                                />
                                {cart.length > 0 ? (
                                    <div className="igr:flex igr:flex-wrap igr:gap-1.5">
                                        {cart.map((p) => (
                                            <button
                                                key={p.id}
                                                type="button"
                                                onClick={() => setCart((c) => c.filter((x) => x.id !== p.id))}
                                                className="igr:rounded-full igr:bg-muted igr:px-2 igr:py-0.5 igr:text-xs igr:hover:line-through"
                                            >
                                                {p.name}
                                            </button>
                                        ))}
                                    </div>
                                ) : null}
                            </>
                        ) : needs === 'contact' ? (
                            canSeeContacts ? (
                                <>
                                    <Label htmlFor="reco-contact">{t('onsiteCommerce.reco.preview.contact')}</Label>
                                    <Input
                                        id="reco-contact"
                                        placeholder={t('onsiteCommerce.reco.preview.contactPlaceholder')}
                                        value={contactQ}
                                        onChange={(e) => setContactQ(e.target.value)}
                                    />
                                    {contactQ.trim() !== '' ? (
                                        <div className="igr:flex igr:flex-wrap igr:gap-1.5">
                                            {(contacts.data?.data ?? []).slice(0, 6).map((c) => (
                                                <button
                                                    key={c.id}
                                                    type="button"
                                                    onClick={() => setContactId(c.id)}
                                                    className={`igr:rounded-full igr:border igr:px-2 igr:py-0.5 igr:text-xs ${contactId === c.id ? 'igr:border-primary igr:bg-primary/10' : 'igr:hover:bg-accent'}`}
                                                >
                                                    {c.name || c.id}
                                                </button>
                                            ))}
                                        </div>
                                    ) : null}
                                </>
                            ) : (
                                <p className="igr:pt-6 igr:text-xs igr:text-muted-foreground">{t('onsiteCommerce.reco.preview.noContacts')}</p>
                            )
                        ) : (
                            <p className="igr:pt-6 igr:text-xs igr:text-muted-foreground">{t('onsiteCommerce.reco.preview.nothingNeeded')}</p>
                        )}
                    </div>
                </div>

                {!ready ? (
                    <p className="igr:rounded-md igr:border igr:border-dashed igr:p-6 igr:text-center igr:text-[13px] igr:text-muted-foreground">
                        {t(`onsiteCommerce.reco.preview.need.${needs ?? 'product'}`)}
                    </p>
                ) : preview.isError ? (
                    <ErrorState error={preview.error} onRetry={() => void preview.refetch()} />
                ) : preview.isPending ? (
                    <div className="igr:grid igr:grid-cols-2 igr:gap-3 igr:md:grid-cols-4">
                        {[0, 1, 2, 3].map((i) => (
                            <Skeleton key={i} className="igr:h-48" />
                        ))}
                    </div>
                ) : (
                    <div className="igr:space-y-2">
                        {preview.data.fallback ? (
                            <p className="igr:flex igr:items-center igr:gap-1.5 igr:text-xs igr:text-muted-foreground">
                                <Sparkles className="igr:size-3.5" />
                                {t('onsiteCommerce.reco.preview.fallback')}
                            </p>
                        ) : null}
                        {preview.data.products.length === 0 ? (
                            <p className="igr:rounded-md igr:border igr:border-dashed igr:p-6 igr:text-center igr:text-[13px] igr:text-muted-foreground">
                                {t(`onsiteCommerce.reco.preview.empty.${kind}`)}
                            </p>
                        ) : (
                            <div className="igr:grid igr:grid-cols-2 igr:gap-3 igr:md:grid-cols-4">
                                {preview.data.products.map((p) => (
                                    <ProductCard key={p.id} product={p} />
                                ))}
                            </div>
                        )}
                    </div>
                )}
            </CardContent>
        </Card>
    );
}

function RulesCard({ overview }: { overview: RecoOverview }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const [product, setProduct] = useState<CatalogProduct | null>(null);
    const [page, setPage] = useState(1);
    const rules = useAssociations(workspace.id, overview.site_id, product?.id ?? '', page);
    const pages = rules.data ? Math.max(1, Math.ceil(rules.data.total / rules.data.per_page)) : 1;

    return (
        <Card>
            <CardHeader>
                <CardTitle className="igr:text-sm">{t('onsiteCommerce.reco.rules.title')}</CardTitle>
                <CardDescription>{t('onsiteCommerce.reco.rules.help', { min: overview.config.min_pair_orders, days: overview.config.window_days })}</CardDescription>
            </CardHeader>
            <CardContent className="igr:space-y-3">
                <div className="igr:max-w-sm">
                    <ProductPicker
                        site={overview.site_id}
                        value={product}
                        onChange={(p) => {
                            setProduct(p);
                            setPage(1);
                        }}
                        label={t('onsiteCommerce.reco.rules.filter')}
                    />
                </div>
                {rules.isError ? (
                    <ErrorState error={rules.error} onRetry={() => void rules.refetch()} />
                ) : rules.isPending ? (
                    <Skeleton className="igr:h-40" />
                ) : rules.data.data.length === 0 ? (
                    <p className="igr:rounded-md igr:border igr:border-dashed igr:p-6 igr:text-center igr:text-[13px] igr:text-muted-foreground">
                        {t('onsiteCommerce.reco.rules.empty', { min: overview.config.min_pair_orders })}
                    </p>
                ) : (
                    <>
                        <div className="igr:overflow-x-auto">
                            <Table>
                                <THead>
                                    <TR>
                                        <TH>{t('onsiteCommerce.reco.rules.product')}</TH>
                                        <TH>{t('onsiteCommerce.reco.rules.related')}</TH>
                                        <TH className="igr:text-right">{t('onsiteCommerce.reco.rules.pairOrders')}</TH>
                                        <TH className="igr:text-right">{t('onsiteCommerce.reco.rules.confidence')}</TH>
                                        <TH className="igr:text-right">{t('onsiteCommerce.reco.rules.lift')}</TH>
                                    </TR>
                                </THead>
                                <TBody>
                                    {rules.data.data.map((r) => (
                                        <TR key={`${r.product.id}-${r.related.id}`}>
                                            <TD className="igr:max-w-56 igr:truncate">{r.product.name ?? r.product.id}</TD>
                                            <TD className="igr:max-w-56">
                                                <span className="igr:truncate">{r.related.name ?? r.related.id}</span>
                                                {!r.related.in_stock ? (
                                                    <Badge variant="outline" className="igr:ml-1.5">
                                                        {t('onsiteCommerce.stock.out')}
                                                    </Badge>
                                                ) : null}
                                            </TD>
                                            <TD className="igr:text-right igr:tabular-nums">{r.pair_orders}</TD>
                                            <TD className="igr:text-right igr:tabular-nums">{formatPercent(r.confidence, workspace.locale)}</TD>
                                            <TD className="igr:text-right igr:tabular-nums">{t('onsiteCommerce.reco.rules.liftValue', { value: r.lift.toFixed(1) })}</TD>
                                        </TR>
                                    ))}
                                </TBody>
                            </Table>
                        </div>
                        {pages > 1 ? (
                            <div className="igr:flex igr:items-center igr:justify-end igr:gap-2 igr:text-xs igr:text-muted-foreground">
                                <Button variant="outline" size="sm" disabled={page <= 1} onClick={() => setPage(page - 1)}>
                                    {t('common.previous')}
                                </Button>
                                {t('onsiteCommerce.pagination', { page, pages })}
                                <Button variant="outline" size="sm" disabled={page >= pages} onClick={() => setPage(page + 1)}>
                                    {t('common.next')}
                                </Button>
                            </div>
                        ) : null}
                    </>
                )}
            </CardContent>
        </Card>
    );
}
