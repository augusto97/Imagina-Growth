import { CalendarDays, Copy, Lightbulb, Megaphone, Plus } from 'lucide-react';
import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useNavigate, useSearchParams } from 'react-router';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input, Select } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { EmptyState, ErrorState, FieldError, PageHeader } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TBody, TD, TH, THead, TR } from '@/components/ui/table';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { errorMessage } from '@/features/contacts/errors';
import { useCan, useWorkspace } from '@/hooks/useWorkspace';
import { formatDate, formatDateTime, formatNumber } from '@/lib/format';
import { useCalendar, useCampaignActions, useCampaigns, useCreateCampaign } from './api';
import { ChannelLabel, CHANNEL_ICON, StatusBadge } from './labels';
import type { CalendarEvent, CampaignChannel, CampaignSummary } from './types';

const CHANNELS: CampaignChannel[] = ['whatsapp', 'email', 'sms'];

/** Mes (1–12) de una fecha ISO o Y-m-d en la zona dada. */
export function monthOf(value: string, timeZone: string): number {
    if (/^\d{4}-\d{2}-\d{2}$/.test(value)) return Number(value.slice(5, 7));
    const m = new Intl.DateTimeFormat('en-US', { timeZone, month: 'numeric' }).format(new Date(value));
    return Number(m);
}

/** Agrupa eventos comerciales y campañas por mes del año. */
export function groupByMonth(events: CalendarEvent[], campaigns: CampaignSummary[], timeZone: string) {
    const months = Array.from({ length: 12 }, (_, i) => ({ month: i + 1, events: [] as CalendarEvent[], campaigns: [] as CampaignSummary[] }));
    for (const e of events) months[monthOf(e.date, timeZone) - 1]?.events.push(e);
    for (const c of campaigns) {
        const when = c.started_at ?? c.send_at;
        if (when) months[monthOf(when, timeZone) - 1]?.campaigns.push(c);
    }
    return months;
}

export function CampaignsPage() {
    const { t } = useTranslation();
    const canManage = useCan('manageMarketing');
    const [search, setSearch] = useSearchParams();
    const view = search.get('vista') === 'calendario' ? 'calendar' : 'list';
    const [creating, setCreating] = useState<{ name: string } | null>(null);

    return (
        <>
            <PageHeader
                title={t('campaigns.title')}
                description={t('campaigns.description')}
                actions={
                    canManage ? (
                        <Button onClick={() => setCreating({ name: '' })}>
                            <Plus />
                            {t('campaigns.new')}
                        </Button>
                    ) : null
                }
            />
            <Tabs value={view} onValueChange={(v) => setSearch(v === 'calendar' ? { vista: 'calendario' } : {}, { replace: true })}>
                <TabsList className="igr:mb-4">
                    <TabsTrigger value="list">{t('campaigns.tabs.list')}</TabsTrigger>
                    <TabsTrigger value="calendar">{t('campaigns.tabs.calendar')}</TabsTrigger>
                </TabsList>
            </Tabs>
            {view === 'list' ? <CampaignList onCreate={canManage ? () => setCreating({ name: '' }) : undefined} /> : <CommercialCalendar onCreate={canManage ? (name) => setCreating({ name }) : undefined} />}
            {creating !== null && <NewCampaignDialog initialName={creating.name} onClose={() => setCreating(null)} />}
            <p className="igr:mt-6 igr:text-xs igr:text-muted-foreground" aria-disabled="true">
                {t('campaigns.copilotSoon')}
            </p>
        </>
    );
}

