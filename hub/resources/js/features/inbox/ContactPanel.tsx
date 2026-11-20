import { ExternalLink, Megaphone } from 'lucide-react';
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { ErrorState } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { useWorkspace } from '@/hooks/useWorkspace';
import { formatDate, formatMoney, formatNumber } from '@/lib/format';
import { BriefCard } from '@/features/leads/BriefCard';
import { QualificationCard } from '@/features/leads/QualificationCard';
import { temperatureTone } from '@/features/leads/logic';
import { useContactPanel } from './api';
import type { PanelLead } from './types';

/** El perfil del contacto (columna al lado del chat desde xl; hoja aparte por debajo, InboxPage): pedidos, carrito actual, puntos, segmentos, último producto visto y origen. */
export function ContactPanel({ id }: { id: string }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const panel = useContactPanel(workspace.id, id);
    const money = (v: string, currency?: string | null) => formatMoney(v, currency ?? workspace.currency, workspace.locale);

    if (panel.isError) {
        return (
            <div className="igr:p-3">
                <ErrorState error={panel.error} onRetry={() => void panel.refetch()} />
            </div>
        );
    }
    if (panel.isPending) {
        return (
            <div className="igr:space-y-2 igr:p-3">
                <Skeleton className="igr:h-16" />
                <Skeleton className="igr:h-24" />
            </div>
        );
    }
    const d = panel.data;
    if (!('contact' in d)) return null;
    const c = d.contact;

    return (
        <div className="igr:space-y-4 igr:p-3 igr:text-xs">
            <div className="igr:min-w-0">
                <p className="igr:truncate igr:text-sm igr:font-semibold">{c.name}</p>
                <p className="igr:text-muted-foreground">{[c.phone, c.city].filter(Boolean).join(' · ')}</p>
            </div>
            <div className="igr:flex igr:flex-wrap igr:gap-1">
                {c.lifecycle_stage ? (
                    <Badge variant="secondary">
                        {t(`stages.${c.lifecycle_stage}`, {
                            defaultValue: c.lifecycle_stage,
                        })}
                    </Badge>
                ) : null}
                {c.rfm_segment ? (
                    <Badge variant="outline">
                        {t(`contacts.rfm.${c.rfm_segment}`, {
                            defaultValue: c.rfm_segment,
                        })}
                    </Badge>
                ) : null}
                {c.tier ? <Badge>{c.tier}</Badge> : null}
                {d.origin.ad ? (
                    <Badge variant="warning">
                        <Megaphone className="igr:size-3" aria-hidden />
                        {t('inbox.panel.fromAd')}
                    </Badge>
                ) : null}
            </div>
            {d.lead ? <LeadSection lead={d.lead} /> : null}
            <dl className="igr:grid igr:grid-cols-2 igr:gap-2">
                <Fact label={t('inbox.panel.orders')} value={formatNumber(c.orders_count, workspace.locale)} />
                <Fact label={t('inbox.panel.revenue')} value={money(c.revenue_total)} />
                <Fact label={t('inbox.panel.points')} value={formatNumber(c.points, workspace.locale)} />
                <Fact label={t('inbox.panel.wallet')} value={money(c.wallet)} />
            </dl>
            <Section title={t('inbox.panel.cart')}>
                {d.cart ? (
                    <div className="igr:space-y-1">
                        <p>
                            {t('inbox.panel.cartSummary', {
                                count: d.cart.items_count,
                                total: money(d.cart.subtotal, d.cart.currency),
                            })}{' '}
                            <Badge variant={d.cart.status === 'abandoned' ? 'warning' : 'secondary'}>
                                {t(`inbox.panel.cartStatus.${d.cart.status}`, {
                                    defaultValue: d.cart.status,
                                })}
                            </Badge>
                        </p>
                        <ul className="igr:text-muted-foreground">
                            {d.cart.items.map((i, k) => (
                                <li key={k}>
                                    {i.qty} × {i.name ?? '—'}
                                </li>
                            ))}
                        </ul>
                    </div>
                ) : (
                    <p className="igr:text-muted-foreground">{t('inbox.panel.noCart')}</p>
                )}
            </Section>
            <Section title={t('inbox.panel.lastOrders')}>
                {d.orders.length === 0 ? (
                    <p className="igr:text-muted-foreground">{t('inbox.panel.noOrders')}</p>
                ) : (
                    <ul className="igr:space-y-1">
                        {d.orders.map((o) => (
                            <li key={o.id} className="igr:flex igr:justify-between igr:gap-2">
                                <span>
                                    #{o.number ?? '—'} ·{' '}
                                    {t(`orderStatus.${o.status}`, {
                                        defaultValue: o.status,
                                    })}
                                </span>
                                <span className="igr:tabular-nums">{money(o.total, o.currency)}</span>
                            </li>
                        ))}
                    </ul>
                )}
            </Section>
            <Section title={t('inbox.panel.lastViewed')}>
                <p className={d.last_product_viewed ? '' : 'igr:text-muted-foreground'}>
                    {d.last_product_viewed
                        ? `${d.last_product_viewed.name} · ${formatDate(d.last_product_viewed.at, workspace.locale, workspace.timezone)}`
                        : t('inbox.panel.nothingViewed')}
                </p>
            </Section>
            <Section title={t('inbox.panel.segments')}>
                {d.segments.length === 0 ? (
                    <p className="igr:text-muted-foreground">—</p>
                ) : (
                    <div className="igr:flex igr:flex-wrap igr:gap-1">
                        {d.segments.map((s) => (
                            <Badge key={s} variant="outline">
                                {s}
                            </Badge>
                        ))}
                    </div>
                )}
            </Section>
            <Button asChild size="sm" variant="outline" className="igr:w-full">
                <Link to={`/w/${workspace.id}/contactos/${c.id}`}>
                    <ExternalLink aria-hidden />
                    {t('inbox.panel.profile')}
                </Link>
            </Button>
        </div>
    );
}

