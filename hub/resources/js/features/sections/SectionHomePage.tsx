import { ArrowRight, ArrowUpRight, Lock } from 'lucide-react';
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { useMembers, useOverview } from '@/api/queries';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Callout } from '@/components/ui/callout';
import { IconTile } from '@/components/ui/icon-tile';
import { PageHeader } from '@/components/ui/page-header';
import { Skeleton } from '@/components/ui/skeleton';
import { lockResolution } from '@/components/layout/LockedPage';
import { itemLock, itemPath, navContext, sectionLock, type NavItem, type NavLock } from '@/components/layout/nav';
import { usePageContext } from '@/components/layout/pageContext';
import { lockLabelKey } from '@/components/layout/SectionNav';
import { NotFoundPage } from '@/components/layout/guards';
import { useCampaigns } from '@/features/campaigns/api';
import { useFlows } from '@/features/flows/api';
import { useInsights } from '@/features/insights/api';
import { useWidgets } from '@/features/onsite/api';
import { useSegments } from '@/features/segments/api';
import { useCan, useWorkspace } from '@/hooks/useWorkspace';
import { formatNumber } from '@/lib/format';
import { cn } from '@/lib/cn';

/*
 * Página de entrada de una sección (rediseño v2 §3): qué hay dentro, en tarjetas con su «para qué sirve» y, cuando es
 * barato saberlo, un conteo o su estado (usa las mismas consultas que las pantallas, así la caché ya queda lista al
 * entrar). Lo que todavía no puede servir lleva su candado y la sección entera avisa arriba qué falta.
 */

/** La acción principal de cada sección (ruta relativa a /w/:workspaceId). */
const PRIMARY: Record<string, { to: string; ability?: Parameters<typeof useCan>[0] }> = {
    customers: { to: 'contactos/importar', ability: 'editContacts' },
    conversations: { to: 'bandeja', ability: 'useInbox' },
    messages: { to: 'flujos' },
    onsite: { to: 'en-el-sitio', ability: 'manageMarketing' },
    results: { to: 'analitica' },
    settings: { to: 'sitios?conectar=1', ability: 'manageSites' },
};

function Stat({ children, loading }: { children?: ReactNode; loading?: boolean }) {
    if (loading) {
        return <Skeleton className="igr:h-4 igr:w-24" />;
    }
    return children ? <span className="igr:text-caption igr:font-medium igr:text-muted-foreground igr:tabular-nums">{children}</span> : null;
}

function PeopleStat() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const overview = useOverview(workspace.id);
    const c = overview.data?.contacts;
    return (
        <Stat loading={overview.isPending}>
            {c
                ? `${t('sections.overview.stats.people', { count: c.total, formatted: formatNumber(c.total, workspace.locale) })} · ${t('sections.overview.stats.customers', { count: c.customers, formatted: formatNumber(c.customers, workspace.locale) })}`
                : null}
        </Stat>
    );
}

function GroupsStat() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const q = useSegments(workspace.id);
    return <Stat loading={q.isPending}>{q.data ? t('sections.overview.stats.groups', { count: q.data.length }) : null}</Stat>;
}

function FlowsStat() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const q = useFlows(workspace.id);
    return <Stat loading={q.isPending}>{q.data ? t('sections.overview.stats.activeFlows', { count: q.data.data.filter((f) => f.status === 'active').length }) : null}</Stat>;
}

function CampaignsStat() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const q = useCampaigns(workspace.id);
    return <Stat loading={q.isPending}>{q.data ? t('sections.overview.stats.campaigns', { count: q.data.length }) : null}</Stat>;
}

function WidgetsStat() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const q = useWidgets(workspace.id);
    return <Stat loading={q.isPending}>{q.data ? t('sections.overview.stats.widgets', { count: q.data.filter((w) => w.status === 'active').length }) : null}</Stat>;
}

function InsightsStat() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const q = useInsights(workspace.id, { status: 'open', category: '', severity: '' });
    return <Stat loading={q.isPending}>{q.data ? t('sections.overview.stats.openInsights', { count: q.data.meta.open }) : null}</Stat>;
}

function TeamStat() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const q = useMembers(workspace.id);
    return <Stat loading={q.isPending}>{q.data ? t('sections.overview.stats.members', { count: q.data.members.length }) : null}</Stat>;
}

function ConnectionsStat() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const c = workspace.connections;
    if (!c) {
        return null;
    }
    const site =
        c.site === 'connected' || c.site === 'disconnected'
            ? t('sections.overview.stats.siteConnected')
            : c.site === 'pending'
              ? t('sections.overview.stats.sitePending')
              : t('sections.overview.stats.siteMissing');
    const channels = c.channels.length > 0 ? t('sections.overview.stats.channels', { count: c.channels.length }) : t('sections.overview.stats.noChannels');
    return <Stat>{`${site} · ${channels}`}</Stat>;
}

/** Conteos baratos por entrada (cada uno con su consulta; solo se monta si la entrada está lista y el rol la ve). */
const STATS: Partial<Record<string, () => ReactNode>> = {
    contacts: () => <PeopleStat />,
    segments: () => <GroupsStat />,
    flows: () => <FlowsStat />,
    campaigns: () => <CampaignsStat />,
    onsite: () => <WidgetsStat />,
    insights: () => <InsightsStat />,
    team: () => <TeamStat />,
    connections: () => <ConnectionsStat />,
};

