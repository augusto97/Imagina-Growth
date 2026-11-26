import { ArrowRight, ArrowUpRight, Lock, Sparkles } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import type { Ability, Workspace } from '@/api/types';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Callout } from '@/components/ui/callout';
import { IconTile } from '@/components/ui/icon-tile';
import { PageHeader } from '@/components/ui/page-header';
import { Skeleton } from '@/components/ui/skeleton';
import { StatCard, StatCardSkeleton } from '@/components/ui/stat-card';
import { lockResolution } from '@/components/layout/LockedPage';
import { itemLock, itemPath, navContext, sectionLock, sectionName, sectionPurpose, type NavItem, type NavLock } from '@/components/layout/nav';
import { usePageContext } from '@/components/layout/pageContext';
import { lockLabelKey } from '@/components/layout/SectionNav';
import { NotFoundPage } from '@/components/layout/guards';
import { useIntentPrefetch } from '@/app/prefetch';
import { useWorkspace } from '@/hooks/useWorkspace';
import { cn } from '@/lib/cn';
import { formatMoney, formatNumber, formatPercent } from '@/lib/format';
import { isSummarySection, nextParams, useSectionSummary, type SectionFigure, type SectionNext } from './api';

/*
 * Página de entrada de una sección (rediseño v2 §3): qué es la sección en una línea, lo que importa con cifras
 * (`GET sections/{section}`: solo números; los textos en `sectionEntry`) y lo primero que conviene hacer, con su botón;
 * debajo, lo que hay dentro con su «para qué sirve». Lo que todavía no puede servir lleva su candado y, si toda la
 * sección espera lo mismo (la web, un canal, el plan), el aviso de arriba lo explica en lugar de «Empieza por aquí».
 *
 * Para la estructura: las cifras y `next` las arma `App\Domain\Home\Queries\SectionSummary`; sus textos, por sección,
 * en `locales/es-CO/section-entry.json` (`sectionEntry.figures.<cifra>` y `sectionEntry.next.<tipo>`).
 */

/**
 * La acción principal de cada sección (ruta relativa a /w/:workspaceId y su texto en sections.overview.primary.<key>).
 * Configuración propone conectar la web mientras falte y, después, invitar al equipo.
 */
export function sectionPrimary(key: string, workspace: Pick<Workspace, 'connections'>): { to: string; label: string; ability: Ability } | null {
    switch (key) {
        case 'customers':
            return { to: 'contactos/importar', label: 'customers', ability: 'editContacts' };
        case 'conversations':
            return { to: 'bandeja', label: 'conversations', ability: 'useInbox' };
        case 'messages':
            return { to: 'flujos', label: 'messages', ability: 'viewDashboards' };
        case 'onsite':
            return { to: 'en-el-sitio', label: 'onsite', ability: 'manageMarketing' };
        case 'results':
            return { to: 'analitica', label: 'results', ability: 'viewDashboards' };
        case 'settings': {
            const site = workspace.connections?.site;
            return site === 'none' || site === 'pending'
                ? { to: site === 'pending' ? 'sitios' : 'sitios?conectar=1', label: 'settings', ability: 'manageSites' }
                : { to: 'equipo?invitar=1', label: 'settingsInvite', ability: 'manageMembers' };
        }
        default:
            return null;
    }
}

/** La cifra con su unidad, en el formato del país. */
export function formatFigure(figure: Pick<SectionFigure, 'value' | 'unit'>, currency: string, locale: string): string {
    switch (figure.unit) {
        case 'money':
            return formatMoney(figure.value, currency, locale);
        case 'percent':
            return formatPercent(figure.value / 100, locale, figure.value > 0 && figure.value < 10 ? 1 : 0);
        default:
            return formatNumber(figure.value, locale);
    }
}

/** El texto de una cifra: la del dinero cambia por sección («Generado por tus mensajes»). */
export function figureKey(section: string, key: string): string {
    return key === 'money' ? `sectionEntry.figures.money.${section}` : `sectionEntry.figures.${key}`;
}

