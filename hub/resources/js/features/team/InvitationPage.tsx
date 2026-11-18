import { useQueryClient } from '@tanstack/react-query';
import { MailCheck, MailX } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useNavigate, useParams } from 'react-router';
import { keys, useInvitation, useMe } from '@/api/queries';
import { AuthLayout } from '@/features/auth/AuthLayout';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { api, isApiError } from '@/lib/api';
import { formatDate } from '@/lib/format';

export function InvitationPage() {
    const { t } = useTranslation();
    const navigate = useNavigate();
    const qc = useQueryClient();
    const { token = '' } = useParams();
    const invitation = useInvitation(token);
    const { data: me } = useMe();
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const self = `/invitacion/${token}`;

    if (invitation.isPending) {
        return (
            <AuthLayout title={t('invitation.title')}>
                <Skeleton className="igr:h-20" />
            </AuthLayout>
        );
    }
    if (invitation.isError) {
        return (
            <div className="igr:mx-auto igr:max-w-md igr:p-10">
                <EmptyState icon={MailX} title={t('invitation.invalid')} description={t('invitation.invalidDescription')} action={<Button asChild variant="outline"><Link to="/">{t('common.goHome')}</Link></Button>} />
            </div>
        );
    }
    const inv = invitation.data;

    const accept = async () => {
        setBusy(true);
        setError(null);
        try {
            const res = await api.post<{ workspace_id: string }>(`/api/invitations/${encodeURIComponent(token)}/accept`);
            await qc.invalidateQueries({ queryKey: keys.me });
            await navigate(`/w/${res.workspace_id}`, { replace: true });
        } catch (e) {
            setError(isApiError(e, 'validation') ? (Object.values(e.fieldErrors)[0] ?? t('errors.generic')) : t('errors.generic'));
        } finally {
            setBusy(false);
        }
    };

    return (
        <AuthLayout
            title={t('invitation.title')}
            description={t('invitation.description', { workspace: inv.workspace ?? '', role: t(`roles.${inv.role}`) })}
        >
            <div className="igr:space-y-4 igr:text-[13px]">
                <div className="igr:flex igr:items-center igr:gap-2 igr:rounded-lg igr:bg-muted igr:p-3">
                    <MailCheck className="igr:size-4 igr:text-muted-foreground" />
                    <span>
                        {t('invitation.forEmail', { email: inv.email })} · {t('invitation.expires', { date: formatDate(inv.expires_at) })}
                    </span>
                </div>
                {me ? (
                    me.user.email.toLowerCase() === inv.email.toLowerCase() ? (
                        <Button className="igr:w-full" onClick={() => void accept()} loading={busy}>
                            {t('invitation.accept')}
                        </Button>
                    ) : (
                        <p className="igr:rounded-md igr:bg-warning/10 igr:px-3 igr:py-2">{t('invitation.otherAccount', { email: me.user.email })}</p>
                    )
                ) : (
                    <div className="igr:grid igr:gap-2">
                        <Button asChild>
                            <Link to={`/login?email=${encodeURIComponent(inv.email)}&next=${encodeURIComponent(self)}`}>{t('invitation.loginToAccept')}</Link>
                        </Button>
                        {inv.has_account ? null : (
                            <Button asChild variant="outline">
                                <Link to={`/registro?email=${encodeURIComponent(inv.email)}&next=${encodeURIComponent(self)}`}>{t('invitation.registerToAccept')}</Link>
                            </Button>
                        )}
                    </div>
                )}
                {error ? <p role="alert" className="igr:text-destructive">{error}</p> : null}
            </div>
        </AuthLayout>
    );
}
