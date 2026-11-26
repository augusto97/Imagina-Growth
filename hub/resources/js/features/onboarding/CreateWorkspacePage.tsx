import { zodResolver } from '@hookform/resolvers/zod';
import { useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { useTranslation } from 'react-i18next';
import { Link, useNavigate, useSearchParams } from 'react-router';
import { z } from 'zod';
import { keys, useMe } from '@/api/queries';
import type { Workspace } from '@/api/types';
import { Logo } from '@/components/layout/Logo';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import { api } from '@/lib/api';
import { applyServerErrors, safeNext } from '@/lib/forms';
import { WorkspaceFields, type WorkspaceFormValues } from './WorkspaceFields';

export function CreateWorkspacePage() {
    const { t } = useTranslation();
    const navigate = useNavigate();
    const qc = useQueryClient();
    const [params] = useSearchParams();
    const { data: me } = useMe();
    const [formError, setFormError] = useState<string | null>(null);

    const schema = z.object({
        name: z
            .string()
            .trim()
            .min(2, t('validation.min', { count: 2 }))
            .max(120),
        country: z.string(),
        timezone: z.string(),
        currency: z.string(),
        locale: z.string(),
        industry: z.string(),
    });
    const form = useForm<WorkspaceFormValues>({
        resolver: zodResolver(schema),
        defaultValues: { name: params.get('nombre') ?? '', country: 'CO', timezone: 'America/Bogota', currency: 'COP', locale: 'es-CO', industry: '' },
    });

    const onSubmit = form.handleSubmit(async (values) => {
        setFormError(null);
        try {
            const ws = await api.post<Workspace>('/api/workspaces', { ...values, industry: values.industry === '' ? null : values.industry });
            await qc.invalidateQueries({ queryKey: keys.me });
            const next = params.get('next');
            await navigate(next ? safeNext(next) : `/w/${ws.id}`, { replace: true });
        } catch (error) {
            setFormError(applyServerErrors(error, form.setError, ['name'], t('errors.generic')));
        }
    });

    const firstTime = (me?.workspaces.length ?? 0) === 0;

    return (
        <div className="igr:min-h-full igr:hero-surface igr:px-4 igr:py-10">
            <div className="igr:mx-auto igr:max-w-xl">
                <div className="igr:mb-6 igr:flex igr:items-center igr:justify-between">
                    <Logo />
                    {firstTime ? null : (
                        <Button variant="ghost" size="sm" asChild>
                            <Link to="/">{t('common.cancel')}</Link>
                        </Button>
                    )}
                </div>
                <form onSubmit={(e) => void onSubmit(e)} noValidate>
                    <Card>
                        <CardHeader>
                            <CardTitle className="igr:text-base">
                                {firstTime ? t('onboarding.welcome', { name: me?.user.name.split(' ')[0] ?? '' }) : t('onboarding.newTitle')}
                            </CardTitle>
                            <CardDescription>{t('onboarding.description')}</CardDescription>
                        </CardHeader>
                        <CardContent>
                            <WorkspaceFields form={form} autoDefaults />
                            {formError ? (
                                <p role="alert" className="igr:mt-4 igr:rounded-md igr:bg-destructive/10 igr:px-3 igr:py-2 igr:text-[13px] igr:text-destructive">
                                    {formError}
                                </p>
                            ) : null}
                        </CardContent>
                        <CardFooter className="igr:justify-between">
                            <p className="igr:text-xs igr:text-muted-foreground">{t('onboarding.twoFactorNote')}</p>
                            <Button type="submit" loading={form.formState.isSubmitting}>
                                {t('onboarding.create')}
                            </Button>
                        </CardFooter>
                    </Card>
                </form>
            </div>
        </div>
    );
}
