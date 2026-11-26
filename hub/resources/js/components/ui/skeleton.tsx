import type { HTMLAttributes } from 'react';
import { cn } from '@/lib/cn';

/**
 * Esqueletos en lugar de spinners (rediseño v2 §7): del tamaño de lo que viene, para que nada salte al cargar. El
 * brillo se detiene con `prefers-reduced-motion`.
 */
export function Skeleton({ className, ...props }: HTMLAttributes<HTMLDivElement>) {
    return <div aria-hidden className={cn('igr-skeleton igr:rounded-md', className)} {...props} />;
}

/** Líneas de texto (la última más corta). */
export function SkeletonText({ lines = 3, className }: { lines?: number; className?: string }) {
    return (
        <div aria-hidden className={cn('igr:space-y-2', className)}>
            {Array.from({ length: lines }, (_, i) => (
                <Skeleton key={i} className={cn('igr:h-3.5', i === lines - 1 && lines > 1 ? 'igr:w-2/3' : 'igr:w-full')} />
            ))}
        </div>
    );
}

/** Una tarjeta mientras carga: título y unas líneas, con el mismo borde y radio que `Card`. */
export function SkeletonCard({ className, lines = 2 }: { className?: string; lines?: number }) {
    return (
        <div aria-hidden className={cn('igr:rounded-xl igr:border igr:bg-card igr:p-5', className)}>
            <div className="igr:flex igr:items-center igr:gap-3">
                <Skeleton className="igr:size-9 igr:rounded-lg" />
                <Skeleton className="igr:h-4 igr:w-32" />
            </div>
            <SkeletonText lines={lines} className="igr:mt-4" />
        </div>
    );
}
