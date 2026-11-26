import type { LucideIcon } from 'lucide-react';
import { useRef, type KeyboardEvent } from 'react';
import { cn } from '@/lib/cn';

export interface SegmentedOption<T extends string> {
    value: T;
    label: string;
    icon?: LucideIcon;
}

/** La opción a la que lleva una flecha (con vuelta al inicio y al final), o la misma si la tecla no es una flecha. */
export function nextSegment<T extends string>(options: readonly SegmentedOption<T>[], current: T, key: string): T {
    const index = options.findIndex((o) => o.value === current);
    const step = key === 'ArrowRight' || key === 'ArrowDown' ? 1 : key === 'ArrowLeft' || key === 'ArrowUp' ? -1 : 0;
    if (step === 0 || options.length === 0) {
        return current;
    }
    const next = options[(index + step + options.length) % options.length];
    return next ? next.value : current;
}

/**
 * Control segmentado (rediseño v2 §7): elige una sola opción entre 2 a 4 a la vista, como «Escritorio / Celular» de una
 * vista previa o el periodo de unas métricas. Es un grupo de radio: Tab entra a la opción elegida y las flechas cambian
 * de opción. `iconOnly` muestra solo el ícono (el nombre queda para el lector de pantalla y el tooltip nativo).
 */
export function SegmentedControl<T extends string>({
    value,
    onChange,
    options,
    label,
    size = 'md',
    iconOnly = false,
    className,
}: {
    value: T;
    onChange: (value: T) => void;
    options: readonly SegmentedOption<T>[];
    /** Nombre del grupo para el lector de pantalla («Vista previa en»). */
    label: string;
    size?: 'sm' | 'md';
    iconOnly?: boolean;
    className?: string;
}) {
    const refs = useRef<Record<string, HTMLButtonElement | null>>({});
    const onKey = (e: KeyboardEvent<HTMLButtonElement>) => {
        const next = nextSegment(options, value, e.key);
        if (next !== value) {
            e.preventDefault();
            onChange(next);
            refs.current[next]?.focus();
        }
    };
    return (
        <div
            role="radiogroup"
            aria-label={label}
            className={cn('igr:inline-flex igr:max-w-full igr:items-center igr:gap-0.5 igr:overflow-x-auto igr:rounded-lg igr:bg-muted igr:p-0.5 igr:scrollbar-none', className)}
        >
            {options.map((option) => {
                const active = option.value === value;
                const Icon = option.icon;
                return (
                    <button
                        key={option.value}
                        ref={(el) => {
                            refs.current[option.value] = el;
                        }}
                        type="button"
                        role="radio"
                        aria-checked={active}
                        tabIndex={active ? 0 : -1}
                        title={iconOnly ? option.label : undefined}
                        onClick={() => onChange(option.value)}
                        onKeyDown={onKey}
                        className={cn(
                            'igr:inline-flex igr:shrink-0 igr:items-center igr:justify-center igr:gap-1.5 igr:rounded-md igr:font-medium igr:whitespace-nowrap igr:transition-[color,background-color,box-shadow] igr:focus-visible:outline-none igr:focus-visible:ring-2 igr:focus-visible:ring-ring',
                            size === 'sm' ? 'igr:h-7 igr:px-2.5 igr:text-xs' : 'igr:h-8 igr:px-3 igr:text-[13px]',
                            iconOnly && (size === 'sm' ? 'igr:w-7 igr:px-0' : 'igr:w-8 igr:px-0'),
                            active ? 'igr:bg-card igr:text-foreground igr:shadow-xs' : 'igr:text-muted-foreground igr:hover:text-foreground',
                        )}
                    >
                        {Icon ? <Icon className="igr:size-4 igr:shrink-0" aria-hidden /> : null}
                        <span className={iconOnly ? 'igr:sr-only' : undefined}>{option.label}</span>
                    </button>
                );
            })}
        </div>
    );
}
