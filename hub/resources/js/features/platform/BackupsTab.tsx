import { CheckCircle2, CircleDashed, KeyRound, Loader2, PlayCircle, PlugZap, RefreshCw, TriangleAlert, Wand2, XCircle } from 'lucide-react';
import { useEffect, useRef, useState, type ReactNode, type SyntheticEvent } from 'react';
import { useTranslation } from 'react-i18next';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ErrorState, FieldError } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TBody, TD, TH, THead, TR } from '@/components/ui/table';
import { CopyButton, fieldError } from '@/features/channels/shared';
import { useWorkspace } from '@/hooks/useWorkspace';
import { isApiError } from '@/lib/api';
import { cn } from '@/lib/cn';
import { formatDateTime } from '@/lib/format';
import { toast } from '@/stores/toast';
import {
    type BackupJob,
    type BackupJobKey,
    type BackupsPayload,
    useBackups,
    useInstallBackupKey,
    useRefreshBackups,
    useRunBackupNow,
    useSaveBackupSettings,
    useTestBackupConnection,
} from './backups-api';
import { BACKUP_JOBS, type BackupForm, errorKey, finishedRequest, formatBytes, formFromPayload, generatePassphrase, jobTone, settingsInput, userFromHost } from './backups-model';

/**
 * Plataforma → Copias de seguridad (docs/despliegue.md §6): destino en el Storage Box, prueba de conexión, copia
 * ahora y el resultado de cada copia, sin consola. El panel deja un pedido; lo atiende el servidor (root) y publica el
 * estado. La contraseña del Storage Box y la frase de cifrado solo viajan en el pedido: nunca vuelven al navegador.
 */
export function BackupsTab() {
    const { t } = useTranslation();
    const backups = useBackups();
    const refresh = useRefreshBackups();
    const [sentId, setSentId] = useState<string | null>(null);
    const announced = useRef<string | null>(null);
    const data = backups.data;

    // El resultado del pedido que se mandó desde aquí, una sola vez.
    useEffect(() => {
        const done = finishedRequest(data, sentId);
        if (!done || announced.current === done.id) return;
        announced.current = done.id;
        if (done.state === 'succeeded') {
            toast.success(t(`platform.backups.done.${done.action ?? 'refresh'}`, { defaultValue: done.message ?? '' }));
        } else {
            toast.error(t('platform.backups.failed'), describeError(t, done.code, done.message));
        }
    }, [data, sentId, t]);

    if (backups.isError) return <ErrorState error={backups.error} onRetry={() => void backups.refetch()} />;
    if (backups.isPending || !data) return <Skeleton className="igr:h-64" />;

    const sent = (p: BackupsPayload) => setSentId(p.pending?.id ?? null);
    const onError = (e: unknown) => toast.error(t('platform.backups.failed'), isApiError(e) ? e.message : undefined);

    return (
        <div className="igr:space-y-4">
            {!data.answered && data.pending === null ? (
                <Notice tone="warning" title={t('platform.backups.notAnswered.title')}>
                    <p>{t('platform.backups.notAnswered.body')}</p>
                    <Button className="igr:mt-2" size="sm" variant="outline" loading={refresh.isPending} onClick={() => refresh.mutate(undefined, { onSuccess: sent, onError })}>
                        <RefreshCw aria-hidden />
                        {t('platform.backups.notAnswered.ask')}
                    </Button>
                </Notice>
            ) : null}
            {data.pending?.stale ? <Notice tone="warning" title={t('platform.backups.stale')} /> : null}
            {data.configured && data.timers_enabled === false ? <Notice tone="warning" title={t('platform.backups.timersOff')} /> : null}
            {data.configured && !data.managed ? <Notice tone="info" title={t('platform.backups.manual', { remote: data.manual_remote ?? '—' })} /> : null}
            <StatusCard data={data} onSent={sent} onError={onError} />
            <div className="igr:grid igr:gap-4 igr:lg:grid-cols-2">
                {/* Cuando root publica un destino nuevo, el formulario vuelve a empezar con lo guardado. */}
                <DestinationCard key={JSON.stringify(data.destination)} data={data} onSent={sent} />
                <KeyCard data={data} onSent={sent} />
            </div>
        </div>
    );
}

