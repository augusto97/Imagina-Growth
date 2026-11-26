import { CalendarHeart, ChevronDown, Lightbulb, Mail, MessageCircle, Smartphone, Users } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Link, useNavigate } from 'react-router';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { IconTile } from '@/components/ui/icon-tile';
import { errorMessage } from '@/features/contacts/errors';
import { useCan, useWorkspace } from '@/hooks/useWorkspace';
import { cn } from '@/lib/cn';
import { toast } from '@/stores/toast';
import { useCreateCalendarCampaign } from './api';
import type { CalendarMoment } from './types';

const CHANNEL_ICON = { whatsapp: MessageCircle, email: Mail, sms: Smartphone } as const;

/** «lunes, 7 de diciembre» de una fecha Y-m-d (sin zona). */
export function momentDate(ymd: string, locale: string, withWeekday = false): string {
    const [y, m, d] = ymd.split('-').map(Number);
    return new Intl.DateTimeFormat(locale, { ...(withWeekday ? { weekday: 'long' as const } : {}), day: 'numeric', month: 'long', timeZone: 'UTC' }).format(new Date(Date.UTC(y ?? 2026, (m ?? 1) - 1, d ?? 1, 12)));
}

/** Hoy (Y-m-d) en la zona del espacio de trabajo. */
export function todayIn(timeZone: string): string {
    return new Intl.DateTimeFormat('en-CA', { timeZone, year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date());
}

/** «Envíala el sábado, 5 de diciembre» o, si ese día ya pasó, «Envíala cuanto antes». */
export function sendOnText(t: ReturnType<typeof useTranslation>['t'], sendOn: string, locale: string, timeZone: string): string {
    return sendOn < todayIn(timeZone) ? t('campaigns.moments.sendAsap', { date: momentDate(sendOn, locale) }) : t('campaigns.moments.sendOn', { date: momentDate(sendOn, locale, true) });
}

/** Crea la campaña sugerida de un momento en borrador (un clic) y lleva al asistente para revisarla y programarla. */
export function useCreateMomentCampaign() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const navigate = useNavigate();
    const create = useCreateCalendarCampaign(workspace.id);
    const run = (moment: CalendarMoment) =>
        create.mutate(
            { key: moment.key, year: moment.year },
            {
                onSuccess: (c) => {
                    toast.success(t('campaigns.moments.created', { name: c.name }), t('campaigns.moments.createdHelp'));
                    void navigate(`/w/${workspace.id}/campanas/${c.id}/editar?paso=review`);
                },
                onError: (e) => toast.error(errorMessage(e, t)),
            },
        );
    return { run, pending: create.isPending, key: create.variables?.key ?? null };
}

/**
 * Un momento del calendario comercial con su campaña sugerida: cuándo es, cuántos días faltan, cuándo conviene
 * enviarla, por qué canal, con qué plantilla y a quién, las ideas y el botón para dejarla lista en un clic.
 */
export function MomentCard({ moment: m, compact = false }: { moment: CalendarMoment; compact?: boolean }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const canManage = useCan('manageMarketing');
    const { run, pending, key } = useCreateMomentCampaign();
    const Icon = CHANNEL_ICON[m.campaign.channel];
    const range = m.ends !== m.date ? t('campaigns.moments.range', { from: momentDate(m.date, workspace.locale), to: momentDate(m.ends, workspace.locale) }) : momentDate(m.date, workspace.locale, true);
    const soon = m.days_left <= 14;

    return (
        <Card className={cn('igr:flex igr:flex-col igr:p-4', soon && 'igr:border-primary/30')}>
            <div className="igr:flex igr:items-start igr:gap-3">
                <IconTile icon={CalendarHeart} tone="rose" />
                <div className="igr:min-w-0 igr:flex-1">
                    <h3 className="igr:font-semibold igr:leading-snug igr:text-foreground">{m.name}</h3>
                    <p className="igr:text-support igr:text-muted-foreground">{range}</p>
                </div>
                <Badge variant={m.days_left <= 0 ? 'success' : soon ? 'brand' : 'secondary'} className="igr:shrink-0 igr:tabular-nums">
                    {m.days_left <= 0 ? t('campaigns.moments.now') : t('campaigns.moments.daysLeft', { count: m.days_left })}
                </Badge>
            </div>
            <dl className="igr:mt-3 igr:space-y-1.5 igr:rounded-lg igr:bg-muted/40 igr:p-3 igr:text-support">
                <div className="igr:flex igr:gap-2">
                    <dt className="igr:sr-only">{t('campaigns.moments.sendOnLabel')}</dt>
                    <dd className="igr:font-medium igr:text-foreground">{sendOnText(t, m.send_on, workspace.locale, workspace.timezone)}</dd>
                </div>
                <div className="igr:flex igr:items-start igr:gap-2">
                    <dt className="igr:sr-only">{t('campaigns.moments.channelLabel')}</dt>
                    <Icon className="igr:mt-0.5 igr:size-3.5 igr:shrink-0 igr:text-muted-foreground" aria-hidden />
                    <dd className="igr:min-w-0 igr:text-muted-foreground">
                        {t(`campaigns.channel.${m.campaign.channel}`)}
                        {m.campaign.template_name ? ` · ${m.campaign.template_name}` : ''}
                    </dd>
                </div>
                <div className="igr:flex igr:items-start igr:gap-2">
                    <dt className="igr:sr-only">{t('campaigns.moments.audienceLabel')}</dt>
                    <Users className="igr:mt-0.5 igr:size-3.5 igr:shrink-0 igr:text-muted-foreground" aria-hidden />
                    <dd className="igr:min-w-0 igr:text-muted-foreground">{m.campaign.segment_name ?? t(`campaigns.moments.everyone_${m.campaign.channel}`)}</dd>
                </div>
            </dl>
            {!compact && m.ideas.length > 0 ? (
                <details className="igr:group igr:mt-2">
                    <summary className="igr:inline-flex igr:cursor-pointer igr:list-none igr:items-center igr:gap-1 igr:rounded-sm igr:text-caption igr:font-medium igr:text-primary igr:focus-visible:outline-none igr:focus-visible:ring-2 igr:focus-visible:ring-ring">
                        <Lightbulb className="igr:size-3.5" aria-hidden />
                        {t('campaigns.moments.ideas', { count: m.ideas.length })}
                        <ChevronDown className="igr:size-3.5 igr:transition-transform igr:group-open:rotate-180" aria-hidden />
                    </summary>
                    <ul className="igr:mt-1.5 igr:list-disc igr:space-y-0.5 igr:pl-5 igr:text-caption igr:text-muted-foreground">
                        {m.ideas.map((idea) => (
                            <li key={idea}>{idea}</li>
                        ))}
                    </ul>
                </details>
            ) : null}
            <div className="igr:mt-auto igr:flex igr:flex-wrap igr:gap-2 igr:pt-4">
                {m.campaign_id ? (
                    <Button asChild size="sm" variant="outline">
                        <Link to={`/w/${workspace.id}/campanas/${m.campaign_id}`}>{t('campaigns.moments.open')}</Link>
                    </Button>
                ) : canManage ? (
                    <Button size="sm" loading={pending && key === m.key} onClick={() => run(m)}>
                        {t('campaigns.moments.create')}
                    </Button>
                ) : null}
            </div>
        </Card>
    );
}
