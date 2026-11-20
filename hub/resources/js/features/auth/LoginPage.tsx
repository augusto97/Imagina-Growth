import { zodResolver } from '@hookform/resolvers/zod';
import { useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { useTranslation } from 'react-i18next';
import { Link, useNavigate, useSearchParams } from 'react-router';
import { z } from 'zod';
import { keys } from '@/api/queries';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { FieldError } from '@/components/ui/misc';
import { api, ensureCsrf, isApiError } from '@/lib/api';
import { safeNext } from '@/lib/forms';
import { registrationOpen } from '@/lib/runtime';
import { AuthLayout } from './AuthLayout';

export function LoginPage() {
    const { t } = useTranslation();
    const navigate = useNavigate();
    const qc = useQueryClient();
    const [params] = useSearchParams();
    const next = safeNext(params.get('next'));
    const [formError, setFormError] = useState<string | null>(null);

    const schema = z.object({
        email: z.string().trim().pipe(z.email(t('validation.email'))),
        password: z.string().min(1, t('validation.required')),
    });
    type Values = z.infer<typeof schema>;
    const form = useForm<Values>({ resolver: zodResolver(schema), defaultValues: { email: params.get('email') ?? '', password: '' } });

    const onSubmit = form.handleSubmit(async (values) => {
        setFormError(null);
        try {
            await ensureCsrf(true);
            const res = await api.post<{ two_factor?: boolean }>('/auth/login', { email: values.email, password: values.password, remember: true });
            if (res.two_factor === true) {
                await navigate(`/verificacion?next=${encodeURIComponent(next)}`);
                return;
            }
            await qc.invalidateQueries({ queryKey: keys.me });
            await navigate(next, { replace: true });
        } catch (error) {
            if (isApiError(error, 'validation')) {
                setFormError(t('auth.invalidCredentials'));
            } else if (isApiError(error, 'rate_limited')) {
                setFormError(t('auth.tooManyAttempts'));
            } else {
                setFormError(t('errors.generic'));
            }
        }
    });

    return (
        <AuthLayout
            title={t('auth.loginTitle')}
            description={t('auth.loginDescription')}
            footer={
                registrationOpen() || next.startsWith('/invitacion') ? (
                    <>
                        {t('auth.noAccount')}{' '}
                        <Link className="igr:font-medium igr:text-foreground igr:underline-offset-4 igr:hover:underline" to={`/registro?next=${encodeURIComponent(next)}`}>
                            {t('auth.createAccount')}
                        </Link>
                    </>
                ) : (
                    t('auth.inviteOnly')
                )
            }
        >
            <form className="igr:space-y-4" onSubmit={(e) => void onSubmit(e)} noValidate>
                <div className="igr:space-y-1.5">
                    <Label htmlFor="email">{t('auth.email')}</Label>
                    <Input id="email" type="email" autoComplete="email" autoFocus aria-invalid={form.formState.errors.email ? true : undefined} {...form.register('email')} />
                    <FieldError message={form.formState.errors.email?.message} />
                </div>
                <div className="igr:space-y-1.5">
                    <div className="igr:flex igr:items-center igr:justify-between">
                        <Label htmlFor="password">{t('auth.password')}</Label>
                        <Link to="/recuperar" className="igr:text-xs igr:text-muted-foreground igr:hover:text-foreground">
                            {t('auth.forgot')}
                        </Link>
                    </div>
                    <Input id="password" type="password" autoComplete="current-password" aria-invalid={form.formState.errors.password ? true : undefined} {...form.register('password')} />
                    <FieldError message={form.formState.errors.password?.message} />
                </div>
                {formError ? (
                    <p role="alert" className="igr:rounded-md igr:bg-destructive/10 igr:px-3 igr:py-2 igr:text-[13px] igr:text-destructive">
                        {formError}
                    </p>
                ) : null}
                <Button type="submit" className="igr:w-full" loading={form.formState.isSubmitting}>
                    {t('auth.login')}
                </Button>
            </form>
        </AuthLayout>
    );
}