type T = ReturnType<typeof useTranslation>['t'];

function describeError(t: T, code: string | null, message: string | null): string {
    const key = errorKey(code);
    return key ? t(key) : (message ?? t('platform.backups.errors.unknown'));
}

function Notice({ tone, title, children }: { tone: 'warning' | 'info'; title: string; children?: ReactNode }) {
    return (
        <div
            role="status"
            className={cn(
                'igr:flex igr:items-start igr:gap-2 igr:rounded-lg igr:border igr:p-3 igr:text-[13px]',
                tone === 'warning' ? 'igr:border-warning/40 igr:bg-warning/5' : 'igr:border-primary/30 igr:bg-primary/5',
            )}
        >
            <TriangleAlert className={cn('igr:mt-0.5 igr:size-4 igr:shrink-0', tone === 'warning' ? 'igr:text-warning' : 'igr:text-primary')} aria-hidden />
            <div className="igr:min-w-0">
                <p className="igr:font-medium">{title}</p>
                {children}
            </div>
        </div>
    );
}

function StatusCard({ data, onSent, onError }: { data: BackupsPayload; onSent: (p: BackupsPayload) => void; onError: (e: unknown) => void }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const test = useTestBackupConnection();
    const run = useRunBackupNow();
    const when = (iso: string | null | undefined) => formatDateTime(iso, workspace.locale, workspace.timezone);
    const working = data.pending !== null && !data.pending.stale ? data.pending.action : data.last_request?.state === 'running' ? data.last_request.action : null;
    const jobRunning = BACKUP_JOBS.some((k) => data.jobs[k]?.state === 'queued' || data.jobs[k]?.state === 'running');
    const lastTest = data.last_test;

    return (
        <Card>
            <CardHeader className="igr:flex igr:flex-row igr:flex-wrap igr:items-start igr:justify-between igr:gap-3">
                <div className="igr:space-y-1.5">
                    <CardTitle>{t('platform.backups.status.title')}</CardTitle>
                    <CardDescription>{t('platform.backups.status.description')}</CardDescription>
                </div>
                <div className="igr:flex igr:flex-wrap igr:gap-2">
                    <Button size="sm" disabled={!data.configured || data.busy || jobRunning} loading={run.isPending} onClick={() => run.mutate(undefined, { onSuccess: onSent, onError })}>
                        <PlayCircle aria-hidden />
                        {t('platform.backups.status.runNow')}
                    </Button>
                    <Button size="sm" variant="outline" disabled={!data.configured || data.busy} loading={test.isPending} onClick={() => test.mutate(undefined, { onSuccess: onSent, onError })}>
                        <PlugZap aria-hidden />
                        {t('platform.backups.status.testNow')}
                    </Button>
                </div>
            </CardHeader>
            <CardContent className="igr:space-y-3">
                {!data.configured ? <p className="igr:text-[13px] igr:text-muted-foreground">{t('platform.backups.status.notConfigured')}</p> : null}
                {working ? (
                    <p className="igr:flex igr:items-center igr:gap-2 igr:text-[13px]" aria-live="polite">
                        <Loader2 className="igr:size-4 igr:animate-spin" aria-hidden />
                        {t(`platform.backups.working.${working}`, { defaultValue: t('platform.backups.working.refresh') })}
                    </p>
                ) : null}
                <div className="igr:overflow-x-auto">
                    <Table>
                        <THead>
                            <TR>
                                <TH>{t('platform.backups.status.task')}</TH>
                                <TH>{t('platform.backups.status.result')}</TH>
                                <TH>{t('platform.backups.status.last')}</TH>
                                <TH className="igr:text-right">{t('platform.backups.status.size')}</TH>
                                <TH>{t('platform.backups.status.next')}</TH>
                            </TR>
                        </THead>
                        <TBody>
                            {BACKUP_JOBS.map((k) => (
                                <JobRow key={k} job={k} value={data.jobs[k]} next={data.schedule[k] ?? null} when={when} />
                            ))}
                        </TBody>
                    </Table>
                </div>
                <div className="igr:text-[13px]" aria-live="polite">
                    {lastTest === null ? (
                        <p className="igr:text-muted-foreground">{t('platform.backups.status.noTest')}</p>
                    ) : lastTest.ok ? (
                        <p className="igr:flex igr:items-center igr:gap-2 igr:text-success">
                            <CheckCircle2 className="igr:size-4" aria-hidden />
                            {t('platform.backups.status.testOk', { date: when(lastTest.at) })}
                            {lastTest.free_bytes !== null ? <span className="igr:text-muted-foreground">· {t('platform.backups.status.free', { size: formatBytes(lastTest.free_bytes, workspace.locale) })}</span> : null}
                        </p>
                    ) : (
                        <div className="igr:flex igr:items-start igr:gap-2 igr:text-destructive">
                            <XCircle className="igr:mt-0.5 igr:size-4 igr:shrink-0" aria-hidden />
                            <div>
                                <p className="igr:font-medium">{t('platform.backups.status.testFailed', { date: when(lastTest.at) })}</p>
                                <p className="igr:text-foreground">{describeError(t, lastTest.code, lastTest.message)}</p>
                            </div>
                        </div>
                    )}
                    {data.last_request?.state === 'failed' && data.last_request.action !== 'test' ? (
                        <p className="igr:mt-1 igr:text-destructive">
                            {t(`platform.backups.lastFailed.${data.last_request.action ?? 'refresh'}`, { defaultValue: t('platform.backups.failed') })}{' '}
                            <span className="igr:text-foreground">{describeError(t, data.last_request.code, data.last_request.message)}</span>
                        </p>
                    ) : null}
                </div>
            </CardContent>
        </Card>
    );
}

