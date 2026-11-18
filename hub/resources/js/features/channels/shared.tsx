import { Check, CircleAlert, Copy, ShieldAlert, TriangleAlert } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { isApiError } from '@/lib/api';
import type { AccountStatus, ChannelAccount } from './api';

export function StatusBadge({ status }: { status: AccountStatus }) {
    const { t } = useTranslation();
    const variant = status === 'active' ? 'success' : status === 'pending' || status === 'paused' ? 'warning' : status === 'error' ? 'destructive' : 'outline';
    return <Badge variant={variant}>{t(`channels.status.${status}`)}</Badge>;
}

/** Calidad del número de WhatsApp: siempre con ícono y palabra, nunca solo color. */
export function QualityBadge({ account }: { account: ChannelAccount }) {
    const { t } = useTranslation();
    if (account.provider !== 'meta_cloud') return null;
    const q = account.quality_rating ?? 'unknown';
    const known = q === 'GREEN' || q === 'YELLOW' || q === 'RED';
    const Icon = q === 'GREEN' ? Check : q === 'YELLOW' ? TriangleAlert : q === 'RED' ? ShieldAlert : CircleAlert;
    return (
        <Badge variant={q === 'GREEN' ? 'success' : q === 'YELLOW' ? 'warning' : q === 'RED' ? 'destructive' : 'outline'} title={q === 'YELLOW' ? t('channels.quality.yellowHelp') : q === 'RED' ? t('channels.quality.redHelp') : undefined}>
            <Icon className="igr:size-3" aria-hidden="true" />
            {t('channels.quality.label')}: {t(`channels.quality.${known ? q : 'unknown'}`)}
        </Badge>
    );
}

export function CopyButton({ value }: { value: string }) {
    const { t } = useTranslation();
    const [copied, setCopied] = useState(false);
    return (
        <Button
            type="button"
            variant="ghost"
            size="sm"
            aria-label={t('channels.email.copy')}
            onClick={() => {
                // En http (sin contexto seguro) no hay portapapeles: el valor sigue visible para copiarlo a mano.
                navigator.clipboard.writeText(value).catch(() => undefined);
                setCopied(true);
                window.setTimeout(() => setCopied(false), 1500);
            }}
        >
            {copied ? <Check /> : <Copy />}
            <span className="igr:sr-only igr:sm:not-sr-only">{copied ? t('channels.email.copied') : t('channels.email.copy')}</span>
        </Button>
    );
}

/** Error de un campo devuelto por la API (422), o null. */
export function fieldError(error: unknown, field: string): string | undefined {
    return isApiError(error) ? error.fieldErrors[field] : undefined;
}
