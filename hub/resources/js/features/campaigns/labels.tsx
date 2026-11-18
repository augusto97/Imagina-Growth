import { Mail, MessageCircle, Smartphone, type LucideIcon } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Badge, type BadgeProps } from '@/components/ui/badge';
import type { CampaignChannel, CampaignStatus } from './types';

export const CHANNEL_ICON: Record<CampaignChannel, LucideIcon> = { email: Mail, whatsapp: MessageCircle, sms: Smartphone };

const STATUS_VARIANT: Record<CampaignStatus, NonNullable<BadgeProps['variant']>> = {
    draft: 'outline',
    scheduled: 'default',
    sending: 'warning',
    sent: 'success',
    paused: 'secondary',
    cancelled: 'outline',
};

export function statusVariant(status: CampaignStatus): NonNullable<BadgeProps['variant']> {
    return STATUS_VARIANT[status];
}

/** Estado con texto, nunca solo color. */
export function StatusBadge({ status }: { status: CampaignStatus }) {
    const { t } = useTranslation();
    return <Badge variant={statusVariant(status)}>{t(`campaigns.status.${status}`)}</Badge>;
}

export function ChannelLabel({ channel }: { channel: CampaignChannel }) {
    const { t } = useTranslation();
    const Icon = CHANNEL_ICON[channel];
    return (
        <span className="igr:inline-flex igr:items-center igr:gap-1.5 igr:text-muted-foreground">
            <Icon className="igr:size-3.5" aria-hidden="true" />
            {t(`campaigns.channel.${channel}`)}
        </span>
    );
}
