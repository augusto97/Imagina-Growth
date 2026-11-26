import { Copy, Eye, EyeOff, KeyRound, RefreshCw, Send, Undo2, Wand2 } from 'lucide-react';
import { useState, type SyntheticEvent } from 'react';
import { useTranslation } from 'react-i18next';
import { useMe } from '@/api/queries';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Callout } from '@/components/ui/callout';
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import { Input, Select } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { FieldError } from '@/components/ui/misc';
import { useWorkspace } from '@/hooks/useWorkspace';
import { isApiError } from '@/lib/api';
import { formatDateTime } from '@/lib/format';
import { toast } from '@/stores/toast';
import { isAiModelField } from './ai-settings-model';
import { AiModelFields, AiTestPanel } from './AiSettings';
import { type MailTestResult, useMailTest } from './api';
import { type SettingField, type SettingsSection, useGenerateVapid, useSaveSettings, useSesSetup } from './settings-api';
import { type Draft, buildPayload, displayValue, effectiveBool, fieldErrors, initialText, pruneDraft, randomToken } from './settings-model';

/** Una sección de Plataforma → Ajustes: sus campos, lo que hay que registrar en otras partes y sus botones propios. */
export function SettingsSectionCard({ section }: { section: SettingsSection }) {
    const { t } = useTranslation();
    const save = useSaveSettings();
    const [draft, setDraft] = useState<Draft>({});
    const [errors, setErrors] = useState<Record<string, string>>({});
    const changes = pruneDraft(section.fields, draft);
    const dirty = Object.keys(changes).length > 0;
    const set = (key: string, value: string | boolean | null) => setDraft((d) => ({ ...d, [key]: value }));

    const submit = (e: SyntheticEvent) => {
        e.preventDefault();
        if (!dirty) {
            toast.info(t('platformSettings.nothingChanged'));
            return;
        }
        save.mutate(
            { section: section.key, values: buildPayload(section.fields, changes) },
            {
                onSuccess: () => {
                    setDraft({});
                    setErrors({});
                    toast.success(t('platformSettings.saved'));
                },
                onError: (err) => {
                    setErrors(isApiError(err) ? fieldErrors(err.fieldErrors) : {});
                    toast.error(t('errors.title'), isApiError(err) ? err.message : undefined);
                },
            },
        );
    };

    const configured = section.info.configured;

    return (
        <form onSubmit={submit} noValidate>
            <Card>
                <CardHeader>
                    <CardTitle className="igr:flex igr:flex-wrap igr:items-center igr:gap-2">
                        {t(`platformSettings.sections.${section.key}.title`)}
                        {configured !== undefined ? (
                            <Badge variant={configured ? 'success' : 'warning'}>{t(configured ? 'platformSettings.status.configured' : 'platformSettings.status.off')}</Badge>
                        ) : null}
                    </CardTitle>
                    <CardDescription>{t(`platformSettings.sections.${section.key}.description`)}</CardDescription>
                </CardHeader>
                <CardContent className="igr:space-y-5">
                    <SectionExtras section={section} />
                    {section.fields
                        .filter((field) => section.key !== 'ai' || !isAiModelField(field.key))
                        .map((field) => (
                            <FieldRow key={field.key} section={section} field={field} draft={draft} error={errors[field.key]} onChange={set} />
                        ))}
                    {section.key === 'ai' ? (
                        <>
                            <AiModelFields section={section} draft={draft} errors={errors} onChange={set} />
                            <AiTestPanel section={section} dirty={dirty} />
                        </>
                    ) : null}
                </CardContent>
                <CardFooter className="igr:flex-wrap igr:justify-between">
                    <p className="igr:text-xs igr:text-muted-foreground">{dirty ? t('platformSettings.unsaved') : ''}</p>
                    <div className="igr:flex igr:gap-2">
                        {dirty ? (
                            <Button
                                type="button"
                                variant="ghost"
                                onClick={() => {
                                    setDraft({});
                                    setErrors({});
                                }}
                            >
                                {t('platformSettings.discard')}
                            </Button>
                        ) : null}
                        <Button type="submit" loading={save.isPending} disabled={!dirty || save.isPending}>
                            {t('platformSettings.save')}
                        </Button>
                    </div>
                </CardFooter>
            </Card>
        </form>
    );
}

