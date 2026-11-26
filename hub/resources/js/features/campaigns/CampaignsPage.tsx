import { ArrowRight, CalendarHeart, Copy, Plus } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, Navigate, useNavigate, useSearchParams } from 'react-router';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { EmptyState, ErrorState, FieldError, PageHeader } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TBody, TD, TH, THead, TR } from '@/components/ui/table';
import { useCommercialCalendar } from '@/features/calendar/api';
import { MomentCard } from '@/features/calendar/MomentCard';
import { errorMessage } from '@/features/contacts/errors';
import { CreateWithAiButton } from '@/features/copilot/CopilotDialog';
import { useCan, useWorkspace } from '@/hooks/useWorkspace';
import { formatDateTime, formatNumber } from '@/lib/format';
import { useCampaignActions, useCampaignOptions, useCampaigns, useCreateCampaign } from './api';
import { ChannelLabel, StatusBadge } from './labels';
import type { CampaignChannel, CampaignSummary } from './types';

/** El canal con el que empieza una campaña nueva: el primero conectado (WhatsApp, correo, SMS) o el correo. */
export function defaultChannel(active: readonly CampaignChannel[] | undefined): CampaignChannel {
    return (['whatsapp', 'email', 'sms'] as const).find((c) => active?.includes(c)) ?? 'email';
}

/**
 * Campañas (modulos.md M10): las próximas fechas para vender del calendario comercial con la campaña lista para crear,
 * y la lista de campañas. «Nueva campaña» pide solo el nombre: el asistente sigue con a quién, qué y cuándo.
 * `?vista=calendario` (de antes) lleva al calendario comercial.
 */
export function CampaignsPage() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const canManage = useCan('manageMarketing');
    const [search] = useSearchParams();
    const [creating, setCreating] = useState(false);

    if (search.get('vista') === 'calendario') {
        return <Navigate to={`/w/${workspace.id}/campanas/calendario`} replace />;
    }

    return (
        <>
            <PageHeader
                title={t('campaigns.title')}
                description={t('campaigns.description')}
                actions={
                    <>
                        {canManage ? <CreateWithAiButton kind="campaign" /> : null}
                        <Button asChild variant="outline">
                            <Link to={`/w/${workspace.id}/campanas/calendario`}>
                                <CalendarHeart />
                                {t('campaigns.moments.short')}
                            </Link>
                        </Button>
                        {canManage ? (
                            <Button onClick={() => setCreating(true)}>
                                <Plus />
                                {t('campaigns.new')}
                            </Button>
                        ) : null}
                    </>
                }
            />
            <div className="igr:space-y-8">
                <UpcomingMoments />
                <CampaignList onCreate={canManage ? () => setCreating(true) : undefined} />
            </div>
            {creating ? <NewCampaignDialog onClose={() => setCreating(false)} /> : null}
        </>
    );
}

/** Las próximas 3 fechas del calendario comercial, con la campaña lista para crear en un clic. */
function UpcomingMoments() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const calendar = useCommercialCalendar(workspace.id, 120);
    if (calendar.isError || (calendar.data && calendar.data.data.length === 0)) {
        return null;
    }
    return (
        <section aria-labelledby="upcoming-moments" className="igr:space-y-3">
            <div className="igr:flex igr:flex-wrap igr:items-end igr:justify-between igr:gap-2">
                <div>
                    <h2 id="upcoming-moments" className="igr:text-section igr:font-semibold">
                        {t('campaigns.moments.upcoming')}
                    </h2>
                    <p className="igr:text-support igr:text-muted-foreground">{t('campaigns.moments.upcomingHelp')}</p>
                </div>
                <Link to={`/w/${workspace.id}/campanas/calendario`} className="igr:inline-flex igr:items-center igr:gap-1 igr:text-support igr:font-medium igr:text-primary igr:hover:underline">
                    {t('campaigns.moments.seeAll')}
                    <ArrowRight className="igr:size-3.5" aria-hidden />
                </Link>
            </div>
            <div className="igr:grid igr:gap-3 igr:md:grid-cols-2 igr:xl:grid-cols-3">
                {calendar.isPending
                    ? Array.from({ length: 3 }, (_, i) => <Skeleton key={i} className="igr:h-56 igr:rounded-xl" />)
                    : calendar.data.data.slice(0, 3).map((m) => <MomentCard key={`${m.key}-${m.year}`} moment={m} compact />)}
            </div>
        </section>
    );
}

