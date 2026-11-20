import { useQueryClient } from '@tanstack/react-query';
import { History, Mail, MessageCircle, ShieldCheck, ShoppingCart, TriangleAlert } from 'lucide-react';
import { useId, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useUpdateWorkspace } from '@/api/queries';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import { Input, Textarea } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ErrorState, FieldError, PageHeader } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { useCan, useWorkspace } from '@/hooks/useWorkspace';
import { formatDateTime } from '@/lib/format';
import { cn } from '@/lib/cn';
import { toast } from '@/stores/toast';
import { errorMessage } from '@/features/contacts/errors';
import { DataRequestsTable } from '@/features/privacy/DataRequestsTable';
import { useDataRequests } from '@/features/privacy/api';
import {
    type ConsentMode,
    type ConsentText,
    type ConsentTextVersion,
    type EarlyCaptureState,
    TEXT_MAX,
    TEXT_MIN,
    useConsentMode,
    useConsentSettings,
    useEarlyCapture,
    usePublishConsentText,
} from './api';

/** Ajustes de consentimiento y derechos de los titulares (cumplimiento.md §2, §3 y §9). */
export function ConsentPage() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const canManage = useCan('manageWorkspace');
    const canExport = useCan('exportContacts');
    const canDelete = useCan('deleteContacts');
    const settings = useConsentSettings(workspace.id);
    const requests = useDataRequests(workspace.id, canExport);

    return (
        <>
            <PageHeader title={t('consent.title')} description={t('consent.description')} />
            {settings.isError ? (
                <ErrorState error={settings.error} onRetry={() => void settings.refetch()} />
            ) : settings.isPending ? (
                <div className="igr:grid igr:gap-4 igr:md:grid-cols-2">
                    <Skeleton className="igr:h-56" />
                    <Skeleton className="igr:h-56" />
                </div>
            ) : (
                <div className="igr:space-y-6">
                    {!canManage && <p className="igr:text-[13px] igr:text-muted-foreground">{t('consent.readOnly')}</p>}
                    <section aria-labelledby="consent-texts">
                        <h2 id="consent-texts" className="igr:mb-1 igr:text-sm igr:font-semibold">
                            {t('consent.texts.title')}
                        </h2>
                        <p className="igr:mb-3 igr:text-[13px] igr:text-muted-foreground">{t('consent.texts.description')}</p>
                        <div className="igr:grid igr:gap-4 igr:md:grid-cols-2">
                            {settings.data.texts.map((text) => (
                                <TextCard key={text.channel} text={text} history={settings.data.history[text.channel] ?? []} canManage={canManage} />
                            ))}
                        </div>
                    </section>

                    <div className="igr:grid igr:gap-4 igr:lg:grid-cols-2">
                        <ModeCard current={settings.data.mode} canManage={canManage} />
                        <PolicyCard current={settings.data.privacy_policy_url} canManage={canManage} />
                    </div>

                    <EarlyCaptureCard state={settings.data.early_capture} minPlugin={settings.data.plugin_min_for_early_capture} canManage={canManage} />

                    {canExport && (
                        <section aria-labelledby="data-requests">
                            <Card>
                                <CardHeader>
                                    <CardTitle id="data-requests">{t('consent.requests.title')}</CardTitle>
                                    <CardDescription>{t('consent.requests.description')}</CardDescription>
                                </CardHeader>
                                {requests.isError ? (
                                    <CardContent>
                                        <ErrorState error={requests.error} onRetry={() => void requests.refetch()} />
                                    </CardContent>
                                ) : requests.isPending ? (
                                    <CardContent>
                                        <Skeleton className="igr:h-24" />
                                    </CardContent>
                                ) : (
                                    <div className={requests.data.length === 0 ? 'igr:p-4 igr:pt-0' : undefined}>
                                        <DataRequestsTable requests={requests.data} canExport={canExport} canDelete={canDelete} />
                                    </div>
                                )}
                            </Card>
                        </section>
                    )}
                </div>
            )}
        </>
    );
}

