import { Camera, MessageCircle, MessagesSquare } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { cn } from '@/lib/cn';
import type { InboxChannel } from './types';

const ICONS = { whatsapp: MessageCircle, messenger: MessagesSquare, instagram: Camera } as const;
const COLORS = { whatsapp: 'igr:text-success', messenger: 'igr:text-primary', instagram: 'igr:text-pink-600' } as const;

/** Ícono del canal de la conversación (WhatsApp, Messenger o Instagram), con su nombre para lectores de pantalla. */
export function ChannelIcon({ channel, className }: { channel: InboxChannel; className?: string }) {
    const { t } = useTranslation();
    const Icon = ICONS[channel];
    return <Icon className={cn('igr:size-3.5 igr:shrink-0', COLORS[channel], className)} role="img" aria-label={t(`inbox.channel.${channel}`)} />;
}
