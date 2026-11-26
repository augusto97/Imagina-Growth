import type { LucideIcon } from 'lucide-react';
import type { ButtonHTMLAttributes, ReactNode } from 'react';
import { cn } from '@/lib/cn';

/**
 * Chip de filtro (rediseño v2 §7): un botón que se prende y se apaga (`aria-pressed`), con ícono y conteo opcionales.
 * Para filtros rápidos a la vista (tipos de actividad, estados de una cita, motivos de «Hoy»). Va dentro de un
 * `ChipGroup` con su nombre accesible; con `scroll`, el grupo se desplaza de lado en el celular en lugar de romper línea.
 */
export function Chip({
    pressed,
    icon: Icon,
    count,
    children,
    className,
    ...props
}: Omit<ButtonHTMLAttributes<HTMLButtonElement>, 'aria-pressed'> & { pressed: boolean; icon?: LucideIcon; count?: ReactNode }) {
    return (
        <button
            type="button"
            aria-pressed={pressed}
            className={cn(
                'igr:inline-flex igr:h-8 igr:shrink-0 igr:items-center igr:gap-1.5 igr:rounded-full igr:border igr:px-3 igr:text-[13px] igr:font-medium igr:whitespace-nowrap igr:transition-colors igr:focus-visible:outline-none igr:focus-visible:ring-3 igr:focus-visible:ring-ring/30 igr:disabled:pointer-events-none igr:disabled:opacity-50',
                pressed
                    ? 'igr:border-primary/40 igr:bg-brand-soft igr:text-brand-soft-foreground'
                    : 'igr:bg-card igr:text-muted-foreground igr:hover:bg-accent igr:hover:text-foreground',
                className,
            )}
            {...props}
        >
            {Icon ? <Icon className="igr:size-3.5 igr:shrink-0" aria-hidden /> : null}
            {children}
            {count !== undefined && count !== null ? (
                <span
                    className={cn(
                        'igr:rounded-full igr:px-1.5 igr:text-[11px] igr:leading-4 igr:tabular-nums',
                        pressed ? 'igr:bg-primary/15 igr:text-brand-soft-foreground' : 'igr:bg-muted igr:text-muted-foreground',
                    )}
                >
                    {count}
                </span>
            ) : null}
        </button>
    );
}

export function ChipGroup({ label, scroll = false, className, children }: { label: string; scroll?: boolean; className?: string; children: ReactNode }) {
    return (
        <div
            role="group"
            aria-label={label}
            className={cn('igr:flex igr:max-w-full igr:gap-1.5', scroll ? 'igr:overflow-x-auto igr:pb-0.5 igr:scrollbar-none' : 'igr:flex-wrap', className)}
        >
            {children}
        </div>
    );
}
