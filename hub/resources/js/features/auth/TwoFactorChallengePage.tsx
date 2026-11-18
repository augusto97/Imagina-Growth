import { useQueryClient } from '@tanstack/react-query';
import { useState, type SyntheticEvent } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useNavigate, useSearchParams } from 'react-router';
import { keys } from '@/api/queries';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { FieldError } from '@/components/ui/misc';
import { api, isApiError } from '@/lib/api';
import { safeNext } from '@/lib/forms';
import { AuthLayout } from './AuthLayout';

export function TwoFactorChallengePage() {
    const { t } = useTranslation();
    const navigate = useNavigate();
    const qc = useQueryClient();
    const [params] = useSearchParams();
    const next = safeNext(params.get('next'));
    const [recovery, setRecovery] = useState(false);
    const [value, setValue] = useState('');
    const [error, setError] = useState<string | undefined>();
    const [busy, setBusy] = useState(false);

    const submit = async (event: SyntheticEvent) => {
        event.preventDefault();
        setBusy(true);
        setError(undefined);
        try {
            await api.post('/auth/two-factor-challenge', recovery ? { recovery_code: value.trim() } : { code: value.replace(/\s/g, '') });
            await qc.invalidateQueries({ queryKey: keys.me });
            await navigate(next, { replace: true });
        } catch (e) {
            if (isApiError(e, 'validation')) {
                setError(recovery ? t('auth.invalidRecoveryCode') : t('auth.invalidCode'));
            } else if (isApiError(e, 'rate_limited')) {
                setError(t('auth.tooManyAttempts'));
            } else {
                setError(t('auth.sessionExpiredLogin'));
            }
        } finally {
            setBusy(false);
        }
    };

    return (
        <AuthLayout
            title={t('auth.challengeTitle')}
            description={recovery ? t('auth.challengeRecoveryDescription') : t('auth.challengeDescription')}
            footer={
                <Link to="/login" className="igr:hover:text-foreground">
                    {t('auth.backToLogin')}
                </Link>
            }
        >
            <form className="igr:space-y-4" onSubmit={(e) => void submit(e)}>
                <div className="igr:space-y-1.5">
                    <Label htmlFor="otp">{recovery ? t('auth.recoveryCode') : t('auth.code')}</Label>
                    <Input
                        id="otp"
                        autoFocus
                        autoComplete="one-time-code"
                        inputMode={recovery ? 'text' : 'numeric'}
                        className={recovery ? undefined : 'igr:font-mono igr:tracking-[0.3em]'}
                        maxLength={recovery ? 64 : 7}
                        value={value}
                        onChange={(e) => setValue(e.target.value)}
                        aria-invalid={error ? true : undefined}
                    />
                    <FieldError message={error} />
                </div>
                <Button type="submit" className="igr:w-full" loading={busy} disabled={value.trim() === ''}>
                    {t('auth.verify')}
                </Button>
                <button
                    type="button"
                    className="igr:w-full igr:text-center igr:text-xs igr:text-muted-foreground igr:hover:text-foreground"
                    onClick={() => {
                        setRecovery(!recovery);
                        setValue('');
                        setError(undefined);
                    }}
                >
                    {recovery ? t('auth.useAuthenticator') : t('auth.useRecoveryCode')}
                </button>
            </form>
        </AuthLayout>
    );
}