function TextCard({ text, history, canManage }: { text: ConsentText; history: ConsentTextVersion[]; canManage: boolean }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const publish = usePublishConsentText(workspace.id);
    const [editing, setEditing] = useState(false);
    const [showHistory, setShowHistory] = useState(false);
    const [body, setBody] = useState(text.label);
    const fieldId = useId();
    const Icon = text.channel === 'email' ? Mail : MessageCircle;
    const channel = t(`contacts.consentChannels.${text.channel}`, { defaultValue: text.channel });
    const length = body.trim().length;
    const unchanged = body.trim() === text.label.trim();
    const older = history.filter((h) => h.version !== text.version);

    return (
        <Card className="igr:flex igr:flex-col">
            <CardHeader className="igr:flex-row igr:items-start igr:justify-between igr:gap-2">
                <div>
                    <CardTitle className="igr:flex igr:items-center igr:gap-2">
                        <Icon className="igr:size-4 igr:text-muted-foreground" />
                        {channel}
                    </CardTitle>
                    <CardDescription>
                        {text.published_at
                            ? t('consent.texts.publishedOn', { date: formatDateTime(text.published_at, workspace.locale, workspace.timezone) })
                            : t('consent.texts.default')}
                    </CardDescription>
                </div>
                <Badge variant="secondary" className="igr:font-mono">
                    {t('consent.texts.version', { version: text.version })}
                </Badge>
            </CardHeader>
            <CardContent className="igr:flex-1 igr:space-y-3">
                {editing ? (
                    <div className="igr:space-y-1.5">
                        <Label htmlFor={fieldId}>{t('consent.texts.newVersion')}</Label>
                        <Textarea id={fieldId} rows={4} maxLength={TEXT_MAX} value={body} onChange={(e) => setBody(e.target.value)} />
                        <div className="igr:flex igr:justify-between igr:text-xs igr:text-muted-foreground">
                            <span>{t('consent.texts.lengthHelp', { min: TEXT_MIN })}</span>
                            <span className={cn('igr:tabular-nums', (length < TEXT_MIN || length > TEXT_MAX) && 'igr:text-destructive')}>
                                {length}/{TEXT_MAX}
                            </span>
                        </div>
                        <FieldError message={publish.isError ? errorMessage(publish.error, t) : undefined} />
                        <p className="igr:flex igr:items-start igr:gap-1.5 igr:rounded-md igr:bg-muted/60 igr:p-2.5 igr:text-xs igr:text-muted-foreground">
                            <TriangleAlert className="igr:mt-0.5 igr:size-3.5 igr:shrink-0" />
                            {t('consent.texts.versionWarning')}
                        </p>
                    </div>
                ) : null}
                <div>
                    <p className="igr:mb-1.5 igr:text-[11px] igr:font-medium igr:uppercase igr:tracking-wide igr:text-muted-foreground">{t('consent.texts.preview')}</p>
                    <label className="igr:flex igr:items-start igr:gap-2 igr:rounded-md igr:border igr:bg-background igr:p-3 igr:text-[13px]">
                        <input type="checkbox" className="igr:mt-0.5 igr:size-4 igr:accent-[var(--primary)]" tabIndex={-1} aria-hidden="true" readOnly />
                        <span>{editing ? body : text.label}</span>
                    </label>
                </div>
                {older.length > 0 && (
                    <div>
                        <button
                            type="button"
                            aria-expanded={showHistory}
                            onClick={() => setShowHistory(!showHistory)}
                            className="igr:inline-flex igr:items-center igr:gap-1.5 igr:rounded igr:text-xs igr:text-muted-foreground igr:outline-none igr:hover:text-foreground igr:focus-visible:ring-2 igr:focus-visible:ring-ring"
                        >
                            <History className="igr:size-3.5" />
                            {t('consent.texts.history', { count: older.length })}
                        </button>
                        {showHistory && (
                            <ol className="igr:mt-2 igr:space-y-2 igr:border-l igr:pl-3">
                                {older.map((h) => (
                                    <li key={h.version} className="igr:text-xs">
                                        <p className="igr:font-medium">
                                            {t('consent.texts.version', { version: h.version })}
                                            <span className="igr:font-normal igr:text-muted-foreground">
                                                {' '}
                                                · {formatDateTime(h.published_at, workspace.locale, workspace.timezone)}
                                            </span>
                                        </p>
                                        <p className="igr:mt-0.5 igr:text-muted-foreground">{h.label}</p>
                                    </li>
                                ))}
                            </ol>
                        )}
                    </div>
                )}
            </CardContent>
            {canManage && (
                <CardFooter className="igr:justify-end">
                    {editing ? (
                        <>
                            <Button
                                variant="ghost"
                                onClick={() => {
                                    setBody(text.label);
                                    setEditing(false);
                                    publish.reset();
                                }}
                            >
                                {t('common.cancel')}
                            </Button>
                            <Button
                                disabled={unchanged || length < TEXT_MIN || length > TEXT_MAX}
                                loading={publish.isPending}
                                onClick={() =>
                                    publish.mutate(
                                        { channel: text.channel, body: body.trim() },
                                        {
                                            onSuccess: () => {
                                                setEditing(false);
                                                toast.success(t('consent.texts.published', { channel }));
                                            },
                                        },
                                    )
                                }
                            >
                                {t('consent.texts.publish')}
                            </Button>
                        </>
                    ) : (
                        <Button variant="outline" onClick={() => setEditing(true)}>
                            {t('consent.texts.publishNew')}
                        </Button>
                    )}
                </CardFooter>
            )}
        </Card>
    );
}

