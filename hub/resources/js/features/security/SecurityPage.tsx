import { useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowLeft, Copy, Download, KeyRound, ShieldAlert, ShieldCheck } from 'lucide-react';
import { useState, type SyntheticEvent } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useNavigate, useSearchParams } from 'react-router';
import { keys, useMe } from '@/api/queries';
import { Logo } from '@/components/layout/Logo';
import { FullPageSkeleton } from '@/components/layout/guards';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { FieldError } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { usePasswordConfirm } from '@/hooks/usePasswordConfirm';
import { api, isApiError } from '@/lib/api';
import { safeNext } from '@/lib/forms';
import { toast } from '@/stores/toast';

type Step = 'intro' | 'setup' | 'codes' | 'enabled';

export function SecurityPage() {
    const { t } = useTranslation();
    const navigate = useNavigate();
    const qc = useQueryClient();
    const [params] = useSearchParams();
    const next = safeNext(params.get('next'));
    const required = params.get('requerido') === '1';
    const { data: me } = useMe();
    const { run, dialog } = usePasswordConfirm();

    const [step, setStep] = useState<Step>(() => (me?.user.two_factor_enabled === true ? 'enabled' : 'intro'));
    const [round, setRound] = useState(0);
    const [codes, setCodes] = useState<string[]>([]);
    const [code, setCode] = useState('');
    const [error, setError] = useState<string | undefined>();
    const [busy, setBusy] = useState(false);

    // Si la activación quedó a medias (secreto creado sin confirmar), se retoma en el paso del QR.
    const pending = me?.user.two_factor_pending === true;
    const showSetup = step === 'setup' || (step === 'intro' && pending);
    const setup = useQuery({
        queryKey: ['two-factor-setup', round],
        queryFn: async () => {
            const [qrRes, secretRes] = await Promise.all([
                run(() => api.get<{ svg: string }>('/auth/user/two-factor-qr-code')),
                run(() => api.get<{ secretKey: string }>('/auth/user/two-factor-secret-key')),
            ]);
            return { svg: qrRes.svg, secret: secretRes.secretKey };
        },
        enabled: showSetup,
        staleTime: Infinity,
        gcTime: 0,
        retry: false,
    });
    const view: Step = showSetup ? 'setup' : step;
    const qr = setup.data?.svg ?? null;
    const secret = setup.data?.secret ?? null;

    const enable = async () => {
        setBusy(true);
        try {
            await run(() => api.post('/auth/user/two-factor-authentication'));
            setRound((r) => r + 1);
            setStep('setup');
        } catch (e) {
            if (!(e instanceof Error && e.message === 'cancelled')) {
                toast.error(t('errors.generic'));
            }
        } finally {
            setBusy(false);
        }
    };

    const confirm = async (event: SyntheticEvent) => {
        event.preventDefault();
        setBusy(true);
        setError(undefined);
        try {
            await run(() => api.post('/auth/user/confirmed-two-factor-authentication', { code: code.replace(/\s/g, '') }));
            const list = await run(() => api.get<string[]>('/auth/user/two-factor-recovery-codes'));
            setCodes(list);
            setStep('codes');
            await qc.invalidateQueries({ queryKey: keys.me });
            toast.success(t('security.enabledToast'));
        } catch (e) {
            setError(isApiError(e, 'validation') ? t('auth.invalidCode') : t('errors.generic'));
        } finally {
            setBusy(false);
        }
    };

    const showCodes = async (regenerate: boolean) => {
        setBusy(true);
        try {
            if (regenerate) {
                await run(() => api.post('/auth/user/two-factor-recovery-codes'));
            }
            setCodes(await run(() => api.get<string[]>('/auth/user/two-factor-recovery-codes')));
            setStep('codes');
        } catch (e) {
            if (!(e instanceof Error && e.message === 'cancelled')) {
                toast.error(t('errors.generic'));
            }
        } finally {
            setBusy(false);
        }
    };

    const disable = async () => {
        if (!window.confirm(t('security.disableConfirm'))) {
            return;
        }
        setBusy(true);
        try {
            await run(() => api.delete('/auth/user/two-factor-authentication'));
            await qc.invalidateQueries({ queryKey: keys.me });
            setStep('intro');
            toast.info(t('security.disabledToast'));
        } catch (e) {
            if (!(e instanceof Error && e.message === 'cancelled')) {
                toast.error(t('errors.generic'));
            }
        } finally {
            setBusy(false);
        }
    };

    const copyCodes = async () => {
        await navigator.clipboard.writeText(codes.join('\n'));
        toast.success(t('common.copied'));
    };

    const downloadCodes = () => {
        const blob = new Blob([`${t('security.codesFileHeader')}\n\n${codes.join('\n')}\n`], { type: 'text/plain' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = 'imagina-growth-codigos-de-recuperacion.txt';
        a.click();
        URL.revokeObjectURL(url);
    };

    if (!me) {
        return <FullPageSkeleton />;
    }

    return (
        <div className="igr:min-h-full igr:bg-muted/40 igr:px-4 igr:py-10">
            <div className="igr:mx-auto igr:max-w-xl">
                <div className="igr:mb-6 igr:flex igr:items-center igr:justify-between">
                    <Logo />
                    {view !== 'setup' && !required ? (
                        <Button variant="ghost" size="sm" asChild>
                            <Link to={next}>
                                <ArrowLeft />
                                {t('common.back')}
                            </Link>
                        </Button>
                    ) : null}
                </div>
                {required && view !== 'codes' ? (
                    <div className="igr:mb-4 igr:flex igr:gap-2 igr:rounded-lg igr:border igr:border-warning/40 igr:bg-warning/10 igr:p-3 igr:text-[13px]">
                        <ShieldAlert className="igr:mt-0.5 igr:size-4 igr:shrink-0 igr:text-warning" />
                        <p>{t('security.requiredNotice')}</p>
                    </div>
                ) : null}
                <Card>
                    <CardHeader>
                        <div className="igr:flex igr:items-center igr:justify-between">
                            <CardTitle className="igr:text-base">{t('security.title')}</CardTitle>
                            {view === 'enabled' || view === 'codes' ? <Badge variant="success">{t('security.on')}</Badge> : <Badge variant="warning">{t('security.off')}</Badge>}
                        </div>
                        <CardDescription>{t('security.description')}</CardDescription>
                    </CardHeader>
                    <CardContent className="igr:space-y-4">
                        {view === 'intro' ? (
                            <>
                                <ol className="igr:list-decimal igr:space-y-1 igr:pl-5 igr:text-[13px] igr:text-muted-foreground">
                                    <li>{t('security.introStep1')}</li>
                                    <li>{t('security.introStep2')}</li>
                                    <li>{t('security.introStep3')}</li>
                                </ol>
                                <Button onClick={() => void enable()} loading={busy}>
                                    <ShieldCheck />
                                    {t('security.enable')}
                                </Button>
                            </>
                        ) : null}

                        {view === 'setup' ? (
                            <div className="igr:grid igr:gap-5 igr:sm:grid-cols-[180px_1fr]">
                                <div>
                                    {qr ? <div className="igr-qr" aria-label={t('security.qrLabel')} dangerouslySetInnerHTML={{ __html: qr }} /> : <Skeleton className="igr:size-44" />}
                                </div>
                                <form className="igr:space-y-3" onSubmit={(e) => void confirm(e)}>
                                    <p className="igr:text-[13px]">{t('security.scan')}</p>
                                    {secret ? (
                                        <div>
                                            <p className="igr:text-xs igr:text-muted-foreground">{t('security.manualKey')}</p>
                                            <code className="igr:mt-1 igr:block igr:rounded-md igr:bg-muted igr:px-2 igr:py-1.5 igr:font-mono igr:text-xs igr:break-all">{secret}</code>
                                        </div>
                                    ) : null}
                                    <div className="igr:space-y-1.5">
                                        <Label htmlFor="code">{t('security.enterCode')}</Label>
                                        <Input
                                            id="code"
                                            inputMode="numeric"
                                            autoComplete="one-time-code"
                                            className="igr:font-mono igr:tracking-[0.3em]"
                                            maxLength={7}
                                            value={code}
                                            onChange={(e) => setCode(e.target.value)}
                                            aria-invalid={error ? true : undefined}
                                            autoFocus
                                        />
                                        <FieldError message={error} />
                                    </div>
                                    <Button type="submit" loading={busy} disabled={code.replace(/\s/g, '').length < 6}>
                                        {t('security.confirm')}
                                    </Button>
                                </form>
                            </div>
                        ) : null}

                        {view === 'codes' ? (
                            <>
                                <div className="igr:flex igr:gap-2 igr:rounded-lg igr:bg-muted igr:p-3 igr:text-[13px]">
                                    <KeyRound className="igr:mt-0.5 igr:size-4 igr:shrink-0 igr:text-muted-foreground" />
                                    <p>{t('security.codesHelp')}</p>
                                </div>
                                <ul className="igr:grid igr:grid-cols-2 igr:gap-2 igr:font-mono igr:text-[13px]">
                                    {codes.map((c) => (
                                        <li key={c} className="igr:rounded-md igr:border igr:px-2 igr:py-1.5 igr:text-center">
                                            {c}
                                        </li>
                                    ))}
                                </ul>
                                <div className="igr:flex igr:flex-wrap igr:gap-2">
                                    <Button variant="outline" onClick={() => void copyCodes()}>
                                        <Copy />
                                        {t('common.copy')}
                                    </Button>
                                    <Button variant="outline" onClick={downloadCodes}>
                                        <Download />
                                        {t('security.download')}
                                    </Button>
                                    <Button className="igr:ml-auto" onClick={() => void navigate(next, { replace: true })}>
                                        {t('common.continue')}
                                    </Button>
                                </div>
                            </>
                        ) : null}

                        {view === 'enabled' ? (
                            <>
                                <p className="igr:text-[13px] igr:text-muted-foreground">{t('security.enabledHelp')}</p>
                                <div className="igr:flex igr:flex-wrap igr:gap-2">
                                    <Button variant="outline" onClick={() => void showCodes(false)} loading={busy}>
                                        {t('security.viewCodes')}
                                    </Button>
                                    <Button variant="outline" onClick={() => void showCodes(true)} disabled={busy}>
                                        {t('security.regenerate')}
                                    </Button>
                                    <Button variant="ghost" className="igr:ml-auto igr:text-destructive" onClick={() => void disable()} disabled={busy}>
                                        {t('security.disable')}
                                    </Button>
                                </div>
                            </>
                        ) : null}
                    </CardContent>
                </Card>
            </div>
            {dialog}
        </div>
    );
}
