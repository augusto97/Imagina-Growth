import { useId, type ReactNode } from 'react';
import { cn } from '@/lib/cn';

/**
 * Interruptor (rediseño v2 §7): prende o apaga algo que surte efecto al instante o al guardar («Prender el agente»,
 * «Clics que valen»). Es un `button` con `role="switch"` y `aria-checked`, se maneja con el teclado (Espacio y Enter)
 * y nunca dice el estado solo con el color: la bolita se mueve y, con `label`, el texto acompaña.
 *
 * - `label` / `description`: texto a la derecha (el nombre accesible es `label`; sin él, pasa `aria-label`).
 * - `size="sm"` para filas densas (tarjetas, tablas).
 */
export function Switch({
    checked,
    onCheckedChange,
    label,
    description,
    disabled,
    size = 'md',
    className,
    id,
    'aria-label': ariaLabel,
}: {
    checked: boolean;
    onCheckedChange: (checked: boolean) => void;
    label?: ReactNode;
    description?: ReactNode;
    disabled?: boolean;
    size?: 'sm' | 'md';
    className?: string;
    id?: string;
    'aria-label'?: string;
}) {
    const auto = useId();
    const buttonId = id ?? `switch-${auto}`;
    const labelId = `${buttonId}-label`;
    const descriptionId = `${buttonId}-description`;
    const control = (
        <button
            id={buttonId}
            type="button"
            role="switch"
            aria-checked={checked}
            aria-label={label ? undefined : ariaLabel}
            aria-labelledby={label ? labelId : undefined}
            aria-describedby={description ? descriptionId : undefined}
            disabled={disabled}
            onClick={() => onCheckedChange(!checked)}
            className={cn(
                'igr:relative igr:inline-flex igr:shrink-0 igr:cursor-pointer igr:items-center igr:rounded-full igr:border igr:border-transparent igr:transition-colors igr:duration-150 igr:outline-none igr:focus-visible:ring-2 igr:focus-visible:ring-ring igr:focus-visible:ring-offset-2 igr:focus-visible:ring-offset-background igr:disabled:cursor-not-allowed igr:disabled:opacity-50',
                size === 'sm' ? 'igr:h-5 igr:w-9' : 'igr:h-6 igr:w-11',
                checked ? 'igr:bg-primary' : 'igr:bg-input',
            )}
        >
            <span
                aria-hidden
                className={cn(
                    'igr:pointer-events-none igr:block igr:rounded-full igr:bg-white igr:shadow-sm igr:ring-0 igr:transition-transform igr:duration-150',
                    size === 'sm' ? 'igr:size-4' : 'igr:size-5',
                    checked ? (size === 'sm' ? 'igr:translate-x-4' : 'igr:translate-x-5') : 'igr:translate-x-0.5',
                )}
            />
        </button>
    );
    if (!label) {
        return <span className={className}>{control}</span>;
    }
    return (
        <div className={cn('igr:flex igr:items-start igr:gap-3', className)}>
            {control}
            <div className="igr:min-w-0">
                <label id={labelId} htmlFor={buttonId} className={cn('igr:cursor-pointer igr:font-medium igr:text-foreground', size === 'sm' ? 'igr:text-support' : 'igr:text-body')}>
                    {label}
                </label>
                {description ? (
                    <p id={descriptionId} className="igr:mt-0.5 igr:text-support igr:text-muted-foreground">
                        {description}
                    </p>
                ) : null}
            </div>
        </div>
    );
}