function JobRow({ job, value, next, when }: { job: BackupJobKey; value: BackupJob | null; next: string | null; when: (iso: string | null | undefined) => string }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const tone = jobTone(value);
    const Icon = tone === 'ok' ? CheckCircle2 : tone === 'critical' ? XCircle : tone === 'active' ? Loader2 : CircleDashed;
    const variant = tone === 'ok' ? 'success' : tone === 'critical' ? 'destructive' : tone === 'active' ? 'default' : 'outline';
    const failed = value?.state === 'failed';
    const shownSize = value?.state === 'succeeded' ? value.size_bytes : (value?.last_success?.size_bytes ?? null);
    return (
        <TR>
            <TD className="igr:align-top">
                <span className="igr:font-medium">{t(`platform.backups.jobs.${job}`)}</span>
                {failed ? (
                    <p className="igr:max-w-md igr:text-xs igr:text-destructive">
                        {describeError(t, value.code, value.message)}
                        {value.last_success?.finished_at ? <span className="igr:block igr:text-muted-foreground">{t('platform.backups.status.lastGood', { date: when(value.last_success.finished_at) })}</span> : null}
                    </p>
                ) : null}
            </TD>
            <TD className="igr:align-top">
                <Badge variant={variant}>
                    <Icon className={cn('igr:size-3', tone === 'active' && 'igr:animate-spin')} aria-hidden />
                    {t(`platform.backups.states.${value?.state ?? 'never'}`)}
                </Badge>
            </TD>
            <TD className="igr:whitespace-nowrap igr:align-top">{value?.finished_at ?? value?.started_at ? when(value.finished_at ?? value.started_at) : t('platform.backups.status.never')}</TD>
            <TD className="igr:text-right igr:tabular-nums igr:align-top">{job === 'verify' ? '—' : formatBytes(shownSize, workspace.locale)}</TD>
            <TD className="igr:whitespace-nowrap igr:align-top">{next ? when(next) : '—'}</TD>
        </TR>
    );
}

