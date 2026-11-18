import { Activity, Check, CircleDollarSign, Plug, ShoppingBag, Truck, Users } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { useMembers, useOverview, useSites } from '@/api/queries';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { EmptyState, ErrorState, PageHeader } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { Tooltip } from '@/components/ui/tooltip';
import { useCan, useWorkspace } from '@/hooks/useWorkspace';
import { cn } from '@/lib/cn';
import { formatMoney, formatNumber } from '@/lib/format';

function Kpi({ icon: Icon, label, value, hint, help }: { icon: LucideIcon; label: string; value: ReactNode; hint?: ReactNode; help?: string }) {
    const content = (
        <Card className="igr:p-4">
            <div className="igr:flex igr:items-center igr:gap-2 igr:text-[13px] igr:text-muted-foreground">
                <Icon className="igr:size-4" />
                {label}
            </div>
            <div className="igr:mt-2 igr:text-2xl igr:font-semibold igr:tracking-tight igr:tabular-nums">{value}</div>
            {hint ? <div className="igr:mt-1 igr:text-xs igr:text-muted-foreground">{hint}</div> : null}
        </Card>
    );
    return help ? <Tooltip content={help}>{<div>{content}</div>}</Tooltip> : content;
}

function Step({ done, title, description, action }: { done: boolean; title: string; description: string; action?: ReactNode }) {
    return (
        <li className="igr:flex igr:items-start igr:gap-3 igr:py-3">
            <span
                className={cn(
                    'igr:mt-0.5 igr:flex igr:size-5 igr:shrink-0 igr:items-center igr:justify-center igr:rounded-full igr:border',
                    done ? 'igr:border-success igr:bg-success igr:text-white' : 'igr:border-input',
                )}
            >
                {done ? <Check className="igr:size-3" /> : null}
            </span>
            <div className="igr:min-w-0 igr:flex-1">
                <p className={cn('igr:text-[13px] igr:font-medium', done && 'igr:text-muted-foreground igr:line-through')}>{title}</p>
                <p className="igr:text-xs igr:text-muted-foreground">{description}</p>
            </div>
            {!done && action ? <div className="igr:shrink-0">{action}</div> : null}
        </li>
    );
}

