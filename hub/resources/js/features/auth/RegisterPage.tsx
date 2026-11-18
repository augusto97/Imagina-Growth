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
import { api, ensureCsrf } from '@/lib/api';
import { applyServerErrors, safeNext } from '@/lib/forms';
import { AuthLayout } from './AuthLayout';

export function RegisterPage() {
    const { t } = useTranslation();
    const navigate = useNavigate();
    const qc = useQueryClient();
    const [params] = useSearchParams();
    const next = safeNext(params.get('next'), '/onboarding');
    const [formError, setFormError] = useState<string | null>(null);

    const schema = z
        .object({
            name: z.string().trim().min(2, t('validation.min', { count: 2 })).max(255),
            email: z.string().trim().pipe(z.email(t('validation.email'))),
            password: z.string().min(8, t('validation.passwordMin', { count: 8 })),
            password_confirmation: z.string(),
        })
        .refine((v) => v.password === v.password_confirmation, { path: ['password_confirmation'], message: t('validation.passwordMatch') });
    type Values = z.infer<typeof schema>;
    const form = useForm<Values>({
        resolver: zodResolver(schema),
        defaultValues: { name: '', email: params.get('email') ?? '', password: '', password_confirmation: '' },
    });
    const errors = form.formState.errors;

    const onSubmit = form.handleSubmit(async (values) => {
        setFormError(null);
        try {
            await ensureCsrf(true);
            await api.post('/auth/register', values);
            await qc.invalidateQueries({ queryKey: keys.me });
            await navigate(next, { replace: true });
        } catch (error) {
            setFormError(applyServerErrors(error, form.setError, ['name', 'email', 'password'], t('errors.generic')));
        }
    });

    return (
        <AuthLayout
            title={t('auth.registerTitle')}
            description={t('auth.registerDescription')}
            footer={
                <>
                    {t('auth.haveAccount')}{' '}
                    <Link className="igr:font-medium igr:text-foreground igr:underline-offset-4 igr:hover:underline" to={`/login?next=${encodeURIComponent(next)}`}>
                        {t('auth.login')}
                    </Link>
                </>
            }
        >
            <form className="igr:space-y-4" onSubmit={(e) => void onSubmit(e)} noValidate>
                <div className="igr:space-y-1.5">
                    <Label htmlFor="name">{t('auth.name')}</Label>
                    <Input id="name" autoComplete="name" autoFocus aria-invalid={errors.name ? true : undefined} {...form.register('name')} />
                    <FieldError message={errors.name?.message} />
                </div>
                <div className="igr:space-y-1.5">
                    <Label htmlFor="email">{t('auth.email')}</Label>
                    <Input id="email" type="email" autoComplete="email" aria-invalid={errors.email ? true : undefined} {...form.register('email')} />
                    <FieldError message={errors.email?.message} />
                </div>
                <div className="igr:space-y-1.5">
                    <Label htmlFor="password">{t('auth.password')}</Label>
                    <Input id="password" type="password" autoComplete="new-password" aria-invalid={errors.password ? true : undefined} {...form.register('password')} />
                    <FieldError message={errors.password?.message} />
                </div>
                <div className="igr:space-y-1.5">
                    <Label htmlFor="password_confirmation">{t('auth.passwordConfirmation')}</Label>
                    <Input
                        id="password_confirmation"
                        type="password"
                        autoComplete="new-password"
                        aria-invalid={errors.password_confirmation ? true : undefined}
                        {...form.register('password_confirmation')}
                    />
                    <FieldError message={errors.password_confirmation?.message} />
                </div>
                {formError ? (
                    <p role="alert" className="igr:rounded-md igr:bg-destructive/10 igr:px-3 igr:py-2 igr:text-[13px] igr:text-destructive">
                        {formError}
                    </p>
                ) : null}
                <Button type="submit" className="igr:w-full" loading={form.formState.isSubmitting}>
                    {t('auth.createAccount')}
                </Button>
                <p className="igr:text-center igr:text-xs igr:text-muted-foreground">{t('auth.registerTwoFactorNote')}</p>
            </form>
        </AuthLayout>
    );
}
