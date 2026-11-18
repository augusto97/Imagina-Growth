import { AppWindow, Disc3, Gift, ListChecks, ListOrdered, type LucideIcon, MessageCircle, PanelLeft, PanelTop, TextCursorInput } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Badge } from '@/components/ui/badge';
import type { WidgetKind, WidgetStatus } from './types';

export const KIND_ICON: Record<WidgetKind, LucideIcon> = {
    popup: AppWindow,
    embedded_form: TextCursorInput,
    bar: PanelTop,
    spin_wheel: Disc3,
    teaser: PanelLeft,
    two_step: ListOrdered,
    quiz: ListChecks,
    wa_widget: MessageCircle,
    referral_welcome: Gift,
};

export function KindLabel({ kind }: { kind: WidgetKind }) {
    const { t } = useTranslation();
    const Icon = KIND_ICON[kind];
    return (
        <span className="igr:inline-flex igr:items-center igr:gap-1.5 igr:text-muted-foreground">
            <Icon className="igr:size-3.5" aria-hidden />
            {t(`onsite.kinds.${kind}.name`)}
        </span>
    );
}

export function StatusBadge({ status, live }: { status: WidgetStatus; live?: boolean }) {
    const { t } = useTranslation();
    if (status === 'active' && live === false) {
        return <Badge variant="warning">{t('onsite.status.scheduled')}</Badge>;
    }
    const variant = status === 'active' ? 'success' : status === 'paused' ? 'warning' : status === 'archived' ? 'outline' : 'secondary';
    return <Badge variant={variant}>{t(`onsite.status.${status}`)}</Badge>;
}