export function HomePage() {
    const { t } = useTranslation();
    const { workspace, me } = useWorkspace();
    const overview = useOverview(workspace.id);
    const sites = useSites(workspace.id);
    const canManageSites = useCan('manageSites');
    const canManageMembers = useCan('manageMembers');
    const members = useMembers(workspace.id);
    const base = `/w/${workspace.id}`;

    if (overview.isError) {
        return <ErrorState error={overview.error} onRetry={() => void overview.refetch()} />;
    }

    const data = overview.data;
    const hasSites = (data?.sites.total ?? 0) > 0;
    const backfillDone = (sites.data ?? []).some((s) => s.backfill?.status === 'completed');
    const hasTeam = (members.data?.members.length ?? 0) + (members.data?.invitations.length ?? 0) > 1;

    return (
        <>
            <PageHeader title={t('home.title', { name: me.user.name.split(' ')[0] ?? '' })} description={t('home.description', { workspace: workspace.name })} />

            <div className="igr:grid igr:gap-3 igr:sm:grid-cols-2 igr:lg:grid-cols-4">
                {data ? (
                    <>
                        <Kpi
                            icon={CircleDollarSign}
                            label={t('home.revenue')}
                            value={formatMoney(data.revenue_ws, data.currency, workspace.locale)}
                            hint={t('home.revenueHint')}
                            help={t('home.revenueHelp')}
                        />
                        <Kpi
                            icon={ShoppingBag}
                            label={t('home.orders')}
                            value={formatNumber(data.orders.paid, workspace.locale)}
                            hint={t('home.ordersHint', { total: formatNumber(data.orders.total, workspace.locale) })}
                        />
                        <Kpi
                            icon={Users}
                            label={t('home.contacts')}
                            value={formatNumber(data.contacts.total, workspace.locale)}
                            hint={t('home.contactsHint', { customers: formatNumber(data.contacts.customers, workspace.locale) })}
                        />
                        <Kpi
                            icon={Activity}
                            label={t('home.events')}
                            value={data.events_24h === null ? '—' : formatNumber(data.events_24h, workspace.locale)}
                            hint={t('home.eventsHint')}
                        />
                    </>
                ) : (
                    Array.from({ length: 4 }, (_, i) => <Skeleton key={i} className="igr:h-[104px]" />)
                )}
            </div>

            {data && data.orders.cod_pending > 0 ? (
                <div className="igr:mt-3 igr:flex igr:items-center igr:gap-2 igr:rounded-lg igr:border igr:bg-card igr:px-4 igr:py-3 igr:text-[13px]">
                    <Truck className="igr:size-4 igr:text-muted-foreground" />
                    <span>{t('home.codPending', { count: data.orders.cod_pending })}</span>
                    <Tooltip content={t('home.codHelp')}>
                        <span className="igr:ml-1 igr:cursor-help igr:text-xs igr:text-muted-foreground igr:underline igr:decoration-dotted">{t('common.whatIsThis')}</span>
                    </Tooltip>
                </div>
            ) : null}

            <div className="igr:mt-6 igr:grid igr:gap-4 igr:lg:grid-cols-[1fr_340px]">
                {data && !hasSites ? (
                    <EmptyState
                        icon={Plug}
                        title={t('home.emptyTitle')}
                        description={t('home.emptyDescription')}
                        action={
                            canManageSites ? (
                                <Button asChild>
                                    <Link to={`${base}/sitios?conectar=1`}>
                                        <Plug />
                                        {t('sites.connect')}
                                    </Link>
                                </Button>
                            ) : (
                                <p className="igr:text-xs igr:text-muted-foreground">{t('home.askAdmin')}</p>
                            )
                        }
                    />
                ) : (
                    <Card>
                        <CardHeader>
                            <CardTitle>{t('home.nextTitle')}</CardTitle>
                            <CardDescription>{t('home.nextDescription')}</CardDescription>
                        </CardHeader>
                        <CardContent className="igr:text-[13px] igr:text-muted-foreground">
                            <p>{t('home.nextBody')}</p>
                            <Button asChild variant="outline" size="sm" className="igr:mt-3">
                                <Link to={`${base}/sitios`}>{t('home.viewSites')}</Link>
                            </Button>
                        </CardContent>
                    </Card>
                )}

                <Card>
                    <CardHeader>
                        <CardTitle>{t('home.stepsTitle')}</CardTitle>
                    </CardHeader>
                    <CardContent className="igr:pt-0">
                        <ul className="igr:divide-y">
                            <Step done={me.user.two_factor_enabled} title={t('home.step2fa')} description={t('home.step2faHelp')} action={<Button asChild size="sm" variant="outline"><Link to="/seguridad">{t('common.activate')}</Link></Button>} />
                            <Step
                                done={hasSites}
                                title={t('home.stepConnect')}
                                description={t('home.stepConnectHelp')}
                                action={canManageSites ? <Button asChild size="sm" variant="outline"><Link to={`${base}/sitios?conectar=1`}>{t('common.start')}</Link></Button> : undefined}
                            />
                            <Step done={backfillDone} title={t('home.stepBackfill')} description={t('home.stepBackfillHelp')} />
                            <Step
                                done={hasTeam}
                                title={t('home.stepTeam')}
                                description={t('home.stepTeamHelp')}
                                action={canManageMembers ? <Button asChild size="sm" variant="outline"><Link to={`${base}/equipo?invitar=1`}>{t('team.invite')}</Link></Button> : undefined}
                            />
                        </ul>
                    </CardContent>
                </Card>
            </div>
        </>
    );
}