function FieldRow({
    section,
    field,
    draft,
    error,
    onChange,
}: {
    section: SettingsSection;
    field: SettingField;
    draft: Draft;
    error: string | undefined;
    onChange: (key: string, value: string | boolean | null) => void;
}) {
    const { t, i18n } = useTranslation();
    const { workspace } = useWorkspace();
    const id = `ps-${section.key}-${field.key}`;
    const base = `platformSettings.fields.${section.key}.${field.key}`;
    const where = i18n.exists(`${base}.where`) ? t(`${base}.where`) : null;
    const placeholder = i18n.exists(`${base}.placeholder`) ? t(`${base}.placeholder`) : undefined;
    const pending = field.key in draft;
    const cleared = pending && draft[field.key] === null;
    const yes = t('platformSettings.yes');
    const no = t('platformSettings.no');
    const exists = (key: string) => i18n.exists(key);
    const server = displayValue(
        field.type === 'enum' && typeof field.fallback === 'string' ? optionLabel(t, exists, section, field, field.fallback) : field.fallback,
        yes,
        no,
        workspace.locale,
    );

    let control;
    if (field.type === 'secret') {
        control = <SecretInput id={id} field={field} draft={draft} onChange={onChange} />;
    } else if (field.type === 'bool') {
        control = (
            <label className="igr:inline-flex igr:items-center igr:gap-2 igr:text-[13px]">
                <input id={id} type="checkbox" checked={effectiveBool(field, draft)} onChange={(e) => onChange(field.key, e.target.checked)} />
                {effectiveBool(field, draft) ? yes : no}
            </label>
        );
    } else if (field.type === 'enum') {
        const value = cleared ? '' : typeof draft[field.key] === 'string' ? (draft[field.key] as string) : initialText(field);
        control = (
            <Select id={id} value={value} aria-invalid={error ? true : undefined} onChange={(e) => onChange(field.key, e.target.value === '' ? null : e.target.value)}>
                <option value="">{server !== '' ? t('platformSettings.useServer', { value: server }) : t('platformSettings.useServerEmpty')}</option>
                {(field.options ?? []).map((option) => (
                    <option key={option} value={option}>
                        {optionLabel(t, exists, section, field, option)}
                    </option>
                ))}
            </Select>
        );
    } else {
        const value = cleared ? '' : typeof draft[field.key] === 'string' ? (draft[field.key] as string) : initialText(field);
        const numeric = field.type === 'int' || field.type === 'number';
        control = (
            <Input
                id={id}
                value={value}
                inputMode={numeric ? 'decimal' : field.type === 'email' ? 'email' : field.type === 'url' ? 'url' : undefined}
                autoComplete="off"
                spellCheck={false}
                aria-invalid={error ? true : undefined}
                placeholder={server !== '' ? t('platformSettings.serverValue', { value: server }) : placeholder}
                onChange={(e) => onChange(field.key, e.target.value)}
            />
        );
    }

    return (
        <div className="igr:space-y-1.5">
            <div className="igr:flex igr:flex-wrap igr:items-center igr:gap-2">
                <Label htmlFor={id}>{t(`${base}.label`)}</Label>
                <SourceBadge field={field} />
                {field.type !== 'secret' && field.source === 'panel' && !cleared ? (
                    <Button type="button" variant="link" size="sm" className="igr:h-auto igr:text-xs" onClick={() => onChange(field.key, null)}>
                        <Undo2 />
                        {t('platformSettings.reset')}
                    </Button>
                ) : null}
            </div>
            <div className="igr:max-w-xl">{control}</div>
            <p className="igr:text-xs igr:text-muted-foreground">{t(`${base}.help`)}</p>
            {where ? (
                <p className="igr:text-xs igr:text-muted-foreground">
                    <span className="igr:font-medium">{t('platformSettings.where')}</span> {where}
                </p>
            ) : null}
            {cleared && field.type !== 'secret' ? <p className="igr:text-xs igr:text-warning">{t('platformSettings.resetHint')}</p> : null}
            <FieldError message={error} />
        </div>
    );
}

function optionLabel(t: (k: string) => string, exists: (k: string) => boolean, section: SettingsSection, field: SettingField, option: string): string {
    if (section.key === 'security' && field.key === 'default_plan') {
        return section.info.plans?.find((p) => p.id === option)?.name ?? option;
    }
    const key = `platformSettings.options.${section.key}.${field.key}.${option}`;
    return exists(key) ? t(key) : option;
}

