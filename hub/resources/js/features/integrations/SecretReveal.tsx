import { Check, Copy, TriangleAlert } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';

/** Un secreto que se muestra una sola vez, con botón de copiar y aviso. */
export function SecretReveal({ label, value }: { label: string; value: string }) {
    const { t } = useTranslation();
    const [copied, setCopied] = useState(false);
    return (
        <div className="igr:space-y-2">
            <p className="igr:text-[13px] igr:font-medium">{label}</p>
            <div className="igr:flex igr:items-center igr:gap-2">
                <code data-testid="secret-value" className="igr:min-w-0 igr:flex-1 igr:truncate igr:rounded-md igr:border igr:bg-muted igr:px-2 igr:py-1.5 igr:font-mono igr:text-xs">
                    {value}
                </code>
                <Button
                    variant="outline"
                    size="sm"
                    onClick={() =>
                        void navigator.clipboard.writeText(value).then(() => {
                            setCopied(true);
                        })
                    }
                >
                    {copied ? <Check className="igr:size-3.5" aria-hidden /> : <Copy className="igr:size-3.5" aria-hidden />}
                    {copied ? t('integrations.copied') : t('integrations.copy')}
                </Button>
            </div>
            <p className="igr:flex igr:items-start igr:gap-1.5 igr:text-xs igr:text-warning">
                <TriangleAlert className="igr:mt-0.5 igr:size-3.5 igr:shrink-0" aria-hidden />
                {t('integrations.shownOnce')}
            </p>
        </div>
    );
}

/** Texto copiable de una línea (URL, comando). */
export function CopyLine({ value, label }: { value: string; label: string }) {
    const { t } = useTranslation();
    return (
        <div className="igr:flex igr:items-center igr:gap-2">
            <code className="igr:min-w-0 igr:flex-1 igr:truncate igr:rounded-md igr:border igr:bg-muted igr:px-2 igr:py-1.5 igr:font-mono igr:text-xs" title={value}>
                {value}
            </code>
            <Button variant="outline" size="icon" aria-label={label} onClick={() => void navigator.clipboard.writeText(value).then(() => undefined)}>
                <Copy className="igr:size-3.5" aria-hidden />
            </Button>
            <span className="igr:sr-only">{t('integrations.copy')}</span>
        </div>
    );
}
