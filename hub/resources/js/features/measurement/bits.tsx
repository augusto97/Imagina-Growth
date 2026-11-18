import { useTranslation } from 'react-i18next';
import { Badge } from '@/components/ui/badge';
import type { DeliveryStatus, DestinationMode, Platform } from './api';

export function PlatformName({ platform }: { platform: Platform }) {
    const { t } = useTranslation();
    return <>{t(`measurement.platform.${platform}`)}</>;
}

export function ModeBadge({ mode }: { mode: DestinationMode }) {
    const { t } = useTranslation();
    return <Badge variant={mode === 'full' ? 'default' : 'secondary'}>{t(`measurement.mode.${mode}.short`)}</Badge>;
}

export function DeliveryBadge({ status }: { status: DeliveryStatus }) {
    const { t } = useTranslation();
    const variant = status === 'delivered' ? 'success' : status === 'failed' ? 'destructive' : status === 'retrying' ? 'warning' : 'outline';
    return <Badge variant={variant}>{t(`measurement.status.${status}`)}</Badge>;
}

/** Iniciales de la plataforma con su color, sin depender de logos de marca. */
export function PlatformMark({ platform }: { platform: Platform }) {
    const tone = platform === 'meta' ? 'igr:bg-blue-600' : platform === 'google' ? 'igr:bg-emerald-600' : 'igr:bg-zinc-900 igr:dark:bg-zinc-100 igr:dark:text-zinc-900';
    return (
        <span aria-hidden className={`igr:inline-flex igr:size-8 igr:shrink-0 igr:items-center igr:justify-center igr:rounded-md igr:text-xs igr:font-semibold igr:text-white ${tone}`}>
            {platform === 'meta' ? 'M' : platform === 'google' ? 'G' : 'T'}
        </span>
    );
}