/** El lead del contacto: etapa, temperatura y la ficha que la IA llena con este chat. */
function LeadSection({ lead }: { lead: PanelLead }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    return (
        <div className="igr:space-y-2 igr:rounded-md igr:border igr:p-2">
            <div className="igr:flex igr:flex-wrap igr:items-center igr:gap-1.5">
                <span className="igr:text-[11px] igr:font-semibold igr:uppercase igr:tracking-wide igr:text-muted-foreground">{t('inbox.panel.lead')}</span>
                <Badge variant="secondary">{lead.stage_label}</Badge>
                <Badge variant={temperatureTone(lead.temperature)}>
                    {lead.score} · {t(`leads.temperature.${lead.temperature}`)}
                </Badge>
            </div>
            {lead.owner ? <p className="igr:text-muted-foreground">{t('inbox.panel.leadOwner', { name: lead.owner.name })}</p> : null}
            <BriefCard key={lead.brief_updated_at ?? 'empty'} leadId={lead.id} brief={lead.brief} editable={lead.can_open} compact />
            <QualificationCard qualification={lead.qualification} compact />
            {lead.can_open ? (
                <Link to={`/w/${workspace.id}/leads?lead=${lead.id}`} className="igr:inline-flex igr:items-center igr:gap-1 igr:text-primary igr:hover:underline">
                    {t('inbox.panel.openLead')}
                    <ExternalLink className="igr:size-3" aria-hidden />
                </Link>
            ) : null}
        </div>
    );
}

function Fact({ label, value }: { label: string; value: string }) {
    return (
        <div className="igr:rounded-md igr:border igr:p-2">
            <dt className="igr:text-[11px] igr:text-muted-foreground">{label}</dt>
            <dd className="igr:font-semibold igr:tabular-nums">{value}</dd>
        </div>
    );
}

function Section({ title, children }: { title: string; children: ReactNode }) {
    return (
        <section>
            <h3 className="igr:mb-1 igr:text-[11px] igr:font-semibold igr:uppercase igr:tracking-wide igr:text-muted-foreground">{title}</h3>
            {children}
        </section>
    );
}