function CampaignList({ onCreate }: { onCreate: (() => void) | undefined }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const campaigns = useCampaigns(workspace.id);
    const base = `/w/${workspace.id}/campanas`;

    return (
        <section aria-labelledby="campaign-list" className="igr:space-y-3">
            <h2 id="campaign-list" className="igr:text-section igr:font-semibold">
                {t('campaigns.list.title')}
            </h2>
            {campaigns.isError ? (
                <ErrorState error={campaigns.error} onRetry={() => void campaigns.refetch()} />
            ) : campaigns.isPending ? (
                <Skeleton className="igr:h-48 igr:rounded-xl" />
            ) : campaigns.data.length === 0 ? (
                <EmptyState
                    illustration="messages"
                    title={t('campaigns.empty.title')}
                    description={t('campaigns.empty.description')}
                    action={
                        onCreate ? (
                            <Button onClick={onCreate}>
                                <Plus />
                                {t('campaigns.new')}
                            </Button>
                        ) : undefined
                    }
                    steps={[t('campaigns.empty.step1'), t('campaigns.empty.step2'), t('campaigns.empty.step3')]}
                />
            ) : (
                <Card className="igr:overflow-hidden">
                    <Table>
                        <THead>
                            <TR>
                                <TH>{t('campaigns.list.name')}</TH>
                                <TH>{t('campaigns.list.status')}</TH>
                                <TH className="igr:hidden igr:md:table-cell">{t('campaigns.list.when')}</TH>
                                <TH className="igr:text-right">{t('campaigns.list.recipients')}</TH>
                                <TH className="igr:w-10">
                                    <span className="igr:sr-only">{t('campaigns.list.actions')}</span>
                                </TH>
                            </TR>
                        </THead>
                        <TBody>
                            {campaigns.data.map((c) => (
                                <CampaignRow key={c.id} campaign={c} base={base} />
                            ))}
                        </TBody>
                    </Table>
                </Card>
            )}
        </section>
    );
}

function CampaignRow({ campaign: c, base }: { campaign: CampaignSummary; base: string }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const navigate = useNavigate();
    const canManage = useCan('manageMarketing');
    const actions = useCampaignActions(workspace.id, c.id);
    const to = c.status === 'draft' ? `${base}/${c.id}/editar` : `${base}/${c.id}`;
    const when = c.started_at ?? c.send_at;

    return (
        <TR className="igr:cursor-pointer igr:hover:bg-muted/40" onClick={() => void navigate(to)}>
            <TD className="igr:min-w-48">
                <Link to={to} className="igr:font-medium igr:hover:underline" onClick={(e) => e.stopPropagation()}>
                    {c.name}
                </Link>
                <div className="igr:mt-0.5 igr:flex igr:flex-wrap igr:items-center igr:gap-x-2 igr:text-caption">
                    <ChannelLabel channel={c.channel} />
                    {c.is_ab ? <Badge variant="outline">{c.winner_variant ? t('campaigns.list.abWinner', { variant: c.winner_variant }) : t('campaigns.list.ab')}</Badge> : null}
                </div>
            </TD>
            <TD>
                <StatusBadge status={c.status} />
            </TD>
            <TD className="igr:hidden igr:whitespace-nowrap igr:text-muted-foreground igr:md:table-cell">
                {when ? formatDateTime(when, workspace.locale, workspace.timezone) : c.send_mode === 'now' ? t('campaigns.list.whenNow') : '—'}
            </TD>
            <TD className="igr:text-right igr:tabular-nums">
                {c.recipients !== undefined && c.recipients !== null ? (
                    <span title={t('campaigns.list.sentOf', { sent: formatNumber(c.sent ?? 0, workspace.locale) })}>{formatNumber(c.recipients, workspace.locale)}</span>
                ) : c.recipients_estimate !== null ? (
                    <span className="igr:text-muted-foreground" title={t('campaigns.list.estimate')}>
                        ≈ {formatNumber(c.recipients_estimate, workspace.locale)}
                    </span>
                ) : (
                    '—'
                )}
            </TD>
            <TD>
                {canManage ? (
                    <Button
                        variant="ghost"
                        size="icon"
                        aria-label={t('campaigns.actions.duplicateNamed', { name: c.name })}
                        title={t('campaigns.actions.duplicate')}
                        onClick={(e) => {
                            e.stopPropagation();
                            actions.duplicate.mutate(undefined, { onSuccess: (copy) => void navigate(`${base}/${copy.id}/editar`) });
                        }}
                    >
                        <Copy />
                    </Button>
                ) : null}
            </TD>
        </TR>
    );
}

function NewCampaignDialog({ onClose }: { onClose: () => void }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const navigate = useNavigate();
    const options = useCampaignOptions(workspace.id);
    const create = useCreateCampaign(workspace.id);
    const [name, setName] = useState('');

    return (
        <Dialog open onOpenChange={(o) => !o && onClose()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{t('campaigns.new')}</DialogTitle>
                    <DialogDescription>{t('campaigns.newHelp')}</DialogDescription>
                </DialogHeader>
                <form
                    className="igr:space-y-4"
                    onSubmit={(e) => {
                        e.preventDefault();
                        create.mutate({ name: name.trim(), channel: defaultChannel(options.data?.active_channels) }, { onSuccess: (c) => void navigate(`/w/${workspace.id}/campanas/${c.id}/editar`) });
                    }}
                >
                    <div>
                        <Label htmlFor="cmp-name">{t('campaigns.fields.name')}</Label>
                        <Input id="cmp-name" className="igr:mt-1" value={name} maxLength={160} required autoFocus onChange={(e) => setName(e.target.value)} placeholder={t('campaigns.fields.namePlaceholder')} />
                    </div>
                    <FieldError message={create.isError ? errorMessage(create.error, t) : undefined} />
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={onClose}>
                            {t('common.cancel')}
                        </Button>
                        <Button type="submit" loading={create.isPending} disabled={name.trim() === '' || options.isPending}>
                            {t('campaigns.createAndContinue')}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