function ItemCard({ item, base, lock, quiet }: { item: NavItem; base: string; lock: NavLock | null; quiet: boolean }) {
    const { t } = useTranslation();
    const intent = useIntentPrefetch();
    return (
        <Link
            to={itemPath(base, item)}
            {...intent(itemPath(base, item))}
            className={cn(
                'igr:group igr:flex igr:min-w-0 igr:items-start igr:gap-3 igr:rounded-xl igr:border igr:bg-card igr:p-4 igr:shadow-xs igr:transition-[box-shadow,border-color] igr:hover:border-primary/25 igr:hover:shadow-md igr:focus-visible:outline-none igr:focus-visible:ring-2 igr:focus-visible:ring-ring',
                lock && 'igr:bg-card/70',
            )}
        >
            <IconTile icon={item.icon} tone={lock ? 'slate' : (item.tone ?? 'indigo')} />
            <div className="igr:min-w-0 igr:flex-1">
                <div className="igr:flex igr:items-center igr:gap-2">
                    <h3 className="igr:min-w-0 igr:flex-1 igr:text-[15px] igr:font-semibold igr:tracking-tight igr:text-foreground">{t(`nav.${item.key}`)}</h3>
                    {lock ? (
                        <span title={t(lockLabelKey(lock))} className="igr:shrink-0 igr:text-muted-foreground">
                            <Lock className="igr:size-3.5" aria-hidden />
                            <span className="igr:sr-only">{t(lockLabelKey(lock))}</span>
                        </span>
                    ) : (
                        <ArrowUpRight
                            className="igr:size-4 igr:shrink-0 igr:text-muted-foreground igr:opacity-0 igr:transition-opacity igr:group-hover:opacity-100 igr:group-focus-visible:opacity-100"
                            aria-hidden
                        />
                    )}
                </div>
                <p className="igr:mt-1 igr:text-support igr:text-muted-foreground">{t(`sections.items.${item.key}`)}</p>
                {lock && !quiet ? (
                    <Badge variant={lock.kind === 'plan' ? 'brand' : 'outline'} className="igr:mt-2">
                        {lock.kind === 'plan' && lock.plan ? t('ui.locked.inPlan', { plan: lock.plan }) : t(lockLabelKey(lock))}
                    </Badge>
                ) : null}
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

/** «Empieza por aquí»: lo primero que conviene hacer en la sección, con por qué y su botón. */
function NextStep({ next, base }: { next: SectionNext; base: string }) {
    const { t } = useTranslation();
    const params = nextParams(next);
    return (
        <section
            aria-labelledby="section-next"
            className="igr:mb-6 igr:flex igr:flex-col igr:gap-4 igr:rounded-2xl igr:border igr:border-primary/15 igr:hero-surface igr:p-4 igr:sm:flex-row igr:sm:items-center igr:sm:p-5"
        >
            <IconTile icon={Sparkles} tone="brand" size="lg" className="igr:max-sm:hidden" />
            <div className="igr:min-w-0 igr:flex-1">
                <p className="igr:text-caption igr:font-semibold igr:tracking-wide igr:text-brand-soft-foreground igr:uppercase">{t('sectionEntry.start')}</p>
                <h2 id="section-next" className="igr:mt-0.5 igr:text-body igr:font-semibold igr:text-foreground">
                    {t(`sectionEntry.next.${next.kind}.title`, params)}
                </h2>
                <p className="igr:mt-0.5 igr:text-support igr:text-muted-foreground">{t(`sectionEntry.next.${next.kind}.why`, params)}</p>
            </div>
            <Button asChild className="igr:shrink-0 igr:max-sm:w-full">
                <Link to={`${base}/${next.to}`}>
                    {t(`sectionEntry.next.${next.kind}.action`, params)}
                    <ArrowRight aria-hidden />
                </Link>
            </Button>
        </section>
    );
}

export function SectionHomePage() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const page = usePageContext();
    const key = page?.section.key ?? '';
    const summary = useSectionSummary(workspace.id, key);
    const primary = page ? sectionPrimary(key, workspace) : null;
    if (!page || page.section.items.length === 0) {
        return <NotFoundPage />;
    }
    const { section, base } = page;
    const nav = navContext(workspace);
    const lock = sectionLock(section, nav);
    const name = sectionName(t, section.key, nav.vertical);
    const data = summary.data;
    const loading = isSummarySection(key) && summary.isPending;
    const figures = data?.figures ?? [];

    return (
        <>
            <PageHeader
                title={name}
                description={sectionPurpose(t, section.key, nav.vertical)}
                icon={section.icon}
                tone={section.tone}
                actions={
                    primary && workspace.abilities.includes(primary.ability) && !lock ? (
                        <Button asChild variant="outline">
                            <Link to={`${base}/${primary.to}`}>{t(`sections.overview.primary.${primary.label}`)}</Link>
                        </Button>
                    ) : undefined
                }
            />
            {lock ? (
                <SectionLockNotice lock={lock} base={base} />
            ) : loading ? (
                <Skeleton className="igr:mb-6 igr:h-[104px] igr:rounded-2xl igr:sm:h-[92px]" />
            ) : data?.next ? (
                <NextStep next={data.next} base={base} />
            ) : null}

            {loading ? (
                <div className="igr:mb-8 igr:grid igr:grid-cols-2 igr:gap-3 igr:lg:grid-cols-4">
                    {Array.from({ length: 4 }, (_, i) => (
                        <StatCardSkeleton key={i} />
                    ))}
                </div>
            ) : figures.length > 0 ? (
                <section aria-label={t('sectionEntry.figuresLabel', { section: name })} className={cn('igr:mb-8 igr:grid igr:grid-cols-2 igr:gap-3 igr:max-sm:[&>*:last-child:nth-child(odd)]:col-span-2', figures.length === 3 ? 'igr:lg:grid-cols-3' : 'igr:lg:grid-cols-4')}>
                    {figures.map((f) => (
                        <StatCard
                            key={f.key}
                            label={t(`${figureKey(key, f.key)}.label`)}
                            help={t(`${figureKey(key, f.key)}.help`)}
                            value={formatFigure(f, data?.currency ?? workspace.currency, workspace.locale)}
                            hint={f.unit === 'money' ? t('sectionEntry.thisMonth') : undefined}
                        />
                    ))}
                </section>
            ) : null}

            <h2 className="igr:mb-3 igr:text-body igr:font-semibold igr:text-foreground">{t('sectionEntry.inside', { section: name })}</h2>
            <div className="igr:grid igr:gap-3 igr:sm:grid-cols-2 igr:xl:grid-cols-3">
                {section.items.map((item) => (
                    // Si toda la sección espera lo mismo, el aviso de arriba lo explica: las tarjetas solo llevan el candado.
                    <ItemCard key={item.key} item={item} base={base} lock={itemLock(item, nav)} quiet={lock !== null} />
                ))}
            </div>
        </>
    );
}
