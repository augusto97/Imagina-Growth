import { Check, Copy } from 'lucide-react';
import { useEffect, useId, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { cn } from '@/lib/cn';
import { Button, type ButtonProps } from './button';

/**
 * Copia un texto al portapapeles y lo confirma en el botón (palomita por 1,5 s) y para el lector de pantalla. En http
 * (sin contexto seguro) no hay portapapeles: el valor sigue a la vista para copiarlo a mano.
 */
export async function copyText(value: string): Promise<boolean> {
    try {
        await navigator.clipboard.writeText(value);
        return true;
    } catch {
        return false;
    }
}

/**
 * Botón de copiar (rediseño v2 §7). Con `children` muestra el texto («Copiar enlace»); sin él es un botón de ícono con
 * su nombre accesible en `label` (por defecto, «Copiar»).
 */
export function CopyButton({
    value,
    label,
    children,
    variant = 'outline',
    size,
    className,
    onCopied,
}: {
    value: string;
    label?: string;
    children?: React.ReactNode;
    variant?: ButtonProps['variant'];
    size?: ButtonProps['size'];
    className?: string;
    onCopied?: () => void;
}) {
    const { t } = useTranslation();
    const [copied, setCopied] = useState(false);
    const timer = useRef<number | null>(null);
    useEffect(() => () => (timer.current !== null ? window.clearTimeout(timer.current) : undefined), []);
    const name = label ?? t('ui.copy.copy');
    return (
        <Button
            type="button"
            variant={variant}
            size={size ?? (children ? 'md' : 'icon')}
            className={className}
            aria-label={children ? undefined : name}
            title={children ? undefined : name}
            onClick={() => {
                void copyText(value);
                setCopied(true);
                onCopied?.();
                if (timer.current !== null) window.clearTimeout(timer.current);
                timer.current = window.setTimeout(() => setCopied(false), 1500);
            }}
        >
            {copied ? <Check aria-hidden /> : <Copy aria-hidden />}
            {children ? <span>{copied ? t('ui.copy.copied') : children}</span> : null}
            <span className="igr:sr-only" aria-live="polite">
                {copied ? t('ui.copy.copied') : ''}
            </span>
        </Button>
    );
}

/**
 * Un valor para copiar (enlace, URL de un webhook, código): la etiqueta, el valor en una caja de solo lectura que se
 * puede seleccionar y el botón de copiar. El valor largo se corta con «…» y se ve completo al enfocarlo o seleccionarlo.
 */
export function CopyField({ label, value, hint, mono = false, className }: { label: React.ReactNode; value: string; hint?: React.ReactNode; mono?: boolean; className?: string }) {
    const id = useId();
    const { t } = useTranslation();
    return (
        <div className={cn('igr:min-w-0', className)}>
            <label htmlFor={id} className="igr:text-[13px] igr:font-medium igr:text-foreground">
                {label}
            </label>
            <div className="igr:mt-1.5 igr:flex igr:min-w-0 igr:items-center igr:gap-2">
                <input
                    id={id}
                    readOnly
                    value={value}
                    onFocus={(e) => e.currentTarget.select()}
                    className={cn(
                        'igr:h-9 igr:min-w-0 igr:flex-1 igr:truncate igr:rounded-md igr:border igr:border-input igr:bg-muted/50 igr:px-3 igr:text-[13px] igr:text-foreground igr:focus-visible:outline-none igr:focus-visible:ring-3 igr:focus-visible:ring-ring/25 igr:focus-visible:border-ring',
                        mono && 'igr:font-mono igr:text-xs',
                    )}
                />
                <CopyButton value={value} label={t('ui.copy.copyThing', { thing: typeof label === 'string' ? label.toLowerCase() : '' }).trim()} />
            </div>
            {hint ? <p className="igr:mt-1.5 igr:text-caption igr:text-muted-foreground">{hint}</p> : null}
        </div>
    );
}
