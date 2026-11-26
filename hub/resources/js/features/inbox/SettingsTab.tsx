import { BellRing, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import { Input, Select, Textarea } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ErrorState } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { Switch } from '@/components/ui/switch';
import { useWorkspace } from '@/hooks/useWorkspace';
import { toast } from '@/stores/toast';
import { errorMessage } from '@/features/contacts/errors';
import { useInboxAgents, useInboxSettings, usePushSubscribe, useQuickReplies, useQuickReplyActions, useSaveInboxSettings } from './api';
import { base64UrlToBytes } from './lib';
import { subscribePush } from './pwa';
import type { InboxSettings, Intent } from './types';

const RULE_INTENTS: Intent[] = ['purchase', 'support', 'complaint', 'other'];

/** Ajustes de la bandeja: asignación automática con reglas, respuestas rápidas y notificaciones push. */
export function SettingsTab() {
    return (
        <div className="igr:grid igr:gap-4 igr:lg:grid-cols-2">
            <AssignmentCard />
            <QuickRepliesCard />
            <PushCard />
        </div>
    );
}

function AssignmentCard() {
    const { workspace } = useWorkspace();
    const settings = useInboxSettings(workspace.id);
    if (settings.isError) return <ErrorState error={settings.error} onRetry={() => void settings.refetch()} />;
    if (settings.isPending) return <Skeleton className="igr:h-48" />;
    return (
        <AssignmentForm
            initial={{
                auto_assign: settings.data.auto_assign,
                rules: settings.data.rules,
            }}
        />
    );
}

function AssignmentForm({ initial }: { initial: Omit<InboxSettings, 'push_public_key'> }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const agents = useInboxAgents(workspace.id);
    const save = useSaveInboxSettings(workspace.id);
    const [draft, setDraft] = useState(initial);
    const firstAgent = agents.data?.data[0]?.id ?? '';

    return (
        <Card className="igr:flex igr:flex-col">
            <CardHeader>
                <CardTitle>{t('inbox.settings.assignment')}</CardTitle>
                <CardDescription>{t('inbox.settings.assignmentHelp')}</CardDescription>
            </CardHeader>
            <CardContent className="igr:flex-1 igr:space-y-4">
            <Switch checked={draft.auto_assign} onCheckedChange={(v) => setDraft({ ...draft, auto_assign: v })} label={t('inbox.settings.autoAssign')} description={t('inbox.settings.autoAssignHelp')} />
            <div className="igr:space-y-2">
                <p className="igr:text-support igr:font-medium">{t('inbox.settings.rules')}</p>
                <p className="igr:text-xs igr:text-muted-foreground">{t('inbox.settings.rulesHelp')}</p>
                {draft.rules.map((rule, i) => (
                    <div key={i} className="igr:grid igr:grid-cols-[minmax(0,1fr)_auto] igr:gap-1.5 igr:rounded-lg igr:border igr:p-2 igr:sm:flex igr:sm:items-center igr:sm:border-0 igr:sm:p-0">
                        <Select
                            aria-label={t('inbox.settings.ruleIntent')}
                            value={rule.intent ?? ''}
                            onChange={(e) =>
                                setDraft({
                                    ...draft,
                                    rules: draft.rules.map((r, k) =>
                                        k === i
                                            ? {
                                                  ...r,
                                                  intent: (e.target.value || undefined) as Intent | undefined,
                                              }
                                            : r,
                                    ),
                                })
                            }
                        >
                            <option value="">{t('inbox.settings.anyIntent')}</option>
                            {RULE_INTENTS.map((x) => (
                                <option key={x} value={x}>
                                    {t(`inbox.intent.${x}`)}
                                </option>
                            ))}
                        </Select>
                        <Input
                            aria-label={t('inbox.settings.ruleLabel')}
                            placeholder={t('inbox.settings.ruleLabel')}
                            value={rule.label ?? ''}
                            onChange={(e) =>
                                setDraft({
                                    ...draft,
                                    rules: draft.rules.map((r, k) =>
                                        k === i
                                            ? {
                                                  ...r,
                                                  label: e.target.value || undefined,
                                              }
                                            : r,
                                    ),
                                })
                            }
                        />
                        <Select
                            aria-label={t('inbox.settings.ruleUser')}
                            value={rule.user}
                            onChange={(e) =>
                                setDraft({
                                    ...draft,
                                    rules: draft.rules.map((r, k) => (k === i ? { ...r, user: e.target.value } : r)),
                                })
                            }
                        >
                            {(agents.data?.data ?? []).map((a) => (
                                <option key={a.id} value={a.id}>
                                    {a.name}
                                </option>
                            ))}
                        </Select>
                        <Button
                            size="icon"
                            variant="ghost"
                            aria-label={t('inbox.settings.removeRule')}
                            onClick={() =>
                                setDraft({
                                    ...draft,
                                    rules: draft.rules.filter((_, k) => k !== i),
                                })
                            }
                        >
                            <Trash2 aria-hidden />
                        </Button>
                    </div>
                ))}
                <Button
                    size="sm"
                    variant="outline"
                    disabled={firstAgent === ''}
                    onClick={() =>
                        setDraft({
                            ...draft,
                            rules: [...draft.rules, { intent: 'complaint', user: firstAgent }],
                        })
                    }
                >
                    <Plus aria-hidden />
                    {t('inbox.settings.addRule')}
                </Button>
            </div>
            </CardContent>
            <CardFooter className="igr:justify-end">
            <Button
                size="sm"
                loading={save.isPending}
                onClick={() =>
                    save.mutate(draft, {
                        onSuccess: () => toast.success(t('inbox.settings.saved')),
                        onError: (e) => toast.error(errorMessage(e, t)),
                    })
                }
            >
                {t('inbox.settings.save')}
            </Button>
            </CardFooter>
        </Card>
    );
}

