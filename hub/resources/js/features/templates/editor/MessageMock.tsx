import { CheckCheck, ExternalLink, Mail, Reply } from 'lucide-react';
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { cn } from '@/lib/cn';

/*
 * Vistas previas «como las va a ver tu cliente» (Mensajes): un celular con el chat de WhatsApp o los mensajes de texto
 * y un correo en la bandeja. Imitan a propósito los colores de WhatsApp (verde de la barra, fondo del chat, burbuja
 * blanca), que no cambian con el modo oscuro del panel: es lo que verá la persona en su teléfono. Se usan en el editor
 * de plantillas, la galería, el asistente de campañas y la vista previa de la biblioteca de automatizaciones.
 */

function initials(name: string): string {
    const parts = name.trim().split(/\s+/).filter(Boolean);
    return ((parts[0]?.[0] ?? '') + (parts[1]?.[0] ?? '')).toUpperCase() || '·';
}

/** El marco del celular con la barra del chat (WhatsApp o mensajes de texto). `compact`: sin el alto mínimo (dentro de una lista). */
export function PhoneMock({ business, variant = 'whatsapp', compact = false, children, className }: { business: string; variant?: 'whatsapp' | 'sms'; compact?: boolean; children: ReactNode; className?: string }) {
    const { t } = useTranslation();
    const wa = variant === 'whatsapp';
    return (
        <div className={cn('igr:mx-auto igr:w-full igr:max-w-[320px]', className)}>
            <div className="igr:overflow-hidden igr:rounded-[30px] igr:border-[7px] igr:border-slate-900 igr:bg-slate-900 igr:shadow-lg igr:dark:border-slate-700">
                <div className={cn('igr:flex igr:items-center igr:gap-2.5 igr:px-3 igr:py-2.5', wa ? 'igr:bg-[#008069] igr:text-white' : 'igr:bg-slate-100 igr:text-slate-900')}>
                    <span aria-hidden className={cn('igr:grid igr:size-8 igr:shrink-0 igr:place-items-center igr:rounded-full igr:text-[12px] igr:font-semibold', wa ? 'igr:bg-white/20' : 'igr:bg-slate-300')}>
                        {initials(business)}
                    </span>
                    <div className="igr:min-w-0">
                        <p className="igr:truncate igr:text-[13.5px] igr:leading-tight igr:font-semibold">{business}</p>
                        <p className={cn('igr:text-[11px] igr:leading-tight', wa ? 'igr:text-white/80' : 'igr:text-slate-500')}>{wa ? t('templates.mock.businessAccount') : t('templates.mock.sms')}</p>
                    </div>
                </div>
                <div className={cn('igr:flex igr:flex-col igr:gap-1 igr:p-3', !compact && 'igr:min-h-[300px]', wa ? 'igr:bg-[#efeae2]' : 'igr:bg-white')}>{children}</div>
            </div>
        </div>
    );
}

/** Una burbuja de WhatsApp de la empresa: encabezado, cuerpo, pie, hora y los botones debajo. */
export function WaBubble({ header, body, footer, buttons = [], time = '10:00' }: { header?: string | null; body: string; footer?: string | null; buttons?: { text: string; url?: boolean }[]; time?: string }) {
    return (
        <div className="igr:max-w-[88%] igr:self-start">
            <div className="igr:rounded-lg igr:rounded-tl-none igr:bg-white igr:px-2.5 igr:pt-1.5 igr:pb-1 igr:text-[13.5px] igr:leading-[19px] igr:text-[#111b21] igr:shadow-[0_1px_0.5px_rgba(11,20,26,0.13)]">
                {header ? <p className="igr:mb-0.5 igr:font-semibold">{header}</p> : null}
                <p className="igr:break-words igr:whitespace-pre-wrap">{body}</p>
                {footer ? <p className="igr:mt-1 igr:text-[12px] igr:text-[#667781]">{footer}</p> : null}
                <p className="igr:mt-0.5 igr:flex igr:items-center igr:justify-end igr:gap-1 igr:text-[11px] igr:text-[#667781]">
                    {time}
                    <CheckCheck className="igr:size-3.5 igr:text-[#53bdeb]" aria-hidden />
                </p>
            </div>
            {buttons.length > 0 ? (
                <div className="igr:mt-0.5 igr:overflow-hidden igr:rounded-lg igr:bg-white igr:shadow-[0_1px_0.5px_rgba(11,20,26,0.13)]">
                    {buttons.map((b, i) => (
                        <p key={`${b.text}-${i}`} className={cn('igr:flex igr:items-center igr:justify-center igr:gap-1.5 igr:px-3 igr:py-2 igr:text-[13.5px] igr:font-medium igr:text-[#027eb5]', i > 0 && 'igr:border-t igr:border-[#e9edef]')}>
                            {b.url ? <ExternalLink className="igr:size-3.5" aria-hidden /> : <Reply className="igr:size-3.5" aria-hidden />}
                            {b.text}
                        </p>
                    ))}
                </div>
            ) : null}
        </div>
    );
}

/** Un mensaje de texto (SMS) recibido. */
export function SmsBubble({ text }: { text: string }) {
    return <div className="igr:max-w-[85%] igr:self-start igr:rounded-2xl igr:rounded-bl-sm igr:bg-slate-200 igr:px-3 igr:py-2 igr:text-[13.5px] igr:whitespace-pre-wrap igr:text-slate-900">{text}</div>;
}

/** Un correo en la bandeja de entrada: quién lo envía, asunto, preencabezado y el inicio del texto. */
export function EmailMock({ business, subject, preheader, text, className }: { business: string; subject: string | null; preheader?: string | null; text?: string | null; className?: string }) {
    const { t } = useTranslation();
    return (
        <div className={cn('igr:overflow-hidden igr:rounded-xl igr:border igr:bg-card igr:shadow-xs', className)}>
            <div className="igr:flex igr:items-center igr:gap-2.5 igr:border-b igr:bg-muted/40 igr:px-3 igr:py-2.5">
                <span aria-hidden className="igr:grid igr:size-8 igr:shrink-0 igr:place-items-center igr:rounded-full igr:bg-brand-soft igr:text-[12px] igr:font-semibold igr:text-brand-soft-foreground">
                    {initials(business)}
                </span>
                <div className="igr:min-w-0 igr:flex-1">
                    <p className="igr:truncate igr:text-support igr:font-semibold igr:text-foreground">{business}</p>
                    <p className="igr:truncate igr:text-caption igr:text-muted-foreground">{t('templates.mock.toYou')}</p>
                </div>
                <Mail className="igr:size-4 igr:text-muted-foreground" aria-hidden />
            </div>
            <div className="igr:space-y-1 igr:px-3 igr:py-3">
                <p className="igr:text-body igr:font-semibold igr:text-foreground">{subject ?? t('templates.mock.noSubject')}</p>
                {preheader ? <p className="igr:text-support igr:text-muted-foreground">{preheader}</p> : null}
                {text ? <p className="igr:mt-2 igr:line-clamp-6 igr:text-support igr:whitespace-pre-wrap igr:text-foreground/90">{text}</p> : null}
            </div>
        </div>
    );
}
