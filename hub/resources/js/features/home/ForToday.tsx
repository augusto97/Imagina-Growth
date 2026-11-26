import { CircleAlert, CircleCheck, Hourglass, Lightbulb, MessageCircle, MessagesSquare, Plug, Rocket, TriangleAlert, Users, type LucideIcon } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { IconTile, type IconTone } from '@/components/ui/icon-tile';
import { cn } from '@/lib/cn';
import { isBlocking, todayLink } from './api';
import type { Home, TodayItem, TodayKind } from './types';

/*
 * «Para hoy» (docs/diseno/rediseno-v2.md §5.3): hasta 5 acciones priorizadas por el servidor (configuración pendiente,
 * estrategias con problemas, conversaciones sin responder, contactos por atender, recomendaciones y la estrategia que
 * más podría generar), cada una con por qué importa y su botón. Lo que bloquea lo demás va con el botón principal.
 */

const KIND_STYLE: Record<TodayKind, { icon: LucideIcon; tone: IconTone }> = {
    connect_site: { icon: Plug, tone: 'indigo' },
    import_running: { icon: Hourglass, tone: 'sky' },
    import_failed: { icon: TriangleAlert, tone: 'amber' },
    connect_channel: { icon: MessageCircle, tone: 'emerald' },
    strategy_attention: { icon: CircleAlert, tone: 'amber' },
    reply_conversations: { icon: MessagesSquare, tone: 'emerald' },
    contact_leads: { icon: Users, tone: 'rose' },
    insight: { icon: Lightbulb, tone: 'amber' },
    activate_strategy: { icon: Rocket, tone: 'violet' },
};

function Row({ item, base, setupOpen }: { item: TodayItem; base: string; setupOpen: boolean }) {
    const style = KIND_STYLE[item.kind];
    const blocking = isBlocking(item);
    return (
        <li className="igr:flex igr:flex-wrap igr:items-start igr:gap-x-3 igr:gap-y-2 igr:py-3.5 igr:first:pt-0 igr:last:pb-0">
            <IconTile icon={style.icon} tone={item.kind === 'insight' && item.severity === 'critical' ? 'rose' : style.tone} size="sm" className="igr:mt-0.5" />
            <div className="igr:min-w-0 igr:flex-1 igr:basis-40">
                <p className="igr:text-support igr:font-medium igr:text-foreground">{item.title}</p>
                <p className="igr:mt-0.5 igr:line-clamp-2 igr:text-caption igr:text-muted-foreground">{item.why}</p>
            </div>
            {/* El texto de una recomendación puede ser largo: el botón se acomoda en dos líneas en lugar de desbordar. */}
            <Button
                asChild
                size="sm"
                variant={blocking ? 'default' : 'outline'}
                className="igr:h-auto igr:min-h-8 igr:max-w-full igr:shrink-0 igr:py-1.5 igr:text-left igr:whitespace-normal igr:max-sm:ml-10 igr:max-sm:max-w-[calc(100%-2.5rem)] igr:sm:max-w-56"
            >
                <Link to={`${base}/${todayLink(item, setupOpen)}`}>{item.action}</Link>
            </Button>
        </li>
    );
}

export function ForToday({ home, base, className }: { home: Pick<Home, 'today' | 'setup'>; base: string; className?: string }) {
    const { t } = useTranslation();
    return (
        <Card className={cn('igr:flex igr:flex-col igr:p-5', className)}>
            <div className="igr:mb-4 igr:flex igr:items-baseline igr:justify-between igr:gap-2">
                <h2 className="igr:text-body igr:font-semibold igr:text-foreground">{t('home.today.title')}</h2>
                {home.today.length > 0 ? <span className="igr:text-caption igr:text-muted-foreground igr:tabular-nums">{t('home.today.count', { count: home.today.length })}</span> : null}
            </div>
            {home.today.length === 0 ? (
                <div className="igr:flex igr:flex-1 igr:flex-col igr:items-center igr:justify-center igr:py-6 igr:text-center">
                    <span className="igr:flex igr:size-10 igr:items-center igr:justify-center igr:rounded-full igr:bg-success/12 igr:text-success">
                        <CircleCheck className="igr:size-5" aria-hidden />
                    </span>
                    <p className="igr:mt-3 igr:text-support igr:font-medium igr:text-foreground">{t('home.today.emptyTitle')}</p>
                    <p className="igr:mt-1 igr:max-w-xs igr:text-caption igr:text-muted-foreground">{t('home.today.emptyBody')}</p>
                </div>
            ) : (
                <ul className="igr:divide-y">
                    {home.today.map((item) => (
                        <Row key={item.key} item={item} base={base} setupOpen={home.setup.visible} />
                    ))}
                </ul>
            )}
        </Card>
    );
}
