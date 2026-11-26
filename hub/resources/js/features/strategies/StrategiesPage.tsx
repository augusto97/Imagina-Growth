import { ArrowRight, Bot, CalendarDays, Check, Gift, Lock, MousePointerClick, RadioTower, Repeat, Rocket, ShoppingCart, Star, type LucideIcon } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Callout } from '@/components/ui/callout';
import { HeroCard } from '@/components/ui/hero-card';
import { IconTile, type IconTone } from '@/components/ui/icon-tile';
import { PageHeader } from '@/components/ui/page-header';
import { itemLock, itemPath, navContext, visibleSections } from '@/components/layout/nav';
import { lockLabelKey } from '@/components/layout/SectionNav';
import { useWorkspace } from '@/hooks/useWorkspace';

/*
 * Estrategias, versión provisional (docs/diseno/rediseno-v2.md §6): el catálogo por objetivo con los ejemplos de cada
 * uno y el enlace a donde se configura hoy. La reemplaza la pantalla de estrategias con su API (`GET strategies`,
 * activar y pausar). Respeta lo que el rol ve y los bloqueos de la navegación.
 */
interface Goal {
    key: string;
    icon: LucideIcon;
    tone: IconTone;
    /** La entrada del menú donde se configura hoy (nav.ts). */
    item: string;
}

const GOALS: Goal[] = [
    { key: 'recover', icon: ShoppingCart, tone: 'orange', item: 'flows' },
    { key: 'repeat', icon: Repeat, tone: 'indigo', item: 'flows' },
    { key: 'loyalty', icon: Gift, tone: 'rose', item: 'loyalty' },
    { key: 'capture', icon: MousePointerClick, tone: 'teal', item: 'onsite' },
    { key: 'reputation', icon: Star, tone: 'amber', item: 'reviews' },
    { key: 'service', icon: Bot, tone: 'violet', item: 'agent' },
    { key: 'ads', icon: RadioTower, tone: 'sky', item: 'measurement' },
    { key: 'services', icon: CalendarDays, tone: 'emerald', item: 'leads' },
];

export function StrategiesPage() {
    const { t } = useTranslation();
    const { workspace, me } = useWorkspace();
    const base = `/w/${workspace.id}`;
    const ctx = navContext(workspace);
    const items = visibleSections(ctx, me.user.is_platform_admin).flatMap((s) => s.items);

    return (
        <>
            <PageHeader title={t('nav.sections.strategies')} description={t('sections.strategies.purpose')} icon={Rocket} tone="violet" />
            <HeroCard
                eyebrow={t('sections.strategiesPreview.eyebrow')}
                title={t('sections.strategiesPreview.title')}
                description={t('sections.strategiesPreview.description')}
                illustration="strategies"
                className="igr:mb-6"
            >
                <Callout tone="brand" title={t('sections.strategiesPreview.soonTitle')} className="igr:max-w-2xl igr:bg-card/80">
                    {t('sections.strategiesPreview.soonBody')}
                </Callout>
            </HeroCard>
            <div className="igr:grid igr:gap-4 igr:sm:grid-cols-2 igr:xl:grid-cols-3 igr:2xl:grid-cols-4">
                {GOALS.map((goal) => {
                    const item = items.find((i) => i.key === goal.item);
                    if (!item) {
                        return null;
                    }
                    const lock = itemLock(item, ctx);
                    const examples = t(`sections.strategiesPreview.goals.${goal.key}.items`, { returnObjects: true }) as unknown;
                    return (
                        <article key={goal.key} className="igr:flex igr:min-w-0 igr:flex-col igr:rounded-xl igr:border igr:bg-card igr:p-5 igr:shadow-xs">
                            <div className="igr:flex igr:items-start igr:justify-between igr:gap-3">
                                <IconTile icon={goal.icon} tone={goal.tone} />
                                {lock ? (
                                    <Badge variant={lock.kind === 'plan' ? 'brand' : 'outline'}>
                                        <Lock className="igr:size-3" aria-hidden />
                                        {t(lockLabelKey(lock))}
                                    </Badge>
                                ) : null}
                            </div>
                            <h2 className="igr:mt-4 igr:text-[15px] igr:font-semibold igr:tracking-tight">{t(`sections.strategiesPreview.goals.${goal.key}.title`)}</h2>
                            <p className="igr:mt-1 igr:text-support igr:text-muted-foreground">{t(`sections.strategiesPreview.goals.${goal.key}.description`)}</p>
                            <ul className="igr:mt-4 igr:flex-1 igr:space-y-1.5">
                                {(Array.isArray(examples) ? (examples as string[]) : []).map((example) => (
                                    <li key={example} className="igr:flex igr:items-start igr:gap-2 igr:text-support">
                                        <Check className="igr:mt-0.5 igr:size-3.5 igr:shrink-0 igr:text-success" aria-hidden />
                                        <span>{example}</span>
                                    </li>
                                ))}
                            </ul>
                            <Button asChild variant="outline" size="sm" className="igr:mt-5 igr:self-start">
                                <Link to={itemPath(base, item)}>
                                    {t('sections.strategiesPreview.configure')}
                                    <ArrowRight aria-hidden />
                                </Link>
                            </Button>
                        </article>
                    );
                })}
            </div>
        </>
    );
}
