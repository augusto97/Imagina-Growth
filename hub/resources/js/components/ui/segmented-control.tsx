import type { LucideIcon } from 'lucide-react';
import { useRef, type KeyboardEvent } from 'react';
import { cn } from '@/lib/cn';

export interface SegmentOption<T extends string> {
    value: T;
    label: string;
    icon?: LucideIcon;
}

/** La opción a la que lleva una flecha desde `index` (da la vuelta en los extremos), o null si la tecla no mueve. */
export function segmentStep(key: string, index: number, count: number): number | null {
    const delta = key === 'ArrowRight' || key === 'ArrowDown' ? 1 : key === 'ArrowLeft' || key === 'ArrowUp' ? -1 : 0;
    if (delta === 0 || count === 0) {
        return null;
    }
    return (index + delta + count) % count;
}

/**
 * Control segmentado (rediseño v2 §7): elegir una vista entre pocas opciones (Día / Semana, Tablero / Lista). Es un
 * `radiogroup`: las flechas mueven la elección y el foco; Tab sale del grupo. Con `iconOnly` se ve solo el ícono (el
 * nombre queda para el lector de pantalla y en el `title`).
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
    options: readonly SegmentOption<T>[];
    label: string;
    size?: 'sm' | 'md';
    iconOnly?: boolean;
    className?: string;
}) {
    const refs = useRef<(HTMLButtonElement | null)[]>([]);
    const onKey = (e: KeyboardEvent<HTMLButtonElement>, index: number) => {
        const next = segmentStep(e.key, index, options.length);
        const option = next === null ? undefined : options[next];
        if (next === null || !option) {
            return;
        }
        e.preventDefault();
        onChange(option.value);
        refs.current[next]?.focus();
    };
    return (
        <div role="radiogroup" aria-label={label} className={cn('igr:inline-flex igr:shrink-0 igr:items-center igr:gap-0.5 igr:rounded-lg igr:border igr:bg-muted/60 igr:p-0.5', className)}>
            {options.map((option, i) => {
                const active = option.value === value;
                const Icon = option.icon;
                return (
                    <button
                        key={option.value}
                        ref={(el) => {
                            refs.current[i] = el;
                        }}
                        type="button"
                        role="radio"
                        aria-checked={active}
                        aria-label={iconOnly ? option.label : undefined}
                        title={iconOnly ? option.label : undefined}
                        tabIndex={active ? 0 : -1}
                        onClick={() => onChange(option.value)}
                        onKeyDown={(e) => onKey(e, i)}
                        className={cn(
                            'igr:inline-flex igr:items-center igr:justify-center igr:gap-1.5 igr:rounded-md igr:font-medium igr:whitespace-nowrap igr:transition-colors igr:focus-visible:outline-none igr:focus-visible:ring-3 igr:focus-visible:ring-ring/30 igr:[&_svg]:size-4',
                            size === 'sm' ? 'igr:h-7 igr:px-2.5 igr:text-xs' : 'igr:h-8 igr:px-3 igr:text-[13px]',
                            active ? 'igr:bg-card igr:text-foreground igr:shadow-xs' : 'igr:text-muted-foreground igr:hover:text-foreground',
                        )}
                    >
                        {Icon ? <Icon aria-hidden /> : null}
                        {iconOnly ? null : option.label}
                    </button>
                );
            })}
        </div>
    );
}
