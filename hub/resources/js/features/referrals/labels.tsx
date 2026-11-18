import { ShieldAlert } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Badge } from '@/components/ui/badge';
import type { ConversionStatus, FraudSignal, Referral, ReferralStatus } from './types';

const REFERRAL_TONE: Record<ReferralStatus, 'outline' | 'default' | 'secondary' | 'success' | 'warning' | 'destructive'> = {
    clicked: 'outline',
    signed_up: 'secondary',
    ordered: 'default',
    qualified: 'warning',
    rewarded: 'success',
    rejected: 'destructive',
};

export function ReferralStatusBadge({ referral }: { referral: Referral }) {
    const { t } = useTranslation();
    return (
        <span className="igr:inline-flex igr:flex-wrap igr:items-center igr:gap-1">
            <Badge variant={REFERRAL_TONE[referral.status]}>{t(`referrals.status.${referral.status}`)}</Badge>
            {referral.review_status === 'pending_review' ? (
                <Badge variant="warning">
                    <ShieldAlert className="igr:size-3" aria-hidden />
                    {t('referrals.review.pending')}
                </Badge>
            ) : null}
        </span>
    );
}

export function SignalBadges({ signals }: { signals: FraudSignal[] }) {
    const { t } = useTranslation();
    if (signals.length === 0) {
        return <span className="igr:text-xs igr:text-muted-foreground">{t('referrals.signals.none')}</span>;
    }
    return (
        <span className="igr:inline-flex igr:flex-wrap igr:gap-1">
            {signals.map((s) => (
                <Badge key={s} variant="outline" title={t(`referrals.signals.help.${s}`)}>
                    <ShieldAlert className="igr:size-3 igr:text-warning" aria-hidden />
                    {t(`referrals.signals.${s}`)}
                </Badge>
            ))}
        </span>
    );
}

const CONVERSION_TONE: Record<ConversionStatus, 'warning' | 'default' | 'success' | 'outline'> = {
    pending: 'warning',
    approved: 'default',
    paid: 'success',
    void: 'outline',
};

export function ConversionStatusBadge({ status }: { status: ConversionStatus }) {
    const { t } = useTranslation();
    return <Badge variant={CONVERSION_TONE[status]}>{t(`referrals.conversions.status.${status}`)}</Badge>;
}

/** Fecha de un día (YYYY-MM-DD) del reporte. */
export function isoDay(date: Date): string {
    const y = date.getFullYear();
    const m = String(date.getMonth() + 1).padStart(2, '0');
    const d = String(date.getDate()).padStart(2, '0');
    return `${y}-${m}-${d}`;
}
