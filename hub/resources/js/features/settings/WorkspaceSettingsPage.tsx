import { zodResolver } from '@hookform/resolvers/zod';
import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { useTranslation } from 'react-i18next';
import { z } from 'zod';
import { useUpdateWorkspace } from '@/api/queries';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { FieldError, PageHeader } from '@/components/ui/misc';
import { WorkspaceFields, type WorkspaceFormValues } from '@/features/onboarding/WorkspaceFields';
import { useCan, useWorkspace } from '@/hooks/useWorkspace';
import { applyServerErrors } from '@/lib/forms';
import { toast } from '@/stores/toast';

export function WorkspaceSettingsPage() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const canManage = useCan('manageWorkspace');
    const update = useUpdateWorkspace(workspace.id);
    const [formError, setFormError] = useState<string | null>(null);

    const schema = z.object({
        name: z.string().trim().min(2, t('validation.min', { count: 2 })).max(120),
        country: z.string(),
        timezone: z.string(),
        currency: z.string(),
        locale: z.string(),
        industry: z.string(),
        legal_name: z.string().max(255).optional(),
        privacy_policy_url: z.union([z.literal(''), z.url(t('validation.url'))]).optional(),
    });
    const form = useForm<WorkspaceFormValues>({
        resolver: zodResolver(schema),
        values: {
            name: workspace.name,
            country: workspace.country,
            timezone: workspace.timezone,
            currency: workspace.currency,
            locale: workspace.locale,
            industry: workspace.industry ?? '',
            legal_name: workspace.legal_name ?? '',
            privacy_policy_url: workspace.privacy_policy_url ?? '',
        },
    });

    const onSubmit = form.handleSubmit(async (values) => {
        setFormError(null);
        try {
            await update.mutateAsync({
                ...values,
                industry: values.industry === '' ? null : values.industry,
                legal_name: values.legal_name ? values.legal_name : null,
                privacy_policy_url: values.privacy_policy_url ? values.privacy_policy_url : null,
            });
            toast.success(t('settings.saved'));
        } catch (error) {
            setFormError(applyServerErrors(error, form.setError, ['name', 'privacy_policy_url', 'legal_name'], t('errors.generic')));
        }
    });

    const errors = form.formState.errors;

    return (
        <>
            <PageHeader title={t('settings.title')} description={t('settings.description')} />
            <form onSubmit={(e) => void onSubmit(e)} noValidate className="igr:space-y-4">
                <Card>
                    <CardHeader>
                        <CardTitle>{t('settings.general')}</CardTitle>
                        <CardDescription>{t('settings.generalHelp')}</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <WorkspaceFields form={form} disabled={!canManage} />
                    </CardContent>
                </Card>
                <Card>
                    <CardHeader>
                        <CardTitle>{t('settings.legal')}</CardTitle>
                        <CardDescription>{t('settings.legalHelp')}</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <fieldset disabled={!canManage} className="igr:grid igr:gap-4 igr:sm:grid-cols-2">
                            <div className="igr:space-y-1.5">
                                <Label htmlFor="legal_name">{t('settings.legalName')}</Label>
                                <Input id="legal_name" {...form.register('legal_name')} />
                                <FieldError message={errors.legal_name?.message} />
                            </div>
                            <div className="igr:space-y-1.5">
                                <Label htmlFor="privacy">{t('settings.privacyUrl')}</Label>
                                <Input id="privacy" type="url" placeholder="https://" aria-invalid={errors.privacy_policy_url ? true : undefined} {...form.register('privacy_policy_url')} />
                                <FieldError message={errors.privacy_policy_url?.message} />
                            </div>
                        </fieldset>
                    </CardContent>
                    <CardFooter className="igr:justify-between">
                        {formError ? <p className="igr:text-[13px] igr:text-destructive">{formError}</p> : <span className="igr:text-xs igr:text-muted-foreground">{canManage ? '' : t('settings.readOnly')}</span>}
                        {canManage ? (
                            <Button type="submit" loading={form.formState.isSubmitting} disabled={!form.formState.isDirty}>
                                {t('common.save')}
                            </Button>
                        ) : null}
                    </CardFooter>
                </Card>
            </form>
        </>
    );
}