function QuickRepliesCard() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const replies = useQuickReplies(workspace.id);
    const actions = useQuickReplyActions(workspace.id);
    const [form, setForm] = useState({ shortcut: '', title: '', body: '' });
    const fail = (e: unknown) => toast.error(errorMessage(e, t));

    return (
        <Card>
            <CardHeader>
                <CardTitle>{t('inbox.settings.quickReplies')}</CardTitle>
                <CardDescription>{t('inbox.settings.quickRepliesHelp')}</CardDescription>
            </CardHeader>
            <CardContent className="igr:space-y-3">
            {(replies.data?.data ?? []).length === 0 && !replies.isPending ? (
                <p className="igr:rounded-lg igr:bg-muted/60 igr:px-3 igr:py-2 igr:text-xs igr:text-muted-foreground">{t('inbox.settings.quickRepliesIdeas')}</p>
            ) : null}
            <ul className="igr:space-y-1.5">
                {(replies.data?.data ?? []).map((r) => (
                    <li key={r.id} className="igr:flex igr:items-start igr:gap-2 igr:rounded-lg igr:border igr:bg-card igr:p-2.5 igr:text-xs">
                        <div className="igr:min-w-0 igr:flex-1">
                            <p className="igr:font-medium">
                                /{r.shortcut} · {r.title}
                            </p>
                            <p className="igr:line-clamp-2 igr:text-muted-foreground">{r.body}</p>
                        </div>
                        <Button
                            size="icon"
                            variant="ghost"
                            aria-label={t('inbox.settings.removeReply', {
                                shortcut: r.shortcut,
                            })}
                            onClick={() => actions.remove.mutate(r.id, { onError: fail })}
                        >
                            <Trash2 aria-hidden />
                        </Button>
                    </li>
                ))}
            </ul>
            <form
                className="igr:space-y-2"
                onSubmit={(e) => {
                    e.preventDefault();
                    actions.create.mutate(form, {
                        onSuccess: () => setForm({ shortcut: '', title: '', body: '' }),
                        onError: fail,
                    });
                }}
            >
                <div className="igr:grid igr:grid-cols-2 igr:gap-2">
                    <div className="igr:space-y-1">
                        <Label htmlFor="qr-shortcut">{t('inbox.settings.shortcut')}</Label>
                        <Input
                            id="qr-shortcut"
                            placeholder={t('inbox.settings.shortcutPlaceholder')}
                            value={form.shortcut}
                            onChange={(e) =>
                                setForm({
                                    ...form,
                                    shortcut: e.target.value.toLowerCase(),
                                })
                            }
                        />
                    </div>
                    <div className="igr:space-y-1">
                        <Label htmlFor="qr-title">{t('inbox.settings.replyTitle')}</Label>
                        <Input id="qr-title" value={form.title} onChange={(e) => setForm({ ...form, title: e.target.value })} />
                    </div>
                </div>
                <div className="igr:space-y-1">
                    <Label htmlFor="qr-body">{t('inbox.settings.replyBody')}</Label>
                    <Textarea id="qr-body" rows={3} value={form.body} onChange={(e) => setForm({ ...form, body: e.target.value })} />
                </div>
                <Button type="submit" size="sm" loading={actions.create.isPending} disabled={form.shortcut.trim() === '' || form.body.trim() === ''}>
                    <Plus aria-hidden />
                    {t('inbox.settings.addReply')}
                </Button>
            </form>
            </CardContent>
        </Card>
    );
}

function PushCard() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const settings = useInboxSettings(workspace.id);
    const subscribe = usePushSubscribe(workspace.id);
    const key = settings.data?.push_public_key ?? null;
    const enable = async () => {
        if (!key) return;
        try {
            const sub = await subscribePush(base64UrlToBytes(key));
            if (!sub?.endpoint || !sub.keys?.p256dh || !sub.keys.auth) {
                toast.error(t('inbox.settings.pushDenied'));
                return;
            }
            subscribe.mutate(
                {
                    endpoint: sub.endpoint,
                    keys: { p256dh: sub.keys.p256dh, auth: sub.keys.auth },
                },
                { onSuccess: () => toast.success(t('inbox.settings.pushOn')) },
            );
        } catch {
            toast.error(t('inbox.settings.pushDenied'));
        }
    };
    return (
        <Card>
            <CardHeader>
                <CardTitle>{t('inbox.settings.push')}</CardTitle>
                <CardDescription>{key ? t('inbox.settings.pushHelp') : t('inbox.settings.pushUnavailable')}</CardDescription>
            </CardHeader>
            <CardContent>
                <Button size="sm" variant="outline" disabled={!key} onClick={() => void enable()}>
                    <BellRing aria-hidden />
                    {t('inbox.settings.pushEnable')}
                </Button>
            </CardContent>
        </Card>
    );
}