function ItemCard({ item, base, lock, quiet }: { item: NavItem; base: string; lock: NavLock | null; quiet: boolean }) {
    const { t } = useTranslation();
    const stat = lock ? null : STATS[item.key];
    return (
        <Link
            to={itemPath(base, item)}
            className={cn(
                'igr:group igr:flex igr:min-w-0 igr:flex-col igr:rounded-xl igr:border igr:bg-card igr:p-5 igr:shadow-xs igr:transition-[box-shadow,border-color] igr:hover:border-primary/25 igr:hover:shadow-md',
                lock && 'igr:bg-card/70',
            )}
        >
            <div className="igr:flex igr:items-start igr:justify-between igr:gap-3">
                <IconTile icon={item.icon} tone={lock ? 'slate' : (item.tone ?? 'indigo')} />
                {lock && quiet ? (
                    <span title={t(lockLabelKey(lock))} className="igr:text-muted-foreground">
                        <Lock className="igr:size-3.5" aria-hidden />
                        <span className="igr:sr-only">{t(lockLabelKey(lock))}</span>
                    </span>
                ) : lock ? (
                    <Badge variant={lock.kind === 'plan' ? 'brand' : 'outline'}>
                        <Lock className="igr:size-3" aria-hidden />
                        {lock.kind === 'plan' && lock.plan ? t('ui.locked.inPlan', { plan: lock.plan }) : t(lockLabelKey(lock))}
                    </Badge>
                ) : (
                    <ArrowUpRight
                        className="igr:size-4 igr:text-muted-foreground igr:opacity-0 igr:transition-opacity igr:group-hover:opacity-100 igr:group-focus-visible:opacity-100"
                        aria-hidden
                    />
                )}
            </div>
            <h2 className="igr:mt-4 igr:text-[15px] igr:font-semibold igr:tracking-tight igr:text-foreground">{t(`nav.${item.key}`)}</h2>
            <p className="igr:mt-1 igr:flex-1 igr:text-support igr:text-muted-foreground">{t(`sections.items.${item.key}`)}</p>
            <div className="igr:mt-4 igr:flex igr:min-h-5 igr:items-center igr:justify-between igr:gap-2">
                {stat ? stat() : <Stat>{lock ? null : t('sections.overview.ready')}</Stat>}
                <span className="igr:text-caption igr:font-medium igr:text-primary igr:opacity-0 igr:transition-opacity igr:group-hover:opacity-100 igr:group-focus-visible:opacity-100">
                    {t('sections.overview.open')}
                </span>
            </div>
        </Link>
    );
}

/** Aviso cuando toda la sección espera lo mismo (sin web, sin canal, fuera del plan), con el botón para resolverlo. */
function SectionLockNotice({ lock, base }: { lock: NavLock; base: string }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const fix = lockResolution(lock);
    const canFix = workspace.abilities.includes(fix.ability);
    const copy = lock.kind === 'site' ? 'connectFirst' : lock.kind === 'channel' ? 'channelFirst' : 'planFirst';
    return (
        <Callout
            tone="brand"
            className="igr:mb-6"
            title={t(`sections.overview.${copy}`)}
            action={
                canFix ? (
                    <Button asChild size="sm">
                        <Link to={`${base}/${fix.to}`}>
                            {t(fix.labelKey)}
                            <ArrowRight aria-hidden />
                        </Link>
                    </Button>
                ) : undefined
            }
        >
            {t(`sections.overview.${copy}Body`)} {canFix ? null : t(`ui.locked.askAdmin.${lock.kind}`)}
        </Callout>
    );
}

export function SectionHomePage() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const page = usePageContext();
    const primary = page ? PRIMARY[page.section.key] : undefined;
    const canPrimary = useCan(primary?.ability ?? 'viewWorkspace');
    if (!page || page.section.items.length === 0) {
        return <NotFoundPage />;
    }
    const { section, base } = page;
    const nav = navContext(workspace);
    const lock = sectionLock(section, nav);
    const name = t(`nav.sections.${section.key}`);

    return (
        <>
            <PageHeader
                title={name}
                description={t(`sections.${section.key}.purpose`)}
                icon={section.icon}
                tone={section.tone}
                actions={
                    primary && canPrimary && !lock ? (
                        <Button asChild>
                            <Link to={`${base}/${primary.to}`}>{t(`sections.overview.primary.${section.key}`)}</Link>
                        </Button>
                    ) : undefined
                }
            />
            {lock ? <SectionLockNotice lock={lock} base={base} /> : null}
            <div className="igr:grid igr:gap-4 igr:sm:grid-cols-2 igr:xl:grid-cols-3">
                {section.items.map((item) => (
                    // Si toda la sección espera lo mismo, el aviso de arriba lo explica: las tarjetas solo llevan el candado.
                    <ItemCard key={item.key} item={item} base={base} lock={itemLock(item, nav)} quiet={lock !== null} />
                ))}
            </div>
        </>
    );
}
