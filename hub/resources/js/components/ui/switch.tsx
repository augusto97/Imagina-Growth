import type { ButtonHTMLAttributes } from 'react';
import { cn } from '@/lib/cn';

/**
 * Interruptor (rediseño v2 §7): prende o apaga algo al instante, como una estrategia en el asistente. Es un botón con
 * `role="switch"` y `aria-checked`: se nombra con `aria-label` o con un `<Label htmlFor>` de su `id`, y Espacio o
 * Enter lo cambian. El estado nunca va solo en el color: la perilla cambia de lado.
 */
export function Switch({
    checked,
    onCheckedChange,
    className,
    disabled,
    ...props
}: Omit<ButtonHTMLAttributes<HTMLButtonElement>, 'onChange' | 'role' | 'type'> & { checked: boolean; onCheckedChange: (checked: boolean) => void }) {
    return (
        <button
            type="button"
            role="switch"
            aria-checked={checked}
            disabled={disabled}
            onClick={() => onCheckedChange(!checked)}
            className={cn(
                'igr:relative igr:inline-flex igr:h-6 igr:w-11 igr:shrink-0 igr:items-center igr:rounded-full igr:border-2 igr:border-transparent igr:transition-colors igr:duration-150',
                'igr:focus-visible:outline-none igr:focus-visible:ring-2 igr:focus-visible:ring-ring igr:focus-visible:ring-offset-2 igr:focus-visible:ring-offset-background',
                'igr:disabled:cursor-not-allowed igr:disabled:opacity-50',
                checked ? 'igr:bg-primary' : 'igr:bg-input',
                className,
            )}
            {...props}
        >
            <span
                aria-hidden
                className={cn(
                    'igr:pointer-events-none igr:block igr:size-5 igr:rounded-full igr:bg-background igr:shadow-sm igr:ring-0 igr:transition-transform igr:duration-150',
                    checked ? 'igr:translate-x-5' : 'igr:translate-x-0',
                )}
            />
        </button>
    );
}