function SourceBadge({ field }: { field: SettingField }) {
    const { t } = useTranslation();
    if (field.source === 'panel') return <Badge variant="default">{t('platformSettings.source.panel')}</Badge>;
    if (field.source === 'server') return <Badge variant="outline">{t('platformSettings.source.server')}</Badge>;
    return field.type === 'secret' ? <Badge variant="outline">{t('platformSettings.source.none')}</Badge> : null;
}

/** Un secreto nunca vuelve del hub: el campo empieza vacío y dice si ya hay uno; escribir uno nuevo lo reemplaza al guardar. */
function SecretInput({ id, field, draft, onChange }: { id: string; field: SettingField; draft: Draft; onChange: (key: string, value: string | boolean | null) => void }) {
    const { t } = useTranslation();
    const [visible, setVisible] = useState(false);
    const pending = draft[field.key];
    const removing = pending === null;
    const value = typeof pending === 'string' ? pending : '';
    const generated = field.generate === 'token' && visible && value !== '';
    const hint = field.hint ?? '';
    const placeholder = removing
        ? t('platformSettings.secret.removed')
        : field.source === 'panel'
          ? t('platformSettings.secret.panel', { hint })
          : field.source === 'server'
            ? t('platformSettings.secret.server', { hint })
            : t('platformSettings.secret.none');

    if (field.generate === 'vapid') {
        return <VapidButton configured={field.configured} />;
    }

    return (
        <div className="igr:space-y-1.5">
            <div className="igr:flex igr:gap-1.5">
                <Input
                    id={id}
                    type={visible ? 'text' : 'password'}
                    value={value}
                    autoComplete="new-password"
                    spellCheck={false}
                    placeholder={placeholder}
                    onChange={(e) => onChange(field.key, e.target.value)}
                />
                <Button
                    type="button"
                    variant="outline"
                    size="icon"
                    aria-label={t(visible ? 'platformSettings.secret.hide' : 'platformSettings.secret.show')}
                    onClick={() => setVisible((v) => !v)}
                >
                    {visible ? <EyeOff /> : <Eye />}
                </Button>
                {field.generate === 'token' ? (
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => {
                            onChange(field.key, randomToken());
                            setVisible(true);
                        }}
                    >
                        <Wand2 />
                        {t('platformSettings.generate')}
                    </Button>
                ) : null}
                {field.source === 'panel' && !removing ? (
                    <Button type="button" variant="ghost" onClick={() => onChange(field.key, null)}>
                        {t('platformSettings.secret.remove')}
                    </Button>
                ) : null}
            </div>
            {generated ? (
                <div className="igr:flex igr:flex-wrap igr:items-center igr:gap-2 igr:rounded-md igr:border igr:border-warning/40 igr:bg-warning/10 igr:p-2 igr:text-xs">
                    <span>{t('platformSettings.generated')}</span>
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        onClick={() => void navigator.clipboard.writeText(value).then(() => toast.success(t('platformSettings.copied')))}
                    >
                        <Copy />
                        {t('platformSettings.copy')}
                    </Button>
                </div>
            ) : null}
        </div>
    );
}

function VapidButton({ configured }: { configured: boolean }) {
    const { t } = useTranslation();
    const generate = useGenerateVapid();
    return (
        <Button
            type="button"
            variant="outline"
            loading={generate.isPending}
            onClick={() => {
                if (configured && !window.confirm(t('platformSettings.push.regenerateConfirm'))) return;
                generate.mutate(undefined, {
                    onSuccess: () => toast.success(t('platformSettings.push.generated')),
                    onError: (e) => toast.error(t('errors.title'), isApiError(e) ? e.message : undefined),
                });
            }}
        >
            <KeyRound />
            {t(configured ? 'platformSettings.push.regenerate' : 'platformSettings.push.generate')}
        </Button>
    );
}

function CopyValue({ label, value }: { label: string; value: string }) {
    const { t } = useTranslation();
    return (
        <div className="igr:flex igr:flex-wrap igr:items-center igr:gap-x-2 igr:gap-y-1 igr:text-xs">
            <span className="igr:text-muted-foreground">{label}</span>
            <code className="igr:break-all igr:rounded igr:bg-muted igr:px-1.5 igr:py-0.5 igr:font-mono">{value}</code>
            <Button
                type="button"
                variant="ghost"
                size="icon"
                className="igr:size-6"
                aria-label={t('platformSettings.copy')}
                onClick={() => void navigator.clipboard.writeText(value).then(() => toast.success(t('platformSettings.copied')))}
            >
                <Copy className="igr:size-3.5" />
            </Button>
        </div>
    );
}