function ModeCard({ current, canManage }: { current: ConsentMode; canManage: boolean }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const save = useConsentMode(workspace.id);
    const [mode, setMode] = useState<ConsentMode>(current);
    // Pasar a informado relaja la protección (queda en la auditoría).
    const relaxing = current !== 'informed' && mode === 'informed';

    return (
        <Card className="igr:flex igr:flex-col">
            <CardHeader>
                <CardTitle className="igr:flex igr:items-center igr:gap-2">
                    <ShieldCheck className="igr:size-4 igr:text-muted-foreground" />
                    {t('consent.mode.title')}
                </CardTitle>
                <CardDescription>{t('consent.mode.description')}</CardDescription>
            </CardHeader>
            <CardContent className="igr:flex-1 igr:space-y-2">
                <div role="radiogroup" aria-label={t('consent.mode.title')} className="igr:space-y-2">
                    {(['auto', 'strict', 'informed'] as const).map((m) => (
                        <button
                            key={m}
                            type="button"
                            role="radio"
                            aria-checked={mode === m}
                            disabled={!canManage}
                            onClick={() => setMode(m)}
                            className={cn(
                                'igr:flex igr:w-full igr:items-start igr:gap-3 igr:rounded-lg igr:border igr:p-3 igr:text-left igr:outline-none igr:transition-colors igr:focus-visible:ring-2 igr:focus-visible:ring-ring igr:disabled:cursor-default',
                                mode === m ? 'igr:border-primary igr:bg-primary/5' : 'igr:enabled:hover:bg-accent',
                            )}
                        >
                            <span
                                aria-hidden="true"
                                className={cn('igr:mt-0.5 igr:size-4 igr:shrink-0 igr:rounded-full igr:border', mode === m && 'igr:border-[5px] igr:border-primary')}
                            />
                            <span>
                                <span className="igr:flex igr:items-center igr:gap-2 igr:text-[13px] igr:font-medium">
                                    {t(`consent.mode.${m}`)}
                                    {m === 'auto' && <Badge variant="outline">{t('consent.mode.recommended')}</Badge>}
                                    {current === m && <Badge variant="secondary">{t('consent.mode.current')}</Badge>}
                                </span>
                                <span className="igr:mt-0.5 igr:block igr:text-xs igr:text-muted-foreground">{t(`consent.mode.${m}Help`)}</span>
                            </span>
                        </button>
                    ))}
                </div>
                {relaxing && (
                    <p className="igr:flex igr:items-start igr:gap-1.5 igr:text-xs igr:text-warning">
                        <TriangleAlert className="igr:mt-0.5 igr:size-3.5 igr:shrink-0" />
                        {t('consent.mode.relaxWarning')}
                    </p>
                )}
                <FieldError message={save.isError ? errorMessage(save.error, t) : undefined} />
            </CardContent>
            {canManage && (
                <CardFooter className="igr:justify-end">
                    <Button disabled={mode === current} loading={save.isPending} onClick={() => save.mutate(mode, { onSuccess: () => toast.success(t('consent.mode.saved')) })}>
                        {t('common.save')}
                    </Button>
                </CardFooter>
            )}
        </Card>
    );
}