function DestinationCard({ data, onSent }: { data: BackupsPayload; onSent: (p: BackupsPayload) => void }) {
    const { t } = useTranslation();
    const save = useSaveBackupSettings();
    const [form, setForm] = useState<BackupForm>(() => formFromPayload(data));
    const [confirming, setConfirming] = useState(false);
    const set = (patch: Partial<BackupForm>) => setForm((f) => ({ ...f, ...patch }));
    const err = (field: string) => fieldError(save.error, field);

    const submit = (e: SyntheticEvent) => {
        e.preventDefault();
        setConfirming(true);
    };

    return (
        <Card>
            <CardHeader>
                <CardTitle>{t('platform.backups.form.title')}</CardTitle>
                <CardDescription>{t('platform.backups.form.description')}</CardDescription>
            </CardHeader>
            <CardContent>
                <form className="igr:space-y-4" onSubmit={submit} id="igr-backup-form">
                    <div className="igr:grid igr:gap-3 igr:sm:grid-cols-[1fr_6rem]">
                        <Field id="bk-host" label={t('platform.backups.form.host')} hint={t('platform.backups.form.hostHint')} error={err('host')}>
                            <Input
                                id="bk-host"
                                required
                                autoComplete="off"
                                spellCheck={false}
                                value={form.host}
                                placeholder={t('platform.backups.form.hostPlaceholder')}
                                onChange={(e) => set({ host: e.target.value })}
                                onBlur={() => form.user === '' && userFromHost(form.host) && set({ user: userFromHost(form.host) ?? '' })}
                            />
                        </Field>
                        <Field id="bk-port" label={t('platform.backups.form.port')} hint={t('platform.backups.form.portHint')} error={err('port')}>
                            <Input id="bk-port" required inputMode="numeric" value={form.port} onChange={(e) => set({ port: e.target.value.replace(/\D/g, '') })} />
                        </Field>
                    </div>
                    <div className="igr:grid igr:gap-3 igr:sm:grid-cols-2">
                        <Field id="bk-user" label={t('platform.backups.form.user')} error={err('user')}>
                            <Input id="bk-user" required autoComplete="off" spellCheck={false} value={form.user} placeholder={t('platform.backups.form.userPlaceholder')} onChange={(e) => set({ user: e.target.value })} />
                        </Field>
                        <Field id="bk-path" label={t('platform.backups.form.path')} hint={t('platform.backups.form.pathHint')} error={err('path')}>
                            <Input id="bk-path" required spellCheck={false} value={form.path} onChange={(e) => set({ path: e.target.value })} />
                        </Field>
                    </div>

                    <fieldset className="igr:space-y-2">
                        <legend className="igr:mb-1 igr:text-[13px] igr:font-medium">{t('platform.backups.form.auth')}</legend>
                        <div className="igr:grid igr:gap-2 igr:sm:grid-cols-2">
                            {(['key', 'password'] as const).map((a) => (
                                <label key={a} className={cn('igr:flex igr:cursor-pointer igr:gap-2 igr:rounded-lg igr:border igr:p-3', form.auth === a && 'igr:border-primary igr:bg-primary/5')}>
                                    <input type="radio" name="bk-auth" value={a} checked={form.auth === a} onChange={() => set({ auth: a })} className="igr:mt-0.5" />
                                    <span>
                                        <span className="igr:block igr:text-[13px] igr:font-medium">{t(a === 'key' ? 'platform.backups.form.authKey' : 'platform.backups.form.authPassword')}</span>
                                        <span className="igr:block igr:text-xs igr:text-muted-foreground">{t(a === 'key' ? 'platform.backups.form.authKeyHelp' : 'platform.backups.form.authPasswordHelp')}</span>
                                    </span>
                                </label>
                            ))}
                        </div>
                        <FieldError message={err('auth')} />
                    </fieldset>
                    {form.auth === 'password' ? (
                        <Field id="bk-password" label={t('platform.backups.form.password')} hint={data.secrets.password ? t('platform.backups.form.passwordStored') : undefined} error={err('password')}>
                            <Input id="bk-password" type="password" autoComplete="new-password" required={!data.secrets.password} value={form.password} onChange={(e) => set({ password: e.target.value })} />
                        </Field>
                    ) : null}

                    <Field id="bk-encryption" label={t('platform.backups.form.encryption')} hint={data.secrets.encryption ? t('platform.backups.form.encryptionStored') : t('platform.backups.form.encryptionHelp')} error={err('encryption_password')}>
                        <div className="igr:flex igr:gap-2">
                            <Input
                                id="bk-encryption"
                                type="text"
                                autoComplete="off"
                                spellCheck={false}
                                className="igr:font-mono"
                                required={!data.secrets.encryption}
                                minLength={12}
                                value={form.encryption_password}
                                onChange={(e) => set({ encryption_password: e.target.value })}
                            />
                            {form.encryption_password !== '' ? <CopyButton value={form.encryption_password} /> : null}
                            <Button type="button" variant="outline" size="sm" className="igr:shrink-0" onClick={() => set({ encryption_password: generatePassphrase() })}>
                                <Wand2 aria-hidden />
                                {t('platform.backups.form.generate')}
                            </Button>
                        </div>
                    </Field>
                    {form.encryption_password !== '' ? <Notice tone="warning" title={t('platform.backups.form.encryptionSave')} /> : null}

                    <Field id="bk-keep" label={t('platform.backups.form.keepDays')} hint={t('platform.backups.form.keepDaysHint')} error={err('keep_days')}>
                        <Input id="bk-keep" required inputMode="numeric" className="igr:w-24" value={form.keep_days} onChange={(e) => set({ keep_days: e.target.value.replace(/\D/g, '') })} />
                    </Field>

                    <fieldset className="igr:space-y-2">
                        <legend className="igr:text-[13px] igr:font-medium">{t('platform.backups.form.heartbeats')}</legend>
                        <p className="igr:text-xs igr:text-muted-foreground">{t('platform.backups.form.heartbeatsHelp')}</p>
                        {BACKUP_JOBS.map((k) => {
                            const removing = form.clear_heartbeats.includes(k);
                            return (
                                <div key={k} className="igr:space-y-1">
                                    <Label htmlFor={`bk-hb-${k}`}>
                                        {t(`platform.backups.jobs.${k}`)}
                                        {data.heartbeats[k] && !removing ? <Badge variant="success" className="igr:ml-2">{t('platform.backups.form.heartbeatSet')}</Badge> : null}
                                    </Label>
                                    <div className="igr:flex igr:gap-2">
                                        <Input
                                            id={`bk-hb-${k}`}
                                            type="url"
                                            autoComplete="off"
                                            spellCheck={false}
                                            disabled={removing}
                                            placeholder={data.heartbeats[k] ? t('platform.backups.form.heartbeatKeep') : t('platform.backups.form.heartbeatPlaceholder')}
                                            value={form.heartbeats[k]}
                                            onChange={(e) => set({ heartbeats: { ...form.heartbeats, [k]: e.target.value } })}
                                        />
                                        {data.heartbeats[k] ? (
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="sm"
                                                className="igr:shrink-0"
                                                onClick={() => set({ clear_heartbeats: removing ? form.clear_heartbeats.filter((x) => x !== k) : [...form.clear_heartbeats, k] })}
                                            >
                                                {removing ? t('platform.backups.form.heartbeatUndo') : t('platform.backups.form.heartbeatRemove')}
                                            </Button>
                                        ) : null}
                                    </div>
                                    <FieldError message={err(`heartbeats.${k}`)} />
                                </div>
                            );
                        })}
                    </fieldset>

                    <Button type="submit" disabled={data.busy} loading={save.isPending}>
                        {t('platform.backups.form.save')}
                    </Button>
                </form>
            </CardContent>
            {confirming ? (
                <ConfirmPassword
                    title={t('platform.backups.confirm.title')}
                    body={t('platform.backups.confirm.body')}
                    submit={t('platform.backups.confirm.submit')}
                    pending={save.isPending}
                    onClose={() => setConfirming(false)}
                    onConfirm={(currentPassword, fail) =>
                        save.mutate(settingsInput(form, currentPassword), {
                            onSuccess: (p) => {
                                onSent(p);
                                setConfirming(false);
                                set({ password: '', encryption_password: '', heartbeats: { mariadb: '', clickhouse: '', verify: '' }, clear_heartbeats: [] });
                            },
                            onError: (e) => {
                                if (isApiError(e) && e.code === 'invalid_password') {
                                    fail(e.message);
                                } else {
                                    setConfirming(false);
                                    toast.error(t('platform.backups.failed'), isApiError(e) ? e.message : undefined);
                                }
                            },
                        })
                    }
                />
            ) : null}
        </Card>
    );
}

