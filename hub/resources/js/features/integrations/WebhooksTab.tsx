import { Plus, RotateCw, Send, Webhook } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Badge, StatusDot } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { EmptyState, ErrorState, FieldError } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TBody, TD, TH, THead, TR } from '@/components/ui/table';
import { errorMessage } from '@/features/contacts/errors';
import { formatDateTime } from '@/lib/format';
import { toast } from '@/stores/toast';
import {
    type DeliveryRow,
    type IntegrationsOverview,
    type WebhookRow,
    useDeleteWebhook,
    usePingWebhook,
    useReplayDelivery,
    useRotateSecret,
    useSaveWebhook,
    useWebhookDeliveries,
    useWebhooks,
} from './api';
import { SecretReveal } from './SecretReveal';

const STATUS_TONE = { active: 'success', failing: 'warning', paused: 'muted' } as const;
const STATE_VARIANT = { ok: 'success', failed: 'destructive', pending: 'warning', sending: 'secondary' } as const;

export function WebhooksTab({ ws, overview }: { ws: string; overview: IntegrationsOverview }) {
    const { t } = useTranslation();
    const hooks = useWebhooks(ws);
    const [editing, setEditing] = useState<WebhookRow | 'new' | null>(null);
    const [open, setOpen] = useState<string | null>(null);
    const [secret, setSecret] = useState<string | null>(null);
    const save = useSaveWebhook(ws);
    const remove = useDeleteWebhook(ws);
    const rotate = useRotateSecret(ws);
    const ping = usePingWebhook(ws);

    if (hooks.isError) return <ErrorState error={hooks.error} onRetry={() => void hooks.refetch()} />;
    if (hooks.isPending) return <Skeleton className="igr:h-40" />;

    return (
        <div className="igr:space-y-4">
            <div className="igr:flex igr:items-center igr:justify-between igr:gap-3">
                <p className="igr:text-[13px] igr:text-muted-foreground">{t('integrations.webhooks.description')}</p>
                <Button onClick={() => setEditing('new')}>
                    <Plus className="igr:size-4" aria-hidden />
                    {t('integrations.webhooks.create')}
                </Button>
            </div>
            {hooks.data.length === 0 ? (
                <Card>
                    <EmptyState icon={Webhook} title={t('integrations.webhooks.empty')} description={t('integrations.webhooks.emptyHint')} />
                </Card>
            ) : (
                hooks.data.map((h) => (
                    <Card key={h.id} data-testid="webhook-card">
                        <CardHeader className="igr:flex-row igr:items-start igr:justify-between igr:gap-3 igr:space-y-0">
                            <div className="igr:min-w-0">
                                <CardTitle className="igr:flex igr:items-center igr:gap-2">
                                    <StatusDot tone={STATUS_TONE[h.status]} />
                                    {h.name ?? h.url}
                                    <Badge variant={h.status === 'active' ? 'success' : h.status === 'failing' ? 'warning' : 'outline'}>{t(`integrations.webhooks.status.${h.status}`)}</Badge>
                                    {h.include_pii && <Badge variant="warning">{t('integrations.webhooks.withPii')}</Badge>}
                                </CardTitle>
                                <CardDescription className="igr:mt-1 igr:truncate igr:font-mono igr:text-xs">{h.url}</CardDescription>
                                <p className="igr:mt-1 igr:text-xs igr:text-muted-foreground">
                                    {t('integrations.webhooks.events', { count: h.events.length })}: {h.events.join(', ')}
                                    {' · '}
                                    {h.last_success_at ? t('integrations.webhooks.lastSuccess', { at: formatDateTime(h.last_success_at) }) : t('integrations.webhooks.noSuccess')}
                                    {h.consecutive_failures > 0 && ` · ${t('integrations.webhooks.failures', { count: h.consecutive_failures })}`}
                                </p>
                            </div>
                            <div className="igr:flex igr:shrink-0 igr:flex-wrap igr:justify-end igr:gap-1">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    loading={ping.isPending && ping.variables === h.id}
                                    onClick={() =>
                                        ping.mutate(h.id, {
                                            onSuccess: (r) =>
                                                r.data.state === 'ok'
                                                    ? toast.success(t('integrations.webhooks.pingOk', { code: r.data.status_code ?? '' }))
                                                    : toast.error(t('integrations.webhooks.pingFailed'), r.data.error ?? undefined),
                                            onError: (e) => toast.error(errorMessage(e, t)),
                                        })
                                    }
                                >
                                    <Send className="igr:size-3.5" aria-hidden />
                                    {t('integrations.webhooks.ping')}
                                </Button>
                                <Button variant="ghost" size="sm" onClick={() => setOpen(open === h.id ? null : h.id)}>
                                    {open === h.id ? t('integrations.webhooks.hideLog') : t('integrations.webhooks.showLog')}
                                </Button>
                                <Button variant="ghost" size="sm" onClick={() => setEditing(h)}>
                                    {t('integrations.edit')}
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    onClick={() => save.mutate({ id: h.id, status: h.status === 'paused' ? 'active' : 'paused' }, { onError: (e) => toast.error(errorMessage(e, t)) })}
                                >
                                    {h.status === 'paused' ? t('integrations.webhooks.resume') : t('integrations.webhooks.pause')}
                                </Button>
                                <Button variant="ghost" size="sm" onClick={() => rotate.mutate(h.id, { onSuccess: (r) => setSecret(r.secret) })}>
                                    {t('integrations.webhooks.rotate')}
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    className="igr:text-destructive"
                                    onClick={() => window.confirm(t('integrations.webhooks.deleteConfirm')) && remove.mutate(h.id, { onSuccess: () => toast.success(t('integrations.webhooks.deleted')) })}
                                >
                                    {t('integrations.delete')}
                                </Button>
                            </div>
                        </CardHeader>
                        {open === h.id && (
                            <CardContent>
                                <DeliveriesLog ws={ws} id={h.id} />
                            </CardContent>
                        )}
                    </Card>
                ))
            )}
            {editing !== null && <WebhookDialog key={editing === 'new' ? 'new' : editing.id} ws={ws} overview={overview} webhook={editing === 'new' ? null : editing} onClose={() => setEditing(null)} />}
            <Dialog open={secret !== null} onOpenChange={(o) => !o && setSecret(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{t('integrations.webhooks.newSecret')}</DialogTitle>
                        <DialogDescription>{t('integrations.webhooks.signatureHint')}</DialogDescription>
                    </DialogHeader>
                    {secret && <SecretReveal label={t('integrations.webhooks.secret')} value={secret} />}
                    <DialogFooter>
                        <Button onClick={() => setSecret(null)}>{t('integrations.done')}</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}

function DeliveriesLog({ ws, id }: { ws: string; id: string }) {
    const { t } = useTranslation();
    const [failedOnly, setFailedOnly] = useState(false);
    const log = useWebhookDeliveries(ws, id, failedOnly);
    const replay = useReplayDelivery(ws);

    return (
        <div className="igr:space-y-2">
            <div className="igr:flex igr:items-center igr:justify-between">
                <h3 className="igr:text-[13px] igr:font-semibold">{t('integrations.webhooks.log')}</h3>
                <label className="igr:flex igr:items-center igr:gap-1.5 igr:text-xs">
                    <input type="checkbox" checked={failedOnly} onChange={(e) => setFailedOnly(e.target.checked)} />
                    {t('integrations.webhooks.failedOnly')}
                </label>
            </div>
            {log.isError ? (
                <ErrorState error={log.error} onRetry={() => void log.refetch()} />
            ) : log.isPending ? (
                <Skeleton className="igr:h-20" />
            ) : log.data.data.length === 0 ? (
                <p className="igr:text-xs igr:text-muted-foreground">{t('integrations.webhooks.noDeliveries')}</p>
            ) : (
                <Table>
                    <THead>
                        <TR>
                            <TH>{t('integrations.webhooks.event')}</TH>
                            <TH>{t('integrations.webhooks.attempt')}</TH>
                            <TH>{t('integrations.webhooks.result')}</TH>
                            <TH>{t('integrations.webhooks.when')}</TH>
                            <TH className="igr:text-right">{t('integrations.actions')}</TH>
                        </TR>
                    </THead>
                    <TBody>
                        {log.data.data.map((d: DeliveryRow) => (
                            <TR key={d.id}>
                                <TD className="igr:font-mono igr:text-xs">{d.event}</TD>
                                <TD className="igr:text-xs">
                                    {d.attempt} · {t(`integrations.webhooks.kind.${d.kind}`)}
                                </TD>
                                <TD className="igr:text-xs">
                                    <Badge variant={STATE_VARIANT[d.state]}>{t(`integrations.webhooks.state.${d.state}`)}</Badge>{' '}
                                    {d.status_code !== null && <span className="igr:font-mono">{d.status_code}</span>}
                                    {d.duration_ms !== null && <span className="igr:text-muted-foreground"> · {d.duration_ms} ms</span>}
                                    {d.error && <div className="igr:mt-0.5 igr:text-muted-foreground">{t(`integrations.webhooks.errors.${d.error}`, { defaultValue: d.error })}</div>}
                                    {d.next_attempt_at && <div className="igr:mt-0.5 igr:text-muted-foreground">{t('integrations.webhooks.nextAttempt', { at: formatDateTime(d.next_attempt_at) })}</div>}
                                </TD>
                                <TD className="igr:text-xs igr:text-muted-foreground">{formatDateTime(d.created_at)}</TD>
                                <TD className="igr:text-right">
                                    {d.kind !== 'ping' && (
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            loading={replay.isPending && replay.variables === d.id}
                                            onClick={() =>
                                                replay.mutate(d.id, {
                                                    onSuccess: (r) => (r.data.state === 'ok' ? toast.success(t('integrations.webhooks.replayOk')) : toast.error(t('integrations.webhooks.replayFailed'))),
                                                    onError: (e) => toast.error(errorMessage(e, t)),
                                                })
                                            }
                                        >
                                            <RotateCw className="igr:size-3.5" aria-hidden />
                                            {t('integrations.webhooks.replay')}
                                        </Button>
                                    )}
                                </TD>
                            </TR>
                        ))}
                    </TBody>
                </Table>
            )}
        </div>
    );
}

function WebhookDialog({ ws, overview, webhook, onClose }: { ws: string; overview: IntegrationsOverview; webhook: WebhookRow | null; onClose: () => void }) {
    const { t } = useTranslation();
    const save = useSaveWebhook(ws);
    const [name, setName] = useState(webhook?.name ?? '');
    const [url, setUrl] = useState(webhook?.url ?? 'https://');
    const [events, setEvents] = useState<string[]>(webhook?.events ?? []);
    const [pii, setPii] = useState(webhook?.include_pii ?? false);
    const [secret, setSecret] = useState<string | null>(null);
    const toggle = (e: string, on: boolean) => setEvents((cur) => (on ? [...cur, e] : cur.filter((x) => x !== e)));
    const error = save.error ? errorMessage(save.error, t) : undefined;

    return (
        <Dialog open onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="igr:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>{webhook ? t('integrations.webhooks.edit') : t('integrations.webhooks.create')}</DialogTitle>
                    <DialogDescription>{t('integrations.webhooks.signatureHint')}</DialogDescription>
                </DialogHeader>
                {secret ? (
                    <SecretReveal label={t('integrations.webhooks.secret')} value={secret} />
                ) : (
                    <form
                        id="webhook-form"
                        className="igr:space-y-4"
                        onSubmit={(e) => {
                            e.preventDefault();
                            save.mutate(
                                { id: webhook?.id, name: name || null, url, events, include_pii: pii },
                                {
                                    onSuccess: (r) => {
                                        if (!webhook && r.secret) setSecret(r.secret);
                                        else {
                                            toast.success(t('integrations.saved'));
                                            onClose();
                                        }
                                    },
                                },
                            );
                        }}
                    >
                        <div className="igr:grid igr:gap-3 igr:sm:grid-cols-2">
                            <div className="igr:space-y-1.5">
                                <Label htmlFor="wh-name">{t('integrations.webhooks.name')}</Label>
                                <Input id="wh-name" value={name} onChange={(e) => setName(e.target.value)} placeholder="Make" maxLength={120} />
                            </div>
                            <div className="igr:space-y-1.5">
                                <Label htmlFor="wh-url">{t('integrations.webhooks.url')}</Label>
                                <Input id="wh-url" type="url" value={url} onChange={(e) => setUrl(e.target.value)} required maxLength={500} />
                            </div>
                        </div>
                        <fieldset className="igr:space-y-2">
                            <legend className="igr:text-[13px] igr:font-medium">{t('integrations.webhooks.pickEvents')}</legend>
                            <div className="igr:grid igr:max-h-72 igr:gap-3 igr:overflow-y-auto igr:rounded-md igr:border igr:p-3 igr:sm:grid-cols-2">
                                {Object.entries(overview.events).map(([group, list]) => {
                                    const all = `${group === 'custom' ? 'custom' : (list[0] ?? '').split('.')[0]}.*`;
                                    const groupAll = group !== 'custom' && list.every((e) => e.startsWith(all.slice(0, -1)));
                                    return (
                                        <div key={group} className="igr:space-y-1">
                                            <p className="igr:text-xs igr:font-semibold">{t(`integrations.groups.${group}`)}</p>
                                            {groupAll && (
                                                <label className="igr:flex igr:items-center igr:gap-1.5 igr:text-xs">
                                                    <input type="checkbox" checked={events.includes(all)} onChange={(e) => toggle(all, e.target.checked)} />
                                                    <span className="igr:font-mono">{all}</span>
                                                    <span className="igr:text-muted-foreground">{t('integrations.webhooks.allOfGroup')}</span>
                                                </label>
                                            )}
                                            {list.map((ev) => (
                                                <label key={ev} className="igr:flex igr:items-center igr:gap-1.5 igr:text-xs">
                                                    <input type="checkbox" checked={events.includes(ev)} disabled={groupAll && events.includes(all)} onChange={(e) => toggle(ev, e.target.checked)} />
                                                    <span className="igr:font-mono">{ev}</span>
                                                </label>
                                            ))}
                                        </div>
                                    );
                                })}
                            </div>
                        </fieldset>
                        <label className="igr:flex igr:items-start igr:gap-2 igr:rounded-md igr:border igr:p-2 igr:text-xs">
                            <input type="checkbox" className="igr:mt-0.5" checked={pii} onChange={(e) => setPii(e.target.checked)} />
                            <span>
                                <span className="igr:block igr:font-medium">{t('integrations.webhooks.includePii')}</span>
                                <span className="igr:text-muted-foreground">{t('integrations.webhooks.includePiiHint')}</span>
                            </span>
                        </label>
                        <FieldError message={error} />
                    </form>
                )}
                <DialogFooter>
                    {secret ? (
                        <Button onClick={onClose}>{t('integrations.done')}</Button>
                    ) : (
                        <>
                            <Button variant="outline" onClick={onClose}>
                                {t('integrations.cancel')}
                            </Button>
                            <Button type="submit" form="webhook-form" loading={save.isPending} disabled={events.length === 0 || url.length < 10}>
                                {t('integrations.save')}
                            </Button>
                        </>
                    )}
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
