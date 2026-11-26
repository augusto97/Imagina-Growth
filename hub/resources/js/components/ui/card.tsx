import type { HTMLAttributes } from 'react';
import { cn } from '@/lib/cn';

/**
 * Tarjeta del sistema v2: `rounded-xl`, borde sutil y sombra mínima sobre el fondo pizarra de la página. Para una
 * tarjeta que se puede abrir (un enlace), agrega `igr:transition-shadow igr:hover:shadow-md` y el foco visible.
 */
export function Card({ className, ...props }: HTMLAttributes<HTMLDivElement>) {
    return <div className={cn('igr:rounded-xl igr:border igr:bg-card igr:text-card-foreground igr:shadow-xs', className)} {...props} />;
}

export function CardHeader({ className, ...props }: HTMLAttributes<HTMLDivElement>) {
    return <div className={cn('igr:flex igr:flex-col igr:gap-1 igr:p-5 igr:pb-2', className)} {...props} />;
}

export function CardTitle({ className, ...props }: HTMLAttributes<HTMLHeadingElement>) {
    return <h3 className={cn('igr:text-[15px] igr:font-semibold igr:leading-snug igr:tracking-tight', className)} {...props} />;
}

export function CardDescription({ className, ...props }: HTMLAttributes<HTMLParagraphElement>) {
    return <p className={cn('igr:text-support igr:text-muted-foreground', className)} {...props} />;
}

export function CardContent({ className, ...props }: HTMLAttributes<HTMLDivElement>) {
    return <div className={cn('igr:p-5 igr:pt-2', className)} {...props} />;
}

export function CardFooter({ className, ...props }: HTMLAttributes<HTMLDivElement>) {
    return <div className={cn('igr:flex igr:flex-wrap igr:items-center igr:gap-2 igr:border-t igr:px-5 igr:py-3', className)} {...props} />;
}
