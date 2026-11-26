import { ArrowRight, Mail, MessageCircle, MessageSquareText } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { useWorkspace } from '@/hooks/useWorkspace';
import { Badge } from '@/components/ui/badge';
import { Callout } from '@/components/ui/callout';
import { SegmentedControl } from '@/components/ui/segmented';
import type { CaptureWarning, CaptureWarningCode, ConsentChannel, LandingStatus } from './types';

/* Piezas que comparten las pantallas de captación: estado, canales con permiso, avisos y el periodo de las cifras. */

export const PERIODS = ['7', '30', '90'] as const;
export type Period = (typeof PERIODS)[number];

export function PeriodControl({ value, onChange }: { value: Period; onChange: (value: Period) => void }) {
    const { t } = useTranslation();
    return (
        <SegmentedControl
            size="sm"
            label={t('capture.period.label')}
            value={value}
            onChange={onChange}
            options={PERIODS.map((p) => ({ value: p, label: t('capture.period.days', { count: Number(p) }) }))}
        />
    );
}

export function PageStatusBadge({ status }: { status: LandingStatus }) {
    const { t } = useTranslation();
    return <Badge variant={status === 'published' ? 'success' : status === 'archived' ? 'outline' : 'secondary'}>{t(`capture.pages.status.${status}`)}</Badge>;
}

const CHANNEL_ICON = { email: Mail, whatsapp: MessageCircle, sms: MessageSquareText } as const;

/** Los canales con permiso (consentimiento) de un envío o de un lead, con ícono y nombre. */
export function ConsentBadges({ channels, empty }: { channels: readonly ConsentChannel[]; empty?: string }) {
    const { t } = useTranslation();
    if (channels.length === 0) {
        return <span className="igr:text-caption igr:text-muted-foreground">{empty ?? t('capture.consent.none')}</span>;
    }
    return (
        <span className="igr:inline-flex igr:flex-wrap igr:gap-1">
            {channels.map((channel) => {
                const Icon = CHANNEL_ICON[channel];
                return (
                    <Badge key={channel} variant="success">
                        <Icon className="igr:size-3" aria-hidden />
                        {t(`capture.channels.${channel}`)}
                    </Badge>
                );
            })}
        </span>
    );
}

/** Dónde se resuelve cada aviso (ruta dentro del espacio de trabajo y el texto del enlace). */
const FIX: Record<CaptureWarningCode, { to: string; label: string }> = {
    no_privacy_url: { to: 'consentimiento', label: 'privacy' },
    no_consent: { to: '', label: '' },
    no_leads: { to: 'facturacion', label: 'plan' },
    no_whatsapp_number: { to: 'conexiones', label: 'whatsapp' },
    whatsapp_not_connected: { to: 'conexiones', label: 'whatsapp' },
    site_disconnected: { to: 'sitios', label: 'site' },
};

/** Los avisos del hub (`warnings[]`), ya en palabras: qué falta para que la página o el chat funcionen bien y dónde se arregla. */
export function Warnings({ warnings, title, className }: { warnings: readonly CaptureWarning[]; title?: string; className?: string }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    if (warnings.length === 0) {
        return null;
    }
    return (
        <Callout tone="warning" className={className} title={title ?? t('capture.warnings.title', { count: warnings.length })}>
            <ul className="igr:mt-1 igr:space-y-1.5">
                {warnings.map((w) => {
                    const fix = FIX[w.code];
                    return (
                        <li key={w.code}>
                            {w.message}
                            {fix.to !== '' && workspace.abilities.includes('viewWorkspace') ? (
                                <>
                                    {' '}
                                    <Link
                                        to={`/w/${workspace.id}/${fix.to}`}
                                        className="igr:inline-flex igr:items-center igr:gap-0.5 igr:font-medium igr:whitespace-nowrap igr:text-foreground igr:underline-offset-2 igr:hover:underline"
                                    >
                                        {t(`capture.warnings.fix.${fix.label}`)}
                                        <ArrowRight className="igr:size-3" aria-hidden />
                                    </Link>
                                </>
                            ) : null}
                        </li>
                    );
                })}
            </ul>
        </Callout>
    );
}