function KeyCard({ data, onSent }: { data: BackupsPayload; onSent: (p: BackupsPayload) => void }) {
    const { t } = useTranslation();
    const [installing, setInstalling] = useState(false);
    const key = data.ssh_key;
    const usesKey = data.destination?.auth === 'key';

    return (
        <Card>
            <CardHeader>
                <CardTitle className="igr:flex igr:items-center igr:gap-2">
                    <KeyRound className="igr:size-4" aria-hidden />
                    {t('platform.backups.key.title')}
                </CardTitle>
                <CardDescription>{t('platform.backups.key.description')}</CardDescription>
            </CardHeader>
            <CardContent className="igr:space-y-3 igr:text-[13px]">
                {key ? (
                    <>
                        <div className="igr:flex igr:items-start igr:gap-2">
                            <code className="igr:block igr:min-w-0 igr:flex-1 igr:break-all igr:rounded-md igr:bg-muted igr:p-2 igr:font-mono igr:text-xs">{key.public}</code>
                            <CopyButton value={key.public} />
                        </div>
                        {key.fingerprint ? <p className="igr:text-xs igr:text-muted-foreground">{t('platform.backups.key.fingerprint', { value: key.fingerprint })}</p> : null}
                        <div>
                            <p className="igr:font-medium">{t('platform.backups.key.stepsTitle')}</p>
                            <ol className="igr:mt-1 igr:list-decimal igr:space-y-1 igr:pl-5 igr:text-muted-foreground">
                                <li>{t('platform.backups.key.step1')}</li>
                                <li>{t('platform.backups.key.step2')}</li>
                                <li>{t('platform.backups.key.step3')}</li>
                            </ol>
                        </div>
                        {usesKey ? (
                            <Button size="sm" variant="outline" disabled={data.busy} onClick={() => setInstalling(true)}>
                                <KeyRound aria-hidden />
                                {t('platform.backups.key.install')}
                            </Button>
                        ) : (
                            <p className="igr:text-muted-foreground">{t('platform.backups.key.passwordMode')}</p>
                        )}
                    </>
                ) : (
                    <p className="igr:text-muted-foreground">{t('platform.backups.key.none')}</p>
                )}
                {data.host_key?.fingerprint ? <p className="igr:text-xs igr:text-muted-foreground">{t('platform.backups.key.hostKey', { value: data.host_key.fingerprint })}</p> : null}
            </CardContent>
            {installing ? <InstallKeyDialog onClose={() => setInstalling(false)} onSent={onSent} /> : null}
        </Card>
    );
}

