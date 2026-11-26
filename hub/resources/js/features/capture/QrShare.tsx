import { Download, ExternalLink, MessageCircle, Printer } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import { CopyField } from '@/components/ui/copy-field';
import { Skeleton } from '@/components/ui/skeleton';
import { cn } from '@/lib/cn';
import { qrDownloadUrl } from './api';

/**
 * Compartir una página o un chat: el enlace para copiar o mandar por WhatsApp y el QR para imprimir (SVG para la
 * imprenta, PNG para redes o un documento). El QR apunta a su propia URL (con `utm_source=qr`), así sus visitas y sus
 * chats cuentan aparte.
 */
export function QrShare({
    url,
    qr,
    name,
    linkLabel,
    shareText,
    className,
}: {
    url: string;
    qr: { svg: string; png: string };
    name: string;
    linkLabel: string;
    /** Texto del mensaje de WhatsApp para compartir el enlace (el enlace va al final). */
    shareText?: string;
    className?: string;
}) {
    const { t } = useTranslation();
    const [loaded, setLoaded] = useState(false);
    const [failed, setFailed] = useState(false);
    const wa = `https://wa.me/?text=${encodeURIComponent(`${shareText ? `${shareText} ` : ''}${url}`)}`;
    return (
        <div className={cn('igr:grid igr:gap-6 igr:md:grid-cols-[220px_minmax(0,1fr)]', className)}>
            <figure className="igr:flex igr:flex-col igr:items-center igr:gap-2">
                <div className="igr:relative igr:size-[200px] igr:overflow-hidden igr:rounded-xl igr:border igr:bg-white igr:p-3 igr:shadow-xs">
                    {!loaded && !failed ? <Skeleton className="igr:absolute igr:inset-3" /> : null}
                    {failed ? (
                        <p className="igr:flex igr:h-full igr:items-center igr:justify-center igr:text-center igr:text-caption igr:text-slate-600">{t('capture.share.qrFailed')}</p>
                    ) : (
                        <img
                            src={qr.svg}
                            alt={t('capture.share.qrAlt', { name })}
                            width={176}
                            height={176}
                            className={cn('igr:size-full', loaded ? 'igr:opacity-100' : 'igr:opacity-0')}
                            onLoad={() => setLoaded(true)}
                            onError={() => setFailed(true)}
                        />
                    )}
                </div>
                <figcaption className="igr:max-w-[220px] igr:text-center igr:text-caption igr:text-muted-foreground">{t('capture.share.qrCaption')}</figcaption>
            </figure>
            <div className="igr:min-w-0 igr:space-y-5">
                <CopyField label={linkLabel} value={url} hint={t('capture.share.linkHint')} />
                <div className="igr:flex igr:flex-wrap igr:gap-2">
                    <Button asChild variant="outline">
                        <a href={wa} target="_blank" rel="noopener noreferrer">
                            <MessageCircle aria-hidden />
                            {t('capture.share.whatsapp')}
                        </a>
                    </Button>
                    <Button asChild variant="ghost">
                        <a href={url} target="_blank" rel="noopener noreferrer">
                            <ExternalLink aria-hidden />
                            {t('capture.share.open')}
                        </a>
                    </Button>
                </div>
                <div>
                    <p className="igr:text-[13px] igr:font-medium">{t('capture.share.downloadTitle')}</p>
                    <p className="igr:mt-0.5 igr:text-caption igr:text-muted-foreground">{t('capture.share.downloadHelp')}</p>
                    <div className="igr:mt-2 igr:flex igr:flex-wrap igr:gap-2">
                        <Button asChild>
                            <a href={qrDownloadUrl(qr.svg)} download>
                                <Printer aria-hidden />
                                {t('capture.share.svg')}
                            </a>
                        </Button>
                        <Button asChild variant="outline">
                            <a href={qrDownloadUrl(qr.png, 1024)} download>
                                <Download aria-hidden />
                                {t('capture.share.png')}
                            </a>
                        </Button>
                    </div>
                </div>
            </div>
        </div>
    );
}
