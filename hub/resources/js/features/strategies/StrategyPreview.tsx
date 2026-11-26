import { Mail, MessageCircle, Sparkles } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { cn } from '@/lib/cn';
import type { StrategyPreview as Preview } from './types';

/*
 * La vista previa real de una estrategia (docs/diseno/rediseno-v2.md §6): los mensajes tal como le llegan a tu cliente
 * (WhatsApp, correo o el que escribe la IA), el formulario que aparece en tu web o, para lo demás, en qué consiste.
 * Los textos vienen del servidor con los datos de ejemplo del país del negocio.
 */

function WhatsAppBubble({ text, status }: { text: string; status: string | null }) {
    const { t } = useTranslation();
    return (
        <figure className="igr:min-w-0">
            <figcaption className="igr:mb-1.5 igr:flex igr:items-center igr:gap-1.5 igr:text-caption igr:font-medium igr:text-muted-foreground">
                <MessageCircle className="igr:size-3.5 igr:text-success" aria-hidden />
                {t('strategies.preview.whatsapp')}
                {status && status !== 'APPROVED' ? <span className="igr:font-normal">· {t('strategies.preview.waPending')}</span> : null}
            </figcaption>
            <div className="igr:rounded-xl igr:bg-muted/60 igr:p-3">
                <p className="igr:max-w-[92%] igr:rounded-lg igr:rounded-tl-sm igr:bg-success/12 igr:px-3 igr:py-2 igr:text-support igr:whitespace-pre-line igr:text-foreground igr:shadow-xs">
                    {text}
                </p>
            </div>
        </figure>
    );
}

function EmailCard({ subject, preheader, headline, intro, cta }: { subject?: string; preheader?: string; headline?: string; intro?: string; cta?: string }) {
    const { t } = useTranslation();
    return (
        <figure className="igr:min-w-0">
            <figcaption className="igr:mb-1.5 igr:flex igr:items-center igr:gap-1.5 igr:text-caption igr:font-medium igr:text-muted-foreground">
                <Mail className="igr:size-3.5 igr:text-info" aria-hidden />
                {t('strategies.preview.email')}
            </figcaption>
            <div className="igr:overflow-hidden igr:rounded-xl igr:border igr:bg-card">
                <div className="igr:border-b igr:bg-muted/40 igr:px-3 igr:py-2">
                    <p className="igr:truncate igr:text-support igr:font-semibold igr:text-foreground">{subject}</p>
                    {preheader ? <p className="igr:truncate igr:text-caption igr:text-muted-foreground">{preheader}</p> : null}
                </div>
                <div className="igr:space-y-1.5 igr:px-4 igr:py-3">
                    {headline ? <p className="igr:text-body igr:font-semibold igr:text-foreground">{headline}</p> : null}
                    {intro ? <p className="igr:text-support igr:text-muted-foreground">{intro}</p> : null}
                    {cta ? <span className="igr:mt-1 igr:inline-flex igr:rounded-md igr:bg-primary igr:px-3 igr:py-1.5 igr:text-caption igr:font-medium igr:text-primary-foreground">{cta}</span> : null}
                </div>
            </div>
        </figure>
    );
}

function AiBubble({ text }: { text: string }) {
    const { t } = useTranslation();
    return (
        <figure className="igr:min-w-0">
            <figcaption className="igr:mb-1.5 igr:flex igr:items-center igr:gap-1.5 igr:text-caption igr:font-medium igr:text-muted-foreground">
                <Sparkles className="igr:size-3.5 igr:text-brand-2" aria-hidden />
                {t('strategies.preview.ai')}
            </figcaption>
            <p className="igr:rounded-xl igr:border igr:border-dashed igr:bg-brand-soft/40 igr:px-3 igr:py-2.5 igr:text-support igr:text-foreground">{text}</p>
        </figure>
    );
}

/** Un mensaje o pieza de la vista previa; `compact` muestra solo la primera (el asistente y las tarjetas). */
export function StrategyPreview({ preview, compact = false, className }: { preview: Preview | null; compact?: boolean; className?: string }) {
    const { t } = useTranslation();
    if (preview === null) {
        return <p className={cn('igr:text-support igr:text-muted-foreground', className)}>{t('strategies.preview.none')}</p>;
    }
    if (preview.kind === 'messages') {
        const messages = compact ? preview.messages.slice(0, 1) : preview.messages;
        return (
            <div className={cn('igr:space-y-4', className)}>
                {messages.map((m, i) =>
                    m.channel === 'whatsapp' ? (
                        <WhatsAppBubble key={i} text={m.text} status={m.template_status} />
                    ) : m.channel === 'ai' ? (
                        <AiBubble key={i} text={m.text} />
                    ) : (
                        <EmailCard key={i} subject={m.subject} preheader={m.preheader} headline={m.headline} intro={m.intro} cta={m.cta} />
                    ),
                )}
                {compact && preview.messages.length > 1 ? (
                    <p className="igr:text-caption igr:text-muted-foreground">{t('strategies.preview.more', { count: preview.messages.length - 1 })}</p>
                ) : null}
            </div>
        );
    }
    if (preview.kind === 'widget') {
        return (
            <figure className={cn('igr:min-w-0', className)}>
                <figcaption className="igr:mb-1.5 igr:text-caption igr:font-medium igr:text-muted-foreground">{t('strategies.preview.widget')}</figcaption>
                <div className="igr:rounded-xl igr:bg-muted/60 igr:p-4">
                    <div className="igr:mx-auto igr:max-w-xs igr:rounded-xl igr:border igr:bg-card igr:p-4 igr:text-center igr:shadow-md">
                        <p className="igr:text-body igr:font-semibold igr:text-foreground">{preview.title}</p>
                        <p className="igr:mt-1 igr:text-support igr:text-muted-foreground">{preview.body}</p>
                        <div className="igr:mt-3 igr:space-y-2" aria-hidden>
                            {preview.fields.map((f) => (
                                <div key={f} className="igr:h-8 igr:rounded-md igr:border igr:bg-background igr:px-2.5 igr:text-left igr:text-caption igr:leading-8 igr:text-muted-foreground">
                                    {f}
                                </div>
                            ))}
                        </div>
                        <span className="igr:mt-3 igr:inline-flex igr:w-full igr:justify-center igr:rounded-md igr:bg-primary igr:px-3 igr:py-2 igr:text-caption igr:font-medium igr:text-primary-foreground">
                            {preview.cta}
                        </span>
                    </div>
                </div>
            </figure>
        );
    }
    return (
        <ul className={cn('igr:space-y-2', className)}>
            {preview.lines.map((line) => (
                <li key={line} className="igr:flex igr:gap-2 igr:text-support igr:text-foreground">
                    <span aria-hidden className="igr:mt-2 igr:size-1.5 igr:shrink-0 igr:rounded-full igr:bg-primary" />
                    <span className="igr:min-w-0">{line}</span>
                </li>
            ))}
        </ul>
    );
}