function CampaignList({ onCreate }: { onCreate: (() => void) | undefined }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const campaigns = useCampaigns(workspace.id);
    const base = `/w/${workspace.id}/campanas`;

    if (campaigns.isError) return <ErrorState error={campaigns.error} onRetry={() => void campaigns.refetch()} />;
    if (campaigns.isPending) {
        return (
            <div className="igr:space-y-2">
                {Array.from({ length: 4 }, (_, i) => (
                    <Skeleton key={i} className="igr:h-12" />
                ))}
            </div>
        );
    }
    if (campaigns.data.length === 0) {
        return (
            <EmptyState
                icon={Megaphone}
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
            />
        );
    }

    return (
        <Card>
            <Table>
                <THead>
                    <TR>
                        <TH>{t('campaigns.list.name')}</TH>
                        <TH>{t('campaigns.list.channel')}</TH>
                        <TH>{t('campaigns.list.status')}</TH>
                        <TH>{t('campaigns.list.when')}</TH>
                        <TH className="igr:text-right">{t('campaigns.list.recipients')}</TH>
                        <TH className="igr:w-10" />
                    </TR>
                </THead>
                <TBody>
                    {campaigns.data.map((c) => (
                        <CampaignRow key={c.id} campaign={c} base={base} />
                    ))}
                </TBody>
            </Table>
        </Card>
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
            <TD>
                <Link to={to} className="igr:font-medium igr:hover:underline" onClick={(e) => e.stopPropagation()}>
                    {c.name}
                </Link>
                {c.is_ab ? (
                    <Badge variant="outline" className="igr:ml-2">
                        {c.winner_variant ? t('campaigns.list.abWinner', { variant: c.winner_variant }) : t('campaigns.list.ab')}
                    </Badge>
                ) : null}
            </TD>
            <TD>
                <ChannelLabel channel={c.channel} />
            </TD>
            <TD>
                <StatusBadge status={c.status} />
            </TD>
            <TD className="igr:whitespace-nowrap igr:text-muted-foreground">
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
                        aria-label={t('campaigns.actions.duplicate')}
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

function CommercialCalendar({ onCreate }: { onCreate: ((name: string) => void) | undefined }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const thisYear = new Date().getFullYear();
    const [year, setYear] = useState(thisYear);
    const [country, setCountry] = useState(['CO', 'MX', 'VE'].includes(workspace.country) ? workspace.country : 'CO');
    const calendar = useCalendar(workspace.id, year, country);
    const months = useMemo(
        () => (calendar.data ? groupByMonth(calendar.data.events, calendar.data.campaigns, workspace.timezone) : []),
        [calendar.data, workspace.timezone],
    );
    const monthName = (m: number) => new Intl.DateTimeFormat(workspace.locale, { month: 'long', timeZone: 'UTC' }).format(new Date(Date.UTC(2026, m - 1, 15)));

    return (
        <div>
            <div className="igr:mb-4 igr:flex igr:flex-wrap igr:items-end igr:gap-3">
                <div>
                    <Label htmlFor="cal-year">{t('campaigns.calendar.year')}</Label>
                    <Select id="cal-year" value={year} onChange={(e) => setYear(Number(e.target.value))} className="igr:mt-1 igr:w-28">
                        {[thisYear - 1, thisYear, thisYear + 1].map((y) => (
                            <option key={y} value={y}>
                                {y}
                            </option>
                        ))}
                    </Select>
                </div>
                <div>
                    <Label htmlFor="cal-country">{t('campaigns.calendar.country')}</Label>
                    <Select id="cal-country" value={country} onChange={(e) => setCountry(e.target.value)} className="igr:mt-1 igr:w-40">
                        {['CO', 'MX', 'VE'].map((c) => (
                            <option key={c} value={c}>
                                {t(`campaigns.countries.${c}`)}
                            </option>
                        ))}
                    </Select>
                </div>
                <p className="igr:text-xs igr:text-muted-foreground igr:max-w-md">{t('campaigns.calendar.help')}</p>
            </div>
            {calendar.isError ? (
                <ErrorState error={calendar.error} onRetry={() => void calendar.refetch()} />
            ) : calendar.isPending ? (
                <div className="igr:grid igr:gap-3 igr:md:grid-cols-2 igr:xl:grid-cols-3">
                    {Array.from({ length: 6 }, (_, i) => (
                        <Skeleton key={i} className="igr:h-40" />
                    ))}
                </div>
            ) : (
                <div className="igr:grid igr:gap-3 igr:md:grid-cols-2 igr:xl:grid-cols-3">
                    {months.map(({ month, events, campaigns }) => (
                        <Card key={month} className={events.length === 0 && campaigns.length === 0 ? 'igr:opacity-70' : undefined}>
                            <CardHeader className="igr:pb-2">
                                <CardTitle className="igr:flex igr:items-center igr:gap-2 igr:text-[13px] igr:capitalize">
                                    <CalendarDays className="igr:size-4 igr:text-muted-foreground" aria-hidden="true" />
                                    {monthName(month)} {year}
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="igr:space-y-3">
                                {events.length === 0 && campaigns.length === 0 ? <p className="igr:text-xs igr:text-muted-foreground">{t('campaigns.calendar.nothing')}</p> : null}
                                {events.map((e) => (
                                    <EventItem key={e.key} event={e} year={year} onCreate={onCreate} />
                                ))}
                                {campaigns.map((c) => {
                                    const Icon = CHANNEL_ICON[c.channel];
                                    return (
                                        <Link key={c.id} to={`/w/${workspace.id}/campanas/${c.id}`} className="igr:flex igr:items-center igr:gap-2 igr:rounded-md igr:border igr:px-2 igr:py-1.5 igr:text-xs igr:hover:bg-muted/40">
                                            <Icon className="igr:size-3.5 igr:text-muted-foreground" aria-hidden="true" />
                                            <span className="igr:flex-1 igr:truncate">{c.name}</span>
                                            <StatusBadge status={c.status} />
                                        </Link>
                                    );
                                })}
                            </CardContent>
                        </Card>
                    ))}
                </div>
            )}
        </div>
    );
}

function EventItem({ event: e, year, onCreate }: { event: CalendarEvent; year: number; onCreate: ((name: string) => void) | undefined }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const [open, setOpen] = useState(false);
    const range = e.ends !== e.date ? `${formatDate(e.date, workspace.locale, 'UTC')} – ${formatDate(e.ends, workspace.locale, 'UTC')}` : formatDate(e.date, workspace.locale, 'UTC');
    return (
        <div className="igr:rounded-md igr:bg-muted/40 igr:p-2">
            <div className="igr:flex igr:items-start igr:justify-between igr:gap-2">
                <div>
                    <p className="igr:text-[13px] igr:font-medium">{e.name}</p>
                    <p className="igr:text-xs igr:text-muted-foreground">
                        {range} · {t('campaigns.calendar.startsCommunicating', { date: formatDate(e.starts_communicating, workspace.locale, 'UTC') })}
                    </p>
                </div>
                {onCreate ? (
                    <Button variant="outline" size="sm" onClick={() => onCreate(`${e.name} ${year}`)}>
                        {t('campaigns.calendar.create')}
                    </Button>
                ) : null}
            </div>
            <button type="button" className="igr:mt-1 igr:inline-flex igr:items-center igr:gap-1 igr:text-xs igr:text-primary igr:hover:underline" aria-expanded={open} onClick={() => setOpen((o) => !o)}>
                <Lightbulb className="igr:size-3.5" aria-hidden="true" />
                {open ? t('campaigns.calendar.hideIdeas') : t('campaigns.calendar.ideas', { count: e.ideas.length })}
            </button>
            {open ? (
                <ul className="igr:mt-1 igr:list-disc igr:space-y-0.5 igr:pl-5 igr:text-xs igr:text-muted-foreground">
                    {e.ideas.map((idea) => (
                        <li key={idea}>{idea}</li>
                    ))}
                </ul>
            ) : null}
        </div>
    );
}

function NewCampaignDialog({ initialName, onClose }: { initialName: string; onClose: () => void }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const navigate = useNavigate();
    const create = useCreateCampaign(workspace.id);
    const [name, setName] = useState(initialName);
    const [channel, setChannel] = useState<CampaignChannel>('whatsapp');

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
                        create.mutate({ name: name.trim(), channel }, { onSuccess: (c) => void navigate(`/w/${workspace.id}/campanas/${c.id}/editar`) });
                    }}
                >
                    <div>
                        <Label htmlFor="cmp-name">{t('campaigns.fields.name')}</Label>
                        <Input id="cmp-name" className="igr:mt-1" value={name} maxLength={160} required autoFocus onChange={(e) => setName(e.target.value)} placeholder={t('campaigns.fields.namePlaceholder')} />
                    </div>
                    <fieldset>
                        <legend className="igr:text-[13px] igr:font-medium">{t('campaigns.fields.channel')}</legend>
                        <div className="igr:mt-1 igr:grid igr:grid-cols-3 igr:gap-2">
                            {CHANNELS.map((c) => {
                                const Icon = CHANNEL_ICON[c];
                                return (
                                    <label key={c} className={`igr:flex igr:cursor-pointer igr:items-center igr:gap-2 igr:rounded-md igr:border igr:p-2 igr:text-[13px] igr:has-[:focus-visible]:ring-2 igr:has-[:focus-visible]:ring-ring ${channel === c ? 'igr:border-primary igr:bg-primary/5' : ''}`}>
                                        <input type="radio" name="channel" value={c} checked={channel === c} onChange={() => setChannel(c)} className="igr:sr-only" />
                                        <Icon className="igr:size-4" aria-hidden="true" />
                                        {t(`campaigns.channel.${c}`)}
                                    </label>
                                );
                            })}
                        </div>
                    </fieldset>
                    <FieldError message={create.isError ? errorMessage(create.error, t) : undefined} />
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={onClose}>
                            {t('common.cancel')}
                        </Button>
                        <Button type="submit" loading={create.isPending} disabled={name.trim() === ''}>
                            {t('campaigns.createAndContinue')}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
