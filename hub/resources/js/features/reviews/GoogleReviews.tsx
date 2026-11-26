import { ExternalLink, MessageCircle, Pencil, Star } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { Button } from '@/components/ui/button';
import { Callout } from '@/components/ui/callout';
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import { CopyField } from '@/components/ui/copy-field';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ErrorState, FieldError } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { errorMessage } from '@/features/contacts/errors';
import { useCan, useWorkspace } from '@/hooks/useWorkspace';
import { isApiError } from '@/lib/api';
import { toast } from '@/stores/toast';
import { useReviewSettings, useSaveReviewSettings } from './api';
import { settingsPayload } from './logic';
import type { ReviewSettingsResponse } from './types';

/** ¿Es un enlace https válido? (el de Google Business Profile: g.page/r/…/review o search.google.com/local/writereview). */
export function isReviewUrl(value: string): boolean {
    try {
        return new URL(value).protocol === 'https:';
    } catch {
        return false;
    }
}

/**
 * Reseñas de Google para un negocio sin tienda (modulos.md M15): el enlace de su Perfil de Empresa para pedir la reseña
 * a cada cliente después de atenderlo (por WhatsApp, en el QR del local o en la página de captura) y cómo pedirla bien.
 * Se pide a todos por igual: filtrar a los satisfechos va contra las políticas de Google.
 */
export function GoogleReviews() {
    const { workspace } = useWorkspace();
    const settings = useReviewSettings(workspace.id);
    if (settings.isError) return <ErrorState error={settings.error} onRetry={() => void settings.refetch()} />;
    if (settings.isPending) {
        return (
            <div className="igr:grid igr:gap-4 igr:lg:grid-cols-2">
                <Skeleton className="igr:h-64 igr:rounded-xl" />
                <Skeleton className="igr:h-64 igr:rounded-xl" />
            </div>
        );
    }
    return <Panel key={settings.data.settings.google_review_url ?? ''} data={settings.data} />;
}

function Panel({ data }: { data: ReviewSettingsResponse }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const canEdit = useCan('manageMarketing');
    const save = useSaveReviewSettings(workspace.id);
    const saved = data.settings.google_review_url ?? '';
    const [editing, setEditing] = useState(saved === '');
    const [url, setUrl] = useState(saved);
    const invalid = url.trim() !== '' && !isReviewUrl(url.trim());
    const serverError = isApiError(save.error) ? save.error.fieldErrors.google_review_url : undefined;
    const share = `https://wa.me/?text=${encodeURIComponent(`${t('reviews.google.shareText')} ${saved}`)}`;
    const base = `/w/${workspace.id}`;

    return (
        <div className="igr:grid igr:items-start igr:gap-4 igr:lg:grid-cols-[minmax(0,1.2fr)_minmax(0,1fr)]">
            <Card className="igr:min-w-0">
                <CardHeader>
                    <CardTitle className="igr:flex igr:items-center igr:gap-2">
                        <Star className="igr:size-4 igr:text-warning" aria-hidden />
                        {t('reviews.google.title')}
                    </CardTitle>
                    <CardDescription>{t('reviews.google.description')}</CardDescription>
                </CardHeader>
                <CardContent className="igr:space-y-4">
                    {!editing && saved !== '' ? (
                        <>
                            <CopyField label={t('reviews.google.link')} value={saved} hint={t('reviews.google.linkHint')} />
                            <div className="igr:flex igr:flex-wrap igr:gap-2">
                                <Button asChild>
                                    <a href={share} target="_blank" rel="noopener noreferrer">
                                        <MessageCircle aria-hidden />
                                        {t('reviews.google.shareWhatsapp')}
                                    </a>
                                </Button>
                                <Button asChild variant="ghost">
                                    <a href={saved} target="_blank" rel="noopener noreferrer">
                                        <ExternalLink aria-hidden />
                                        {t('reviews.google.open')}
                                    </a>
                                </Button>
                            </div>
                        </>
                    ) : (
                        <form
                            className="igr:space-y-3"
                            onSubmit={(e) => {
                                e.preventDefault();
                                if (invalid) return;
                                save.mutate(settingsPayload({ ...data.settings, google_review_url: url.trim() === '' ? null : url.trim() }), {
                                    onSuccess: () => {
                                        toast.success(t('reviews.google.saved'));
                                        setEditing(false);
                                    },
                                    onError: (err) => toast.error(errorMessage(err, t)),
                                });
                            }}
                        >
                            <fieldset disabled={!canEdit} className="igr:space-y-1.5">
                                <Label htmlFor="google-review-url">{t('reviews.google.link')}</Label>
                                <Input
                                    id="google-review-url"
                                    type="url"
                                    inputMode="url"
                                    placeholder="https://g.page/r/…/review"
                                    value={url}
                                    aria-invalid={invalid || undefined}
                                    onChange={(e) => setUrl(e.target.value)}
                                />
                                <p className="igr:text-caption igr:text-muted-foreground">{t('reviews.google.howToGet')}</p>
                                <FieldError message={invalid ? t('reviews.google.invalid') : serverError} />
                            </fieldset>
                            {canEdit ? (
                                <div className="igr:flex igr:flex-wrap igr:gap-2">
                                    <Button type="submit" loading={save.isPending} disabled={url.trim() === '' || invalid}>
                                        {t('reviews.google.save')}
                                    </Button>
                                    {saved !== '' ? (
                                        <Button type="button" variant="ghost" onClick={() => setEditing(false)}>
                                            {t('common.cancel')}
                                        </Button>
                                    ) : null}
                                </div>
                            ) : null}
                        </form>
                    )}
                </CardContent>
                {!editing && saved !== '' && canEdit ? (
                    <CardFooter>
                        <Button size="sm" variant="ghost" onClick={() => setEditing(true)}>
                            <Pencil aria-hidden />
                            {t('reviews.google.change')}
                        </Button>
                    </CardFooter>
                ) : null}
            </Card>
            <Card className="igr:min-w-0">
                <CardHeader>
                    <CardTitle>{t('reviews.google.tipsTitle')}</CardTitle>
                    <CardDescription>{t('reviews.google.tipsDescription')}</CardDescription>
                </CardHeader>
                <CardContent className="igr:space-y-3">
                    <ol className="igr:space-y-2.5 igr:text-support">
                        {(['tip1', 'tip2', 'tip3'] as const).map((key, i) => (
                            <li key={key} className="igr:flex igr:gap-2.5">
                                <span className="igr:flex igr:size-5 igr:shrink-0 igr:items-center igr:justify-center igr:rounded-full igr:bg-brand-soft igr:text-[11px] igr:font-semibold igr:text-brand-soft-foreground igr:tabular-nums">
                                    {i + 1}
                                </span>
                                <span className="igr:text-muted-foreground">{t(`reviews.google.${key}`)}</span>
                            </li>
                        ))}
                    </ol>
                    <div className="igr:flex igr:flex-wrap igr:gap-2">
                        <Button asChild size="sm" variant="outline">
                            <Link to={`${base}/whatsapp-qr`}>{t('reviews.google.toQr')}</Link>
                        </Button>
                        <Button asChild size="sm" variant="outline">
                            <Link to={`${base}/campanas`}>{t('reviews.google.toCampaign')}</Link>
                        </Button>
                    </div>
                    <Callout tone="info">{t('reviews.google.policy')}</Callout>
                </CardContent>
            </Card>
        </div>
    );
}