/** Captura temprana del checkout (cumplimiento.md §3.2): encenderla exige aceptar la responsabilidad y queda en la auditoría. */
function EarlyCaptureCard({ state, minPlugin, canManage }: { state: EarlyCaptureState; minPlugin: string; canManage: boolean }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const save = useEarlyCapture(workspace.id);
    const [accepted, setAccepted] = useState(false);
    const acceptId = useId();

    return (
        <section aria-labelledby="early-capture">
            <Card>
                <CardHeader className="igr:flex-row igr:items-start igr:justify-between igr:gap-2">
                    <div>
                        <CardTitle id="early-capture" className="igr:flex igr:items-center igr:gap-2">
                            <ShoppingCart className="igr:size-4 igr:text-muted-foreground" />
                            {t('consent.earlyCapture.title')}
                        </CardTitle>
                        <CardDescription>{t('consent.earlyCapture.description')}</CardDescription>
                    </div>
                    <Badge variant={state.enabled ? 'default' : 'secondary'}>{state.enabled ? t('consent.earlyCapture.on') : t('consent.earlyCapture.off')}</Badge>
                </CardHeader>
                <CardContent className="igr:space-y-3 igr:text-[13px]">
                    <div>
                        <p className="igr:mb-1.5 igr:text-[11px] igr:font-medium igr:uppercase igr:tracking-wide igr:text-muted-foreground">
                            {t('consent.earlyCapture.noticeTitle')}
                        </p>
                        <p className="igr:rounded-md igr:border igr:bg-background igr:p-3 igr:text-xs igr:text-muted-foreground">{t('consent.earlyCapture.notice')}</p>
                    </div>
                    <ul className="igr:list-disc igr:space-y-1 igr:pl-5 igr:text-xs igr:text-muted-foreground">
                        <li>{t('consent.earlyCapture.ruleCart')}</li>
                        <li>{t('consent.earlyCapture.ruleConsent')}</li>
                        <li>{t('consent.earlyCapture.rulePlugin', { version: minPlugin })}</li>
                    </ul>
                    {state.enabled && state.accepted_at && (
                        <p className="igr:text-xs igr:text-muted-foreground">
                            {t('consent.earlyCapture.acceptedOn', {
                                date: formatDateTime(state.accepted_at, workspace.locale, workspace.timezone),
                                name: state.accepted_by ?? '—',
                            })}
                        </p>
                    )}
                    {canManage && !state.enabled && (
                        <label htmlFor={acceptId} className="igr:flex igr:items-start igr:gap-2 igr:rounded-md igr:bg-muted/60 igr:p-2.5 igr:text-xs">
                            <input
                                id={acceptId}
                                type="checkbox"
                                className="igr:mt-0.5 igr:size-4 igr:accent-[var(--primary)]"
                                checked={accepted}
                                onChange={(e) => setAccepted(e.target.checked)}
                            />
                            <span>{t('consent.earlyCapture.accept')}</span>
                        </label>
                    )}
                    <FieldError message={save.isError ? errorMessage(save.error, t) : undefined} />
                </CardContent>
                {canManage && (
                    <CardFooter className="igr:justify-end">
                        {state.enabled ? (
                            <Button
                                variant="outline"
                                loading={save.isPending}
                                onClick={() => save.mutate({ enabled: false }, { onSuccess: () => toast.success(t('consent.earlyCapture.disabled')) })}
                            >
                                {t('consent.earlyCapture.disable')}
                            </Button>
                        ) : (
                            <Button
                                disabled={!accepted}
                                loading={save.isPending}
                                onClick={() =>
                                    save.mutate(
                                        { enabled: true, accept_responsibility: true },
                                        {
                                            onSuccess: () => {
                                                setAccepted(false);
                                                toast.success(t('consent.earlyCapture.enabled'));
                                            },
                                        },
                                    )
                                }
                            >
                                {t('consent.earlyCapture.enable')}
                            </Button>
                        )}
                    </CardFooter>
                )}
            </Card>
        </section>
    );
}

function PolicyCard({ current, canManage }: { current: string | null; canManage: boolean }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const qc = useQueryClient();
    const update = useUpdateWorkspace(workspace.id);
    const [url, setUrl] = useState(current ?? '');
    const id = useId();
    const trimmed = url.trim();
    const valid = trimmed === '' || /^https:\/\/\S+\.\S+/.test(trimmed);

    return (
        <Card className="igr:flex igr:flex-col">
            <CardHeader>
                <CardTitle>{t('consent.policy.title')}</CardTitle>
                <CardDescription>{t('consent.policy.description')}</CardDescription>
            </CardHeader>
            <CardContent className="igr:flex-1 igr:space-y-1.5">
                <Label htmlFor={id}>{t('consent.policy.url')}</Label>
                <Input
                    id={id}
                    type="url"
                    inputMode="url"
                    placeholder="https://"
                    readOnly={!canManage}
                    value={url}
                    aria-invalid={!valid ? true : undefined}
                    onChange={(e) => setUrl(e.target.value)}
                />
                {!valid && <FieldError message={t('consent.policy.invalid')} />}
                <FieldError message={update.isError ? errorMessage(update.error, t) : undefined} />
                {!current && <p className="igr:text-xs igr:text-warning">{t('consent.policy.missing')}</p>}
                <p className="igr:text-xs igr:text-muted-foreground">{t('consent.policy.help')}</p>
            </CardContent>
            {canManage && (
                <CardFooter className="igr:justify-end">
                    <Button
                        disabled={!valid || trimmed === (current ?? '')}
                        loading={update.isPending}
                        onClick={() =>
                            update.mutate(
                                { privacy_policy_url: trimmed === '' ? null : trimmed },
                                {
                                    onSuccess: () => {
                                        void qc.invalidateQueries({ queryKey: ['ws', workspace.id, 'consent-texts'] });
                                        toast.success(t('consent.policy.saved'));
                                    },
                                },
                            )
                        }
                    >
                        {t('common.save')}
                    </Button>
                </CardFooter>
            )}
        </Card>
    );
}