/**
 * Probar el correo de la plataforma: a la dirección que elija el administrador (la suya por defecto), con el resultado
 * a la vista hasta la próxima prueba (no solo un aviso que se va): qué aceptó el servidor o por qué no salió.
 */
function MailTestForm() {
    const { t } = useTranslation();
    const me = useMe();
    const test = useMailTest();
    const [to, setTo] = useState('');
    const [result, setResult] = useState<MailTestResult | null>(null);
    const target = to.trim() || me.data?.user.email || '';
    // Va dentro del formulario de la sección: no es un <form> propio (no se anidan) y no envía el de afuera.
    const submit = () => {
        if (target === '' || test.isPending) return;
        setResult(null);
        test.mutate(to.trim() ? { to: to.trim() } : {}, {
            onSuccess: (r) => {
                setResult(r);
                toast.success(t('platform.mail.sent', { to: r.to }));
            },
            onError: (err) => {
                const body = isApiError(err) && err.body && typeof err.body === 'object' && 'sent' in err.body ? (err.body as MailTestResult) : null;
                if (body) {
                    setResult(body);
                } else if (!isApiError(err) || !err.fieldErrors.to) {
                    toast.error(t('platform.mail.failed'), isApiError(err) ? err.message : undefined);
                }
            },
        });
    };
    return (
        <div className="igr:space-y-2">
            <Label htmlFor="mail-test-to">{t('platformSettings.mail.testTo')}</Label>
            <div className="igr:flex igr:flex-wrap igr:items-start igr:gap-2">
                <Input
                    id="mail-test-to"
                    type="email"
                    autoComplete="email"
                    className="igr:min-w-0 igr:flex-1 igr:basis-56"
                    placeholder={me.data?.user.email ?? ''}
                    value={to}
                    onChange={(e) => setTo(e.target.value)}
                    onKeyDown={(e) => {
                        if (e.key === 'Enter') {
                            e.preventDefault();
                            submit();
                        }
                    }}
                />
                <Button type="button" size="sm" variant="outline" className="igr:h-9" loading={test.isPending} disabled={target === ''} onClick={submit}>
                    <Send />
                    {t('platformSettings.mail.test')}
                </Button>
            </div>
            <FieldError message={isApiError(test.error) ? test.error.fieldErrors.to : undefined} />
            <p className="igr:text-xs igr:text-muted-foreground">{t('platformSettings.mail.testHelp')}</p>
            {result ? (
                <Callout tone={result.sent ? 'success' : 'danger'} title={result.sent ? t('platformSettings.mail.sentTitle') : t('platformSettings.mail.failedTitle')}>
                    <p>{result.message}</p>
                    <ul className="igr:mt-1 igr:space-y-0.5 igr:text-xs igr:text-muted-foreground">
                        {result.sent ? <li>{t('platform.mail.sent', { to: result.to })}</li> : null}
                        {result.from ? <li>{t('platformSettings.mail.from', { from: result.from })}</li> : null}
                        {result.server_response ? <li className="igr:break-all">{t('platformSettings.mail.server', { response: result.server_response })}</li> : null}
                        {result.message_id ? <li className="igr:break-all">{t('platformSettings.mail.messageId', { id: result.message_id })}</li> : null}
                    </ul>
                    {result.detail ? (
                        <details className="igr:mt-1 igr:text-xs">
                            <summary className="igr:cursor-pointer igr:text-muted-foreground">{t('platformSettings.mail.detail')}</summary>
                            <p className="igr:mt-1 igr:break-all igr:font-mono">{result.detail}</p>
                        </details>
                    ) : null}
                </Callout>
            ) : null}
        </div>
    );
}

