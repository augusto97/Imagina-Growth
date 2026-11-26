import { Mail, MessageCircle, MessageSquareText, type LucideIcon } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { cn } from '@/lib/cn';
import type { ConsentChannel } from '../api';
import { CONSENT_CHANNELS } from '../labels';

const ICONS: Record<ConsentChannel, LucideIcon> = { whatsapp: MessageCircle, email: Mail, sms: MessageSquareText };

/**
 * Por dónde se le puede escribir a la persona: un ícono por canal, prendido si aceptó mensajes de marketing. El color
 * nunca va solo: el nombre de cada canal y si acepta van en el texto para el lector de pantalla y en el `title`.
 */
export function ConsentIcons({ granted, onlyGranted = false, className }: { granted: readonly string[]; onlyGranted?: boolean; className?: string }) {
    const { t } = useTranslation();
    // En el celular solo los canales con permiso: el espacio es para el nombre y el resumen.
    const channels = onlyGranted ? CONSENT_CHANNELS.filter((c) => granted.includes(c)) : CONSENT_CHANNELS;
    if (channels.length === 0) {
        return null;
    }
    return (
        <span className={cn('igr:inline-flex igr:items-center igr:gap-1.5', className)}>
            {channels.map((channel) => {
                const on = granted.includes(channel);
                const Icon = ICONS[channel];
                const label = t(on ? 'contacts.list.consentOn' : 'contacts.list.consentOff', { channel: t(`contacts.consentChannels.${channel}`) });
                return (
                    <span key={channel} title={label} className={cn('igr:inline-flex', on ? 'igr:text-success' : 'igr:text-muted-foreground/45')}>
                        <Icon className="igr:size-4" aria-hidden />
                        <span className="igr:sr-only">{label}</span>
                    </span>
                );
            })}
        </span>
    );
}
