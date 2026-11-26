import { cn } from '@/lib/cn';

/** Barra de progreso (0 a 1). `tone` cambia el color de la parte llena; con movimiento reducido no se anima. */
export function Progress({ value, className, label, tone = 'brand' }: { value: number | null; className?: string; label?: string; tone?: 'brand' | 'success' | 'warning' }) {
    const pct = Math.max(0, Math.min(100, Math.round((value ?? 0) * 100)));
    return (
        <div
            role="progressbar"
            aria-valuemin={0}
            aria-valuemax={100}
            aria-valuenow={pct}
            aria-label={label}
            className={cn('igr:h-1.5 igr:w-full igr:overflow-hidden igr:rounded-full igr:bg-muted', className)}
        >
            <div
                className={cn(
                    'igr:h-full igr:rounded-full igr:transition-[width] igr:duration-500',
                    tone === 'brand' && 'igr:bg-primary',
                    tone === 'success' && 'igr:bg-success',
                    tone === 'warning' && 'igr:bg-warning',
                )}
                style={{ width: `${pct}%` }}
            />
        </div>
    );
}