/** Lo propio de cada sección: probar el correo, preparar SES, las direcciones a registrar en Meta, Google y TikTok… */
function SectionExtras({ section }: { section: SettingsSection }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const setup = useSesSetup();
    const info = section.info;
    const box = 'igr:space-y-2 igr:rounded-md igr:border igr:bg-muted/40 igr:p-3';

    switch (section.key) {
        case 'mail':
            return (
                <div className={box}>
                    <p className="igr:text-[13px]">
                        {info.configured
                            ? t('platformSettings.mail.on', { mailer: t(`platformSettings.options.mail.driver.${info.mailer ?? ''}`, { defaultValue: info.mailer ?? '' }) })
                            : t('platformSettings.mail.off')}
                    </p>
                    {info.configured ? <MailTestForm /> : null}
                </div>
            );
        case 'ses': {
            const last = info.last_setup ?? null;
            return (
                <div className={box}>
                    {info.webhook_url ? (
                        <>
                            <p className="igr:text-xs igr:text-muted-foreground">{t('platformSettings.ses.subscribe')}</p>
                            <CopyValue label={t('platformSettings.urls.webhook')} value={info.webhook_url} />
                        </>
                    ) : null}
                    <div className="igr:flex igr:flex-wrap igr:items-center igr:gap-2">
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            loading={setup.isPending}
                            onClick={() =>
                                setup.mutate(undefined, {
                                    onSuccess: (r) =>
                                        r.result?.status === 'ok'
                                            ? toast.success(t('platformSettings.ses.result.ok'), r.result.message)
                                            : toast.error(t(`platformSettings.ses.result.${r.result?.status ?? 'error'}`), r.result?.message),
                                    onError: (e) => toast.error(t('errors.title'), isApiError(e) ? e.message : undefined),
                                })
                            }
                        >
                            <RefreshCw />
                            {t('platformSettings.ses.setup')}
                        </Button>
                        {last ? (
                            <Badge variant={last.status === 'ok' ? 'success' : last.status === 'no_topic' ? 'warning' : 'destructive'}>
                                {t(`platformSettings.ses.result.${last.status}`)}
                            </Badge>
                        ) : null}
                    </div>
                    <p className="igr:text-xs igr:text-muted-foreground">{t('platformSettings.ses.setupHelp')}</p>
                    {last ? (
                        <p className="igr:text-xs igr:text-muted-foreground">
                            {last.message} {t('platformSettings.ses.last', { at: formatDateTime(last.checked_at, workspace.locale) })}
                        </p>
                    ) : (
                        <p className="igr:text-xs igr:text-muted-foreground">{t('platformSettings.ses.never')}</p>
                    )}
                </div>
            );
        }
        case 'meta':
        case 'google':
        case 'tiktok':
            return (
                <div className={box}>
                    <p className="igr:text-[13px] igr:font-medium">{t('platformSettings.urls.title')}</p>
                    {info.callback_url ? <CopyValue label={t('platformSettings.urls.callback')} value={info.callback_url} /> : null}
                    {info.webhook_url ? <CopyValue label={t('platformSettings.urls.webhook')} value={info.webhook_url} /> : null}
                    {info.domain ? <CopyValue label={t('platformSettings.urls.domain')} value={info.domain} /> : null}
                    {section.key === 'meta' ? <p className="igr:text-xs igr:text-muted-foreground">{t('platformSettings.meta.webhookFields')}</p> : null}
                    {section.key === 'meta' && (info.connected_accounts ?? 0) > 0 ? (
                        <p className="igr:text-xs igr:text-muted-foreground">{t('platformSettings.meta.accounts', { count: info.connected_accounts })}</p>
                    ) : null}
                    {section.key === 'google' && info.calendar_legacy ? <p className="igr:text-xs igr:text-muted-foreground">{t('platformSettings.google.legacy')}</p> : null}
                </div>
            );
        case 'security': {
            const sites = info.signature_v1_sites ?? [];
            return (
                <div className={box}>
                    <p className="igr:text-[13px]">
                        {sites.length === 0
                            ? t('platformSettings.security.signatureAll')
                            : t('platformSettings.security.signatureSome', { count: sites.length, version: info.signature_v2_min_plugin ?? '' })}
                    </p>
                    {sites.length > 0 ? (
                        <ul className="igr:list-disc igr:space-y-0.5 igr:pl-5 igr:text-xs igr:text-muted-foreground">
                            {sites.slice(0, 20).map((s) => (
                                <li key={s}>{s}</li>
                            ))}
                        </ul>
                    ) : null}
                    {info.health_url ? <CopyValue label={t('platformSettings.security.healthUrl')} value={info.health_url} /> : null}
                </div>
            );
        }
        default:
            return null;
    }
}