function InstallKeyDialog({ onClose, onSent }: { onClose: () => void; onSent: (p: BackupsPayload) => void }) {
    const { t } = useTranslation();
    const install = useInstallBackupKey();
    const [password, setPassword] = useState('');
    const [current, setCurrent] = useState('');
    const [error, setError] = useState<string | null>(null);
    const submit = (e?: SyntheticEvent) => {
        e?.preventDefault();
        setError(null);
        install.mutate(
            { password, current_password: current },
            {
                onSuccess: (p) => {
                    onSent(p);
                    onClose();
                },
                onError: (err) => setError(isApiError(err) ? (err.fieldErrors.password ?? err.message) : t('errors.title')),
            },
        );
    };
    return (
        <Dialog open onOpenChange={(open) => (!open ? onClose() : undefined)}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{t('platform.backups.install.title')}</DialogTitle>
                    <DialogDescription>{t('platform.backups.install.body')}</DialogDescription>
                </DialogHeader>
                <form id="igr-install-key" className="igr:space-y-3" onSubmit={submit}>
                    <Field id="bk-box-password" label={t('platform.backups.install.password')}>
                        <Input id="bk-box-password" type="password" autoComplete="off" value={password} onChange={(e) => setPassword(e.target.value)} autoFocus />
                    </Field>
                    <Field id="bk-install-current" label={t('platform.backups.confirm.password')}>
                        <Input id="bk-install-current" type="password" autoComplete="current-password" value={current} onChange={(e) => setCurrent(e.target.value)} />
                    </Field>
                    {error ? <p className="igr:text-sm igr:text-destructive">{error}</p> : null}
                </form>
                <DialogFooter>
                    <Button variant="outline" onClick={onClose}>
                        {t('common.cancel')}
                    </Button>
                    <Button type="submit" form="igr-install-key" disabled={password === '' || current === ''} loading={install.isPending}>
                        {t('platform.backups.install.submit')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function ConfirmPassword({
    title,
    body,
    submit,
    pending,
    onClose,
    onConfirm,
}: {
    title: string;
    body: string;
    submit: string;
    pending: boolean;
    onClose: () => void;
    onConfirm: (password: string, fail: (message: string) => void) => void;
}) {
    const { t } = useTranslation();
    const [password, setPassword] = useState('');
    const [error, setError] = useState<string | null>(null);
    const send = (e?: SyntheticEvent) => {
        e?.preventDefault();
        setError(null);
        onConfirm(password, setError);
    };
    return (
        <Dialog open onOpenChange={(open) => (!open ? onClose() : undefined)}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{title}</DialogTitle>
                    <DialogDescription>{body}</DialogDescription>
                </DialogHeader>
                <form id="igr-backup-confirm" className="igr:space-y-2" onSubmit={send}>
                    <Label htmlFor="bk-current">{t('platform.backups.confirm.password')}</Label>
                    <Input
                        id="bk-current"
                        type="password"
                        autoComplete="current-password"
                        value={password}
                        onChange={(e) => setPassword(e.target.value)}
                        aria-invalid={error !== null}
                        aria-describedby={error ? 'bk-current-error' : undefined}
                        autoFocus
                    />
                    {error ? (
                        <p id="bk-current-error" className="igr:text-sm igr:text-destructive">
                            {error}
                        </p>
                    ) : null}
                </form>
                <DialogFooter>
                    <Button variant="outline" onClick={onClose}>
                        {t('common.cancel')}
                    </Button>
                    <Button type="submit" form="igr-backup-confirm" disabled={password === ''} loading={pending}>
                        {submit}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function Field({ id, label, hint, error, children }: { id: string; label: string; hint?: string | undefined; error?: string | undefined; children: ReactNode }) {
    return (
        <div className="igr:space-y-1">
            <Label htmlFor={id}>{label}</Label>
            {children}
            {hint ? <p className="igr:text-xs igr:text-muted-foreground">{hint}</p> : null}
            <FieldError message={error} />
        </div>
    );
}
