import { useState, type SyntheticEvent } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useNavigate, useParams, useSearchParams } from 'react-router';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { FieldError } from '@/components/ui/misc';
import { toast } from '@/stores/toast';
import { api, ensureCsrf, isApiError } from '@/lib/api';
import { AuthLayout } from './AuthLayout';

export function ForgotPasswordPage() {
    const { t } = useTranslation();
    const [email, setEmail] = useState('');
    const [sent, setSent] = useState(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | undefined>();

    const submit = async (event: SyntheticEvent) => {
        event.preventDefault();
        setBusy(true);
        setError(undefined);
        try {
            await ensureCsrf(true);
            await api.post('/auth/forgot-password', { email: email.trim() });
            setSent(true);
        } catch (e) {
            // No se revela si el correo existe: el mensaje es el mismo.
            if (isApiError(e, 'validation') && e.fieldErrors.email !== undefined && !/find|encontr/i.test(e.fieldErrors.email)) {
                setError(t('validation.email'));
            } else if (isApiError(e, 'rate_limited')) {
                setError(t('auth.tooManyAttempts'));
            } else {
                setSent(true);
            }
        } finally {
            setBusy(false);
        }
    };

    return (
        <AuthLayout
            title={t('auth.forgotTitle')}
            description={sent ? t('auth.forgotSent') : t('auth.forgotDescription')}
            footer={
                <Link to="/login" className="igr:hover:text-foreground">
                    {t('auth.backToLogin')}
                </Link>
            }
        >
            {sent ? null : (
                <form className="igr:space-y-4" onSubmit={(e) => void submit(e)}>
                    <div className="igr:space-y-1.5">
                        <Label htmlFor="email">{t('auth.email')}</Label>
                        <Input id="email" type="email" autoComplete="email" autoFocus value={email} onChange={(e) => setEmail(e.target.value)} />
                        <FieldError message={error} />
                    </div>
                    <Button type="submit" className="igr:w-full" loading={busy} disabled={email.trim() === ''}>
                        {t('auth.sendLink')}
                    </Button>
                </form>
            )}
        </AuthLayout>
    );
}

export function ResetPasswordPage() {
    const { t } = useTranslation();
    const navigate = useNavigate();
    const { token = '' } = useParams();
    const [params] = useSearchParams();
    const [email, setEmail] = useState(params.get('email') ?? '');
    const [password, setPassword] = useState('');
    const [confirmation, setConfirmation] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | undefined>();

    const submit = async (event: SyntheticEvent) => {
        event.preventDefault();
        if (password !== confirmation) {
            setError(t('validation.passwordMatch'));
            return;
        }
        setBusy(true);
        setError(undefined);
        try {
            await ensureCsrf(true);
            await api.post('/auth/reset-password', { token, email: email.trim(), password, password_confirmation: confirmation });
            toast.success(t('auth.resetDone'));
            await navigate(`/login?email=${encodeURIComponent(email.trim())}`, { replace: true });
        } catch (e) {
            setError(isApiError(e, 'validation') ? (Object.values(e.fieldErrors)[0] ?? t('auth.resetInvalid')) : t('errors.generic'));
        } finally {
            setBusy(false);
        }
    };

    return (
        <AuthLayout title={t('auth.resetTitle')} description={t('auth.resetDescription')}>
            <form className="igr:space-y-4" onSubmit={(e) => void submit(e)}>
                <div className="igr:space-y-1.5">
                    <Label htmlFor="email">{t('auth.email')}</Label>
                    <Input id="email" type="email" autoComplete="email" value={email} onChange={(e) => setEmail(e.target.value)} />
                </div>
                <div className="igr:space-y-1.5">
                    <Label htmlFor="password">{t('auth.newPassword')}</Label>
                    <Input id="password" type="password" autoComplete="new-password" value={password} onChange={(e) => setPassword(e.target.value)} />
                </div>
                <div className="igr:space-y-1.5">
                    <Label htmlFor="confirmation">{t('auth.passwordConfirmation')}</Label>
                    <Input id="confirmation" type="password" autoComplete="new-password" value={confirmation} onChange={(e) => setConfirmation(e.target.value)} />
                    <FieldError message={error} />
                </div>
                <Button type="submit" className="igr:w-full" loading={busy} disabled={password === '' || email === ''}>
                    {t('auth.resetSubmit')}
                </Button>
            </form>
        </AuthLayout>
    );
}
